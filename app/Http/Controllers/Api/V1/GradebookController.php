<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\Observation;
use App\Models\User;
use App\Models\WordwallGrade;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradebookController extends Controller
{
    public function data(Request $r): array
    {
        $r->validate(['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $r->user()->accessibleClassrooms()->pluck('id'))], 'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('classroom_id', $r->integer('classroom_id'))]]);
        $class = $r->integer('classroom_id');
        $course = $r->integer('course_id');
        $staff = $r->user()->role !== Role::Student;
        $students = User::where('role', Role::Student)->whereIn('id', DB::table('classroom_user')->where('classroom_id', $class)->select('user_id'))->when(! $staff, fn ($q) => $q->whereKey($r->user()->id))->orderBy('name')->get();
        $tasks = Assignment::query()->when(! $staff, fn ($q) => $q->visibleTo($r->user()))->where('classroom_id', $class)->whereIn('status', ['published', 'closed'])->when($course, fn ($q) => $q->whereHas('lesson', fn ($q) => $q->where('course_id', $course)))->get();
        $assessments = Assessment::query()->when(! $staff, fn ($q) => $q->visibleTo($r->user()))->where('classroom_id', $class)->where('purpose', '!=', 'research')->whereIn('status', ['published', 'closed'])->when($course, fn ($q) => $q->whereHas('lesson', fn ($q) => $q->where('course_id', $course)))->get();
        $submissions = AssignmentSubmission::whereIn('assignment_id', $tasks->pluck('id'))->get();
        $attempts = AssessmentAttempt::whereIn('assessment_id', $assessments->pluck('id'))->whereNotNull('submitted_at')->orderByDesc('id')->get();
        $groups = LearningGroup::where('classroom_id', $class)->with('users:id')->get();
        $works = GroupWork::whereIn('learning_group_id', $groups->pluck('id'))->get();
        $observations = Observation::where('classroom_id', $class)->get();
        $wordwallGrades = WordwallGrade::where('classroom_id', $class)->with('teacher:id,name')->get();
        $games = collect(json_decode(file_get_contents(database_path('data/lkpd-games.json')), true, flags: JSON_THROW_ON_ERROR));
        $rows = $students->map(function ($student) use ($tasks, $assessments, $submissions, $attempts, $groups, $works, $observations, $wordwallGrades, $games, $course) {
            $taskRows = $tasks->map(function ($task) use ($student, $submissions) {
                $s = $submissions->first(fn ($s) => $s->assignment_id === $task->id && in_array($student->id, $s->member_ids, true));

                return ['id' => $task->id, 'title' => $task->title, 'kind' => $task->kind, 'status' => $s?->status ?? 'missing', 'score' => $s?->score, 'max_score' => $s?->assignment_snapshot['max_score'] ?? $task->max_score, 'normalized_score' => $s?->score !== null && $s ? round($s->score / $s->assignment_snapshot['max_score'] * 100, 2) : null, 'source' => $s?->source, 'feedback' => $s?->feedback, 'is_late' => $s?->is_late ?? false, 'submission_id' => $s?->id];
            });
            $testRows = $assessments->map(function ($a) use ($student, $attempts) {
                $s = $attempts->first(fn ($s) => $s->assessment_id === $a->id && $s->student_id === $student->id);

                return ['id' => $a->id, 'title' => $a->title, 'status' => $s?->status ?? 'missing', 'raw_score' => $s?->raw_score, 'max_score' => $s?->max_score ?? $a->max_score, 'normalized_score' => $s?->raw_score !== null && $s ? round($s->raw_score / $s->max_score * 100, 2) : null, 'source' => $s?->source, 'feedback' => $s?->feedback, 'attempt_id' => $s?->id];
            });
            $studentGroups = $groups->filter(fn ($g) => $g->users->contains('id', $student->id))->pluck('id');
            $wordwall = $course || ! $student->canUseSvil() ? collect() : $games->map(function ($game) use ($student, $wordwallGrades) {
                $grade = $wordwallGrades->first(fn ($grade) => $grade->student_id === $student->id && $grade->game_id === $game['id']);

                return ['id' => $game['id'], 'title' => $grade?->game_title ?? $game['title'], 'topic' => $game['topic'], 'status' => $grade ? 'graded' : 'missing', 'raw_score' => $grade?->score, 'max_score' => 100, 'normalized_score' => $grade?->score, 'source' => $grade ? 'wordwall_manual' : null, 'feedback' => $grade?->feedback, 'teacher' => $grade?->teacher->name, 'updated_at' => $grade?->updated_at];
            });

            return ['student_id' => $student->id, 'participant_code' => 'P-'.$student->id, 'name' => $student->name, 'assignments' => $taskRows->values(), 'assessments' => $testRows->values(), 'wordwall' => $wordwall->values(), 'group_work' => $works->filter(fn ($w) => $w->member_ids ? in_array($student->id, $w->member_ids, true) : $studentGroups->contains($w->learning_group_id))->map(fn ($w) => $w->summary())->values(), 'observations' => $observations->where('student_id', $student->id)->values(), 'progress' => ['tasks_assigned' => $taskRows->count(), 'tasks_submitted' => $taskRows->whereNotIn('status', ['missing', 'draft'])->count(), 'tasks_reviewed' => $taskRows->where('status', 'reviewed')->count(), 'assessments_assigned' => $testRows->count(), 'assessments_submitted' => $testRows->where('status', '!=', 'missing')->count(), 'assessments_graded' => $testRows->where('status', 'graded')->count()]];
        });

        return ['classroom_id' => $class, 'course_id' => $course ?: null, 'policy' => 'Tugas dan asesmen dilaporkan terpisah. Asesmen memakai percobaan terakhir yang sudah dikirim. Progres menunjukkan bukti pengumpulan/tinjauan, bukan kesimpulan efektivitas atau karakter.', 'students' => $rows->values()];
    }

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->data($r)]);
    }

    public function csv(Request $r): StreamedResponse
    {
        $data = $this->data($r);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['peserta', 'kelas_id', 'jenis', 'kegiatan_id', 'judul', 'status', 'skor_mentah', 'maksimum', 'nilai_100', 'sumber'], escape: '');
            foreach ($data['students'] as $student) {
                foreach (['assignments' => 'TUGAS', 'assessments' => 'ASESMEN', 'wordwall' => 'KUIS_WORDWALL'] as $key => $kind) {
                    foreach ($student[$key] as $row) {
                        $title = preg_match('/^\s*[=+\-@]|^[\t\r]/u', $row['title']) ? "'".$row['title'] : $row['title'];
                        fputcsv($out, [$student['participant_code'], $data['classroom_id'], $kind, $row['id'], $title, $row['status'], $row['score'] ?? $row['raw_score'] ?? null, $row['max_score'], $row['normalized_score'], $row['source']], escape: '');
                    }
                }
            }fclose($out);
        }, 'svil-buku-nilai.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
