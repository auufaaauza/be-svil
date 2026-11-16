<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassroomResource;
use App\Learning\LearningNotifier;
use App\Learning\YouTubeVideo;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SchoolOperationsController extends Controller
{
    private function protectResearch(array $students): void
    {
        abort_if(DB::table('research_enrollments as e')->join('research_studies as s', 's.id', '=', 'e.research_study_id')->whereIn('e.student_id', $students)->where('s.status', 'active')->exists(), 422, 'Peserta sedang mengikuti tahap penelitian aktif. Tutup tahap atau selaraskan penempatan dengan peneliti sebelum mengubah keanggotaan.');
    }

    public function classroom(Request $r, Classroom $classroom): ClassroomResource
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:80'], 'academic_year' => ['required', 'string', 'max:20'], 'archived' => ['required', 'boolean']]);
        $saved = DB::transaction(function () use ($r, $classroom, $data) {
            $c = Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
            abort_if($c->version !== $data['version'], 409, 'Kelas berubah. Muat ulang.');
            abort_if(Classroom::where('school_id', $c->school_id)->where('name', $data['name'])->where('academic_year', $data['academic_year'])->whereKeyNot($c->id)->exists(), 422, 'Nama kelas dan tahun ajaran sudah digunakan.');
            if ($data['archived'] && ! $c->archived_at) {
                $this->protectResearch($c->users()->where('role', Role::Student)->pluck('users.id')->all());
                Course::where('classroom_id', $c->id)->update(['status' => 'archived', 'updated_by' => $r->user()->id, 'version' => DB::raw('version+1'), 'updated_at' => now()]);
            }$c->update(['name' => $data['name'], 'academic_year' => $data['academic_year'], 'archived_at' => $data['archived'] ? ($c->archived_at ?? now()) : null, 'version' => $c->version + 1]);
            LearningNotifier::audit($r->user()->id, 'classroom_updated', 'classroom', $c->id, $data);

            return $c;
        });

        return new ClassroomResource($saved->load('school'));
    }

    public function rotateCode(Request $r, Classroom $classroom): ClassroomResource
    {
        $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $c = DB::transaction(function () use ($r, $classroom) {
            $c = Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
            abort_if($c->version !== $r->integer('version'), 409, 'Kelas berubah. Muat ulang.');
            $c->forceFill(['join_code' => Str::upper(Str::random(12)), 'version' => $c->version + 1])->save();
            LearningNotifier::audit($r->user()->id, 'class_code_rotated', 'classroom', $c->id);

            return $c;
        });

        return new ClassroomResource($c->load('school'));
    }

    public function membership(Request $r, Classroom $classroom, User $user): JsonResponse
    {
        $data = $r->validate(['operation' => ['required', 'in:activate,deactivate,transfer'], 'target_classroom_id' => ['required_if:operation,transfer', 'nullable', 'integer', 'exists:classrooms,id']]);
        DB::transaction(function () use ($r, $classroom, $user, $data): void {
            $c = Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->school_id === $c->school_id && $user->role !== Role::Admin, 422, 'Akun harus berasal dari madrasah yang sama.');
            abort_unless(DB::table('classroom_user')->where('classroom_id', $c->id)->where('user_id', $user->id)->exists(), 422, 'Keanggotaan tidak ditemukan.');
            if ($user->role === Role::Student) {
                $this->protectResearch([$user->id]);
            }
            if ($data['operation'] === 'activate') {
                abort_if($c->archived_at, 422, 'Kelas diarsipkan.');
                $c->belongsToMany(User::class)->withTimestamps()->syncWithoutDetaching([$user->id => ['is_active' => true]]);
            } else {
                if ($data['operation'] === 'transfer') {
                    $target = Classroom::whereKey($data['target_classroom_id'])->lockForUpdate()->firstOrFail();
                    abort_unless($target->school_id === $c->school_id && ! $target->archived_at && $target->id !== $c->id, 422, 'Kelas tujuan harus aktif dan berada pada madrasah yang sama.');
                    $target->belongsToMany(User::class)->withTimestamps()->syncWithoutDetaching([$user->id => ['is_active' => true]]);
                }$c->users()->updateExistingPivot($user->id, ['is_active' => false]);
                DB::table('learning_group_user')->where('user_id', $user->id)->whereIn('learning_group_id', LearningGroup::where('classroom_id', $c->id)->select('id'))->update(['is_active' => false, 'updated_at' => now()]);
            }
            LearningNotifier::audit($r->user()->id, 'membership_'.$data['operation'], 'user', $user->id, ['classroom_id' => $c->id, 'target_classroom_id' => $data['target_classroom_id'] ?? null]);
        });

        return response()->json(['message' => 'Keanggotaan diperbarui. Jawaban dan nilai lama tetap tersimpan.']);
    }

    public function rollover(Request $r, Classroom $classroom): ClassroomResource
    {
        $data = $r->validate(['name' => ['required', 'string', 'max:80'], 'academic_year' => ['required', 'string', 'max:20'], 'copy_structure' => ['required', 'boolean'], 'archive_source' => ['required', 'boolean']]);
        $next = DB::transaction(function () use ($r, $classroom, $data) {
            $c = Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
            abort_if($c->archived_at, 422, 'Pilih kelas sumber yang masih aktif.');
            $ids = $c->users()->pluck('users.id')->all();
            $this->protectResearch($c->users()->where('role', Role::Student)->pluck('users.id')->all());
            abort_if(Classroom::where('school_id', $c->school_id)->where('name', $data['name'])->where('academic_year', $data['academic_year'])->exists(), 422, 'Kelas tujuan sudah ada.');
            $next = Classroom::create(['school_id' => $c->school_id, 'name' => $data['name'], 'academic_year' => $data['academic_year']]);
            $next->users()->attach($ids);
            if ($data['copy_structure']) {
                foreach (Course::where('classroom_id', $c->id)->with('chapters.lessons')->get() as $course) {
                    $copy = Course::create([...$course->only(['title', 'subject', 'semester', 'method', 'description']), 'classroom_id' => $next->id, 'status' => 'draft', 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id]);
                    foreach ($course->chapters as $chapter) {
                        $newChapter = $copy->chapters()->create($chapter->only(['title', 'position']));
                        foreach ($chapter->lessons as $lesson) {
                            CourseLesson::create([...$lesson->only(['title', 'objective', 'position', 'mode', 'completion_rule', 'minimum_score']), 'course_id' => $copy->id, 'course_chapter_id' => $newChapter->id, 'status' => 'draft']);
                        }
                    }
                }
            }
            if ($data['archive_source']) {
                $c->update(['archived_at' => now(), 'version' => $c->version + 1]);
                Course::where('classroom_id', $c->id)->update(['status' => 'archived', 'updated_by' => $r->user()->id, 'version' => DB::raw('version+1'), 'updated_at' => now()]);
            }LearningNotifier::audit($r->user()->id, 'classroom_rolled_over', 'classroom', $c->id, ['new_classroom_id' => $next->id]);

            return $next;
        });

        return new ClassroomResource($next->load('school'));
    }

    public function group(Request $r, LearningGroup $group): JsonResponse
    {
        abort_unless($r->user()->canAccessClassroom($group->classroom_id), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:80', Rule::unique('learning_groups')->where('classroom_id', $group->classroom_id)->ignore($group->id)], 'archived' => ['sometimes', 'boolean'], 'student_ids' => ['present', 'array', Rule::when(! $r->boolean('archived'), 'min:1'), 'max:10'], 'student_ids.*' => ['integer', 'distinct', Rule::exists('classroom_user', 'user_id')->where('classroom_id', $group->classroom_id)->where('is_active', true), Rule::exists('users', 'id')->where('role', 'student')->where('is_active', true)]]);
        $saved = DB::transaction(function () use ($r, $group, $data) {
            Classroom::whereKey($group->classroom_id)->lockForUpdate()->firstOrFail();
            $g = LearningGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if($g->version !== $data['version'], 409, 'Kelompok berubah. Muat ulang.');
            $before = $g->users()->pluck('users.id')->all();
            $changed = array_unique([...array_diff($before, $data['student_ids']), ...array_diff($data['student_ids'], $before)]);
            $this->protectResearch($changed);
            $other = LearningGroup::where('classroom_id', $g->classroom_id)->whereKeyNot($g->id)->whereHas('users', fn ($q) => $q->whereIn('users.id', $data['student_ids']))->exists();
            abort_if($other, 422, 'Anggota masih aktif di kelompok lain. Keluarkan melalui pengaturan kelompok asal dahulu.');
            DB::table('learning_group_user')->where('learning_group_id', $g->id)->update(['is_active' => false, 'updated_at' => now()]);
            $g->belongsToMany(User::class)->withTimestamps()->syncWithoutDetaching(array_fill_keys($data['student_ids'], ['is_active' => true]));
            $g->update(['name' => $data['name'], 'version' => $g->version + 1, 'archived_at' => $r->boolean('archived') ? now() : null]);
            LearningNotifier::audit($r->user()->id, 'group_updated', 'group', $g->id, ['previous_members' => $before, 'members' => $data['student_ids']]);

            return $g;
        });

        return response()->json(['data' => $saved->load('users:id,name')]);
    }

    public function moduleVideo(Request $r, LearningModule $module): JsonResponse
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())], 'version' => ['required', 'integer', 'min:0'], 'youtube_url' => ['nullable', 'string', 'max:2048'], 'video_caption' => ['nullable', 'string', 'max:3000']]);
        abort_unless($module->availableIn((int) $data['classroom_id']), 403);
        $video = YouTubeVideo::id($data['youtube_url'] ?? null);
        $row = DB::transaction(function () use ($r, $module, $data, $video) {
            Classroom::whereKey($data['classroom_id'])->lockForUpdate()->firstOrFail();
            $keys = ['learning_module_id' => $module->id, 'classroom_id' => $data['classroom_id']];
            $old = DB::table('module_class_media')->where($keys)->first();
            abort_if(($old?->version ?? 0) !== $data['version'], 409, 'Video LKPD berubah. Muat ulang.');
            DB::table('module_class_media')->updateOrInsert($keys, ['video_is_overridden' => true, 'youtube_video_id' => $video, 'video_caption' => $data['video_caption'] ?? null, 'updated_by' => $r->user()->id, 'version' => ($old?->version ?? 0) + 1, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            LearningNotifier::audit($r->user()->id, 'lkpd_video_updated', 'module', $module->id, ['classroom_id' => $data['classroom_id'], 'youtube_video_id' => $video]);

            return DB::table('module_class_media')->where($keys)->first();
        });

        return response()->json(['data' => $row]);
    }
}
