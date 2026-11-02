<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCourseRequest;
use App\Learning\LearningNotifier;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        return response()->json(Course::visibleTo($r->user())->with('classroom:id,name,academic_year')->when($r->integer('classroom_id'), fn ($q) => $q->where('classroom_id', $r->integer('classroom_id')))->latest()->paginate(30));
    }

    public function show(Request $r, Course $course): JsonResponse
    {
        abort_unless($course->canView($r->user()), 403);
        $course->load(['chapters.lessons' => fn ($q) => $q->when($r->user()->role === Role::Student, fn ($q) => $q->where('status', '!=', 'draft'))]);

        return response()->json(['data' => $course]);
    }

    public function store(SaveCourseRequest $r): JsonResponse
    {
        return response()->json(['data' => Course::create([...$r->safe()->except('version'), 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id])], 201);
    }

    public function update(SaveCourseRequest $r, Course $course): JsonResponse
    {
        return response()->json(['data' => DB::transaction(function () use ($r, $course) {
            $target = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $r->integer('version'), 409, 'Mapel sudah berubah. Muat ulang.');
            abort_if($target->classroom_id !== $r->integer('classroom_id'), 422, 'Kelas mapel tidak dapat dipindahkan. Gunakan salin struktur.');
            $target->update([...$r->safe()->except('version'), 'version' => $target->version + 1, 'updated_by' => $r->user()->id]);

            return $target;
        })]);
    }

    public function chapter(Request $r, Course $course): JsonResponse
    {
        abort_unless($course->canManage($r->user()), 403);
        $data = $r->validate(['title' => ['required', 'string', 'max:180'], 'position' => ['required', 'integer', 'between:1,1000']]);

        return response()->json(['data' => $course->chapters()->create($data)], 201);
    }

    public function updateChapter(Request $r, CourseChapter $chapter): JsonResponse
    {
        abort_unless($chapter->course->canManage($r->user()), 403);
        $data = $r->validate(['title' => ['required', 'string', 'max:180'], 'position' => ['required', 'integer', 'between:1,1000'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => DB::transaction(function () use ($data, $chapter) {
            $target = CourseChapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $data['version'], 409, 'Bab berubah. Muat ulang.');
            $target->update([...$data, 'version' => $target->version + 1]);

            return $target;
        })]);
    }

    private function lessonData(Request $r, bool $updating = false): array
    {
        return $r->validate(['completion_rule' => ['sometimes', 'in:manual,materials,activities,all'], 'minimum_score' => ['nullable', 'numeric', 'between:0,100'], 'title' => ['required', 'string', 'max:180'], 'objective' => ['required', 'string', 'max:3000'], 'position' => ['required', 'integer', 'between:1,1000'], 'mode' => ['required', 'in:online,offline,blended'], 'status' => ['required', 'in:draft,open,closed'], 'scheduled_on' => ['nullable', 'date_format:Y-m-d'], 'version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1']]);
    }

    public function lesson(Request $r, CourseChapter $chapter): JsonResponse
    {
        abort_unless($chapter->course->canManage($r->user()), 403);

        return response()->json(['data' => CourseLesson::create([...$this->lessonData($r), 'course_chapter_id' => $chapter->id, 'course_id' => $chapter->course_id])], 201);
    }

    public function updateLesson(Request $r, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->course->canManage($r->user()), 403);
        $data = $this->lessonData($r, true);

        return response()->json(['data' => DB::transaction(function () use ($lesson, $data) {
            $target = CourseLesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $data['version'], 409, 'Pertemuan berubah. Muat ulang.');
            $target->update([...$data, 'version' => $target->version + 1]);

            return $target;
        })]);
    }

    public function copy(Request $r, Course $course): JsonResponse
    {
        abort_unless($course->canManage($r->user()), 403);
        $data = $r->validate(['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $r->user()->accessibleClassrooms()->pluck('id'))], 'title' => ['required', 'string', 'max:180'], 'semester' => ['required', 'in:ganjil,genap']]);
        $new = DB::transaction(function () use ($r, $course, $data) {
            $copy = Course::create([...$course->only(['subject', 'method', 'description']), ...$data, 'status' => 'draft', 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id]);
            foreach ($course->chapters()->with('lessons')->get() as $chapter) {
                $newChapter = $copy->chapters()->create($chapter->only(['title', 'position']));
                foreach ($chapter->lessons as $lesson) {
                    CourseLesson::create([...$lesson->only(['title', 'objective', 'position', 'mode']), 'course_id' => $copy->id, 'course_chapter_id' => $newChapter->id, 'status' => 'draft']);
                }
            }

            return $copy;
        });

        return response()->json(['data' => $new, 'message' => 'Struktur mapel, bab dan pertemuan disalin sebagai draf. Hasil siswa tidak disalin.'], 201);
    }

    public function lessonRecords(Request $r, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->canView($r->user()), 403);
        $staff = $r->user()->role !== Role::Student;

        return response()->json(['data' => ['attendance' => DB::table('lesson_attendances')->where('course_lesson_id', $lesson->id)->when(! $staff, fn ($q) => $q->where('student_id', $r->user()->id))->get(), 'phases' => $staff ? DB::table('lesson_phase_records')->where('course_lesson_id', $lesson->id)->get() : []]]);
    }

    public function record(Request $r, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->course->canManage($r->user()), 403);
        $data = $r->validate(['kind' => ['required', 'in:attendance,phase'], 'version' => ['required', 'integer', 'min:0'], 'student_id' => ['required_if:kind,attendance', 'nullable', 'integer', Rule::exists('classroom_user', 'user_id')->where('classroom_id', $lesson->course->classroom_id), Rule::exists('users', 'id')->where('role', 'student')], 'phase' => ['required_if:kind,phase', 'nullable', 'integer', 'between:0,4'], 'status' => ['required', Rule::in($r->input('kind') === 'phase' ? ['planned', 'done'] : ['present', 'absent', 'excused'])], 'evidence' => ['required', 'string', 'max:3000']]);
        $row = DB::transaction(function () use ($r, $lesson, $data) {
            CourseLesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $table = $data['kind'] === 'phase' ? 'lesson_phase_records' : 'lesson_attendances';
            $keys = ['course_lesson_id' => $lesson->id, $data['kind'] === 'phase' ? 'phase' : 'student_id' => $data[$data['kind'] === 'phase' ? 'phase' : 'student_id']];
            $previous = DB::table($table)->where($keys)->first();
            abort_if(($previous?->version ?? 0) !== $data['version'], 409, 'Catatan pertemuan sudah berubah.');
            DB::table($table)->updateOrInsert($keys, ['status' => $data['status'], 'evidence' => $data['evidence'], 'recorded_by' => $r->user()->id, 'version' => ($previous?->version ?? 0) + 1, 'created_at' => $previous?->created_at ?? now(), 'updated_at' => now()]);

            return DB::table($table)->where($keys)->first();
        });

        return response()->json(['data' => $row]);
    }

    public function announcements(Request $r): JsonResponse
    {
        return response()->json(DB::table('school_announcements')->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id'))->when($r->integer('classroom_id'), fn ($q) => $q->where('classroom_id', $r->integer('classroom_id')))->latest('id')->paginate(30));
    }

    public function announce(Request $r): JsonResponse
    {
        abort_if($r->user()->role === Role::Student, 403);
        $data = $r->validate(['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $r->user()->accessibleClassrooms()->pluck('id'))], 'title' => ['required', 'string', 'max:180'], 'content' => ['required', 'string', 'max:5000']]);
        $id = DB::table('school_announcements')->insertGetId([...$data, 'author_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        LearningNotifier::send(LearningNotifier::members($data['classroom_id']), 'announcement', $data['title'], 'Ada pengumuman baru dari gurumu.', '/siswa', 'announcement:'.$id, $data['classroom_id']);

        return response()->json(['data' => ['id' => $id]], 201);
    }
}
