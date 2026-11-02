<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\GroupWork;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LearningProgressController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())]]);
        $rows = DB::table('learning_progress')->where('classroom_id', $data['classroom_id'])->when($r->user()->role === Role::Student, fn ($q) => $q->where('student_id', $r->user()->id))->orderByDesc('updated_at')->paginate(50);

        return response()->json($rows);
    }

    public function material(Request $r, Material $material): JsonResponse
    {
        abort_unless($r->user()->can('view', $material), 403);
        $row = DB::table('learning_progress')->where(['student_id' => $r->user()->id, 'target_type' => 'material', 'target_key' => $material->id])->first();

        return response()->json(['data' => $row, 'content_version' => $material->version, 'is_current' => $row?->content_version === $material->version]);
    }

    public function saveMaterial(Request $r, Material $material): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $r->user()->can('view', $material), 403);
        $data = $r->validate(['status' => ['required', 'in:started,completed'], 'version' => ['required', 'integer', 'min:0'], 'content_version' => ['required', 'integer']]);
        abort_if($data['content_version'] !== $material->version, 409, 'Materi sudah diperbarui. Baca versi terbaru.');

        return $this->save($r, $material->classroom_id, 'material', $material->id, $material->version, $data, []);
    }

    public function lesson(Request $r, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->canView($r->user()), 403);
        $student = $r->user();
        if ($r->user()->role !== Role::Student) {
            $r->validate(['student_id' => ['required', 'integer']]);
            $student = User::findOrFail($r->integer('student_id'));
            abort_unless($student->role === Role::Student && $student->canAccessClassroom($lesson->course->classroom_id), 403);
        }
        $criteria = $this->criteria($student, $lesson);
        $row = DB::table('learning_progress')->where(['student_id' => $student->id, 'target_type' => 'lesson', 'target_key' => (string) $lesson->id])->first();

        return response()->json(['data' => $row, 'criteria' => $criteria, 'content_version' => $lesson->version, 'is_current' => $row?->content_version === $lesson->version && ($lesson->completion_rule === 'manual' || $criteria['eligible'])]);
    }

    public function saveLesson(Request $r, CourseLesson $lesson): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $lesson->canView($r->user()), 403);
        $data = $r->validate(['status' => ['required', 'in:started,completed'], 'version' => ['required', 'integer', 'min:0'], 'content_version' => ['required', 'integer']]);
        abort_if($data['content_version'] !== $lesson->version, 409, 'Pertemuan sudah diperbarui. Muat ulang.');
        $criteria = $this->criteria($r->user(), $lesson);
        abort_if($data['status'] === 'completed' && ! $criteria['eligible'], 422, 'Syarat penyelesaian belum terpenuhi. Periksa materi, tugas atau kuis pada pertemuan ini.');

        return $this->save($r, $lesson->course->classroom_id, 'lesson', (string) $lesson->id, $lesson->version, $data, $criteria);
    }

    public function criteria(User $student, CourseLesson $lesson): array
    {
        $materials = Material::visibleTo($student)->where('course_lesson_id', $lesson->id)->where('kind', 'material')->get(['id', 'version']);
        $progress = DB::table('learning_progress')->where('student_id', $student->id)->where('target_type', 'material')->where('status', 'completed')->get()->keyBy('target_key');
        $read = $materials->filter(fn ($m) => isset($progress[$m->id]) && $progress[$m->id]->content_version === $m->version)->count();
        $tasks = Assignment::visibleTo($student)->where('course_lesson_id', $lesson->id)->get(['id']);
        $submissions = AssignmentSubmission::whereIn('assignment_id', $tasks->pluck('id'))->whereJsonContains('member_ids', $student->id)->orderByDesc('id')->get();
        $taskDone = $tasks->filter(function ($task) use ($submissions, $lesson) {
            $submission = $submissions->firstWhere('assignment_id', $task->id);

            return $submission && in_array($submission->status, ['submitted', 'reviewed'], true) && ($lesson->minimum_score === null || ($submission->score !== null && $submission->score / $submission->assignment_snapshot['max_score'] * 100 >= $lesson->minimum_score));
        })->count();
        $tests = Assessment::where('course_lesson_id', $lesson->id)->where('purpose', '!=', 'research')->whereIn('status', ['published', 'closed'])->get(['id']);
        $attempts = AssessmentAttempt::whereIn('assessment_id', $tests->pluck('id'))->where('student_id', $student->id)->whereNotNull('submitted_at')->orderByDesc('id')->get();
        $testDone = $tests->filter(function ($a) use ($attempts, $lesson) {
            $last = $attempts->firstWhere('assessment_id', $a->id);

            return $last && ($lesson->minimum_score === null || ($last->raw_score !== null && $last->raw_score / $last->max_score * 100 >= $lesson->minimum_score));
        })->count();
        $legacy = Material::visibleTo($student)->where('course_lesson_id', $lesson->id)->where('kind', 'quiz')->get(['id']);
        $practice = MaterialAttempt::whereIn('material_id', $legacy->pluck('id'))->where('student_id', $student->id)->orderByDesc('id')->get();
        $legacyDone = $legacy->filter(function ($item) use ($practice, $lesson) {
            $attempt = $practice->firstWhere('material_id', $item->id);

            return $attempt && ($lesson->minimum_score === null || ($attempt->correct !== null && ($attempt->correct ? 100 : 0) >= $lesson->minimum_score));
        })->count();
        $activityTotal = $tasks->count() + $tests->count() + $legacy->count();
        $activityDone = $taskDone + $testDone + $legacyDone;
        $total = $materials->count() + $activityTotal;
        $completed = $read + $activityDone;
        $eligible = match ($lesson->completion_rule) {
            'materials' => $materials->count() > 0 && $read === $materials->count(),'activities' => $activityTotal > 0 && $activityDone === $activityTotal,'all' => $total > 0 && $completed === $total,default => true
        };

        return ['rule' => $lesson->completion_rule, 'minimum_score' => $lesson->minimum_score, 'materials_total' => $materials->count(), 'materials_done' => $read, 'tasks_total' => $tasks->count(), 'tasks_done' => $taskDone, 'assessments_total' => $tests->count() + $legacy->count(), 'assessments_done' => $testDone + $legacyDone, 'eligible' => $eligible];
    }

    public function overview(Request $r): JsonResponse
    {
        $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())], 'student_id' => ['nullable', 'integer']]);
        $class = $r->integer('classroom_id');
        $student = $r->user();
        if ($student->role !== Role::Student) {
            $r->validate(['student_id' => ['required', 'integer']]);
            $student = User::findOrFail($r->integer('student_id'));
            abort_unless($student->role === Role::Student && $student->canAccessClassroom($class), 403);
        }
        $progress = DB::table('learning_progress')->where('classroom_id', $class)->where('student_id', $student->id)->get()->keyBy(fn ($p) => $p->target_type.':'.$p->target_key);
        $materials = Material::visibleTo($student)->where('classroom_id', $class)->where('kind', 'material')->get(['id', 'title', 'version']);
        $lessons = CourseLesson::whereIn('course_id', Course::visibleTo($student)->where('classroom_id', $class)->select('id'))->where('status', '!=', 'draft')->with('course')->get(['id', 'title', 'version', 'completion_rule', 'minimum_score', 'course_id']);
        $present = function ($items, $type) use ($progress, $student) {
            return $items->map(function ($item) use ($progress, $type, $student) {
                $row = $progress[$type.':'.$item->id] ?? null;

                $eligible = $type !== 'lesson' || ! $row || $row->status !== 'completed' || $item->completion_rule === 'manual' || $this->criteria($student, $item)['eligible'];

                return ['id' => $item->id, 'title' => $item->title, 'version' => $item->version, 'status' => $row ? ($row->content_version === $item->version && $eligible ? $row->status : 'updated') : 'not_started', 'completed_at' => $row?->completed_at];
            });
        };

        return response()->json(['data' => ['student' => ['id' => $student->id, 'name' => $student->name], 'materials' => $present($materials, 'material'), 'lessons' => $present($lessons, 'lesson')]]);
    }

    public function history(Request $r): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student, 403);
        $student = $r->user();
        $classes = DB::table('classroom_user as m')->join('classrooms as c', 'c.id', '=', 'm.classroom_id')->where('m.user_id', $student->id)->select('c.id', 'c.name', 'c.academic_year', 'c.archived_at', 'm.is_active')->get();
        $data = $classes->map(function ($class) use ($student) {
            return [...(array) $class, 'notes' => DB::table('learning_notes')->where('classroom_id', $class->id)->where('student_id', $student->id)->select('topic', 'phase', 'content', 'feedback', 'updated_at')->get(), 'group_work' => GroupWork::whereHas('group', fn ($q) => $q->where('classroom_id', $class->id))->whereJsonContains('member_ids', $student->id)->with('group:id,name')->get()->map(fn ($w) => [...$w->summary(), 'groupName' => $w->group->name]), 'assignments' => AssignmentSubmission::whereHas('assignment', fn ($q) => $q->where('classroom_id', $class->id))->whereJsonContains('member_ids', $student->id)->get()->map(fn ($s) => ['title' => $s->assignment_snapshot['title'], 'score' => $s->score, 'max_score' => $s->assignment_snapshot['max_score'], 'status' => $s->status, 'feedback' => $s->feedback, 'submitted_at' => $s->submitted_at]), 'assessments' => AssessmentAttempt::whereHas('assessment', fn ($q) => $q->where('classroom_id', $class->id))->where('student_id', $student->id)->get()->map(fn ($a) => ['title' => $a->assessment_snapshot['title'], 'score' => $a->raw_score, 'max_score' => $a->max_score, 'status' => $a->status, 'feedback' => $a->feedback])];
        });

        return response()->json(['data' => $data]);
    }

    private function save(Request $r, int $classroom, string $type, string $key, int $contentVersion, array $data, array $criteria): JsonResponse
    {
        $row = DB::transaction(function () use ($r, $classroom, $type, $key, $contentVersion, $data, $criteria) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $keys = ['student_id' => $r->user()->id, 'target_type' => $type, 'target_key' => $key];
            $old = DB::table('learning_progress')->where($keys)->first();
            if ($old && $old->status === $data['status'] && $old->content_version === $contentVersion) {
                return $old;
            }abort_if(($old?->version ?? 0) !== $data['version'], 409, 'Progres berubah di perangkat lain. Muat ulang.');
            DB::table('learning_progress')->updateOrInsert($keys, ['classroom_id' => $classroom, 'status' => $data['status'], 'content_version' => $contentVersion, 'criteria_snapshot' => json_encode($criteria, JSON_THROW_ON_ERROR), 'version' => ($old?->version ?? 0) + 1, 'first_opened_at' => $old?->first_opened_at ?? now(), 'last_opened_at' => now(), 'completed_at' => $data['status'] === 'completed' ? now() : null, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            $row = DB::table('learning_progress')->where($keys)->first();
            DB::table('learning_progress_events')->insert(['learning_progress_id' => $row->id, 'version' => $row->version, 'snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $row;
        });

        return response()->json(['data' => $row, 'message' => 'Progres belajarmu tersimpan.']);
    }
}
