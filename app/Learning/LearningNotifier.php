<?php

namespace App\Learning;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseLesson;
use App\Models\Discussion;
use App\Models\GroupWork;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LearningNotifier
{
    public static function audit(int $actor, string $action, string $type, int|string $id, array $data = []): void
    {
        DB::table('learning_audits')->insert(['actor_id' => $actor, 'action' => $action, 'subject_type' => $type, 'subject_id' => (string) $id, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public static function send(array $ids, string $kind, string $title, string $body, string $studentPath, string $event, ?int $classroom = null): void
    {
        $rows = [];
        foreach (User::whereIn('id', array_unique($ids))->where('is_active', true)->get(['id', 'role']) as $user) {
            $path = $user->role === Role::Student ? $studentPath : ($user->role === Role::Admin ? '/admin/laporan' : '/guru/tinjauan');
            if ($classroom) {
                $path .= (str_contains($path, '?') ? '&' : '?').'kelas='.$classroom;
            }
            $rows[] = ['recipient_id' => $user->id, 'kind' => $kind, 'title' => mb_substr($title, 0, 180), 'body' => $body, 'target_path' => $path, 'dedupe_key' => $event, 'created_at' => now(), 'updated_at' => now()];
        }if ($rows) {
            DB::table('learning_notifications')->insertOrIgnore($rows);
        }
    }

    public static function members(int $classroom, string $role = 'student'): array
    {
        return User::where('role', $role)->whereHas('classrooms', fn ($q) => $q->whereKey($classroom))->pluck('id')->all();
    }

    public static function remind(User $student): void
    {
        if ($student->role !== Role::Student) {
            return;
        }
        foreach (Assignment::visibleTo($student)->where('status', 'published')->whereBetween('due_at', [now(), now()->addDay()])->get() as $task) {
            $done = AssignmentSubmission::where('assignment_id', $task->id)->whereJsonContains('member_ids', $student->id)->whereIn('status', ['submitted', 'reviewed'])->exists();
            if (! $done) {
                self::send([$student->id], 'deadline', 'Tugasmu mendekati batas waktu', $task->title, '/siswa/belajar?kegiatan=task', 'due:task:'.$task->id.':'.now()->format('Y-m-d'), $task->classroom_id);
            }
        }
        foreach (Assessment::visibleTo($student)->whereBetween('closes_at', [now(), now()->addDay()])->get() as $test) {
            $done = AssessmentAttempt::where('assessment_id', $test->id)->where('student_id', $student->id)->whereNotNull('submitted_at')->exists();
            if (! $done) {
                self::send([$student->id], 'deadline', 'Waktu kuis akan ditutup', $test->title, '/siswa/belajar?kegiatan=assessment', 'due:test:'.$test->id.':'.now()->format('Y-m-d'), $test->classroom_id);
            }
        }
    }

    public static function observe(Model $model): void
    {
        if (! Auth::id()) {
            return;
        } $actor = Auth::id();
        $classroom = $model->getAttribute('classroom_id') ?? match (true) {
            $model instanceof AssignmentSubmission => $model->assignment->classroom_id,$model instanceof AssessmentAttempt => $model->assessment->classroom_id,$model instanceof CourseLesson => $model->course->classroom_id,$model instanceof GroupWork => $model->group->classroom_id,$model instanceof MaterialAttempt => $model->material->classroom_id,default => null
        };
        $kind = class_basename($model);
        $version = $model->version ?? $model->review_version ?? $model->updated_at?->format('Uu') ?? 1;
        $event = $kind.':'.$model->getKey().':'.$version;
        if ($model instanceof Material && $model->status === 'published' && ($model->wasRecentlyCreated || $model->wasChanged(['version', 'status']))) {
            self::send(self::members($model->classroom_id), 'material', 'Materi dari guru: '.$model->title, 'Bahan belajar baru atau pembaruan sudah tersedia.', '/siswa/belajar?materi='.$model->id, $event, $classroom);
        } elseif ($model instanceof Assignment && $model->status === 'published' && ($model->wasRecentlyCreated || $model->wasChanged(['version', 'status']))) {
            self::send(self::members($model->classroom_id), 'assignment', 'Tugas: '.$model->title, 'Baca petunjuk dan batas pengumpulan dari gurumu.', '/siswa/belajar?kegiatan=task', $event, $classroom);
        } elseif ($model instanceof Assessment && $model->status === 'published' && ($model->wasRecentlyCreated || $model->wasChanged(['version', 'status']))) {
            self::send(self::members($model->classroom_id), 'assessment', 'Kuis: '.$model->title, 'Periksa jadwal kuis dan kerjakan dengan tenang.', '/siswa/belajar?kegiatan=assessment', $event, $classroom);
        } elseif ($model instanceof Discussion && $model->status === 'open' && ($model->wasRecentlyCreated || $model->wasChanged(['version', 'status']))) {
            self::send(self::members($model->classroom_id), 'discussion', 'Diskusi kelas dibuka', 'Gurumu mengajakmu berbagi pendapat.', '/siswa/belajar?kegiatan=discussion', $event, $classroom);
        } elseif ($model instanceof CourseLesson && $model->wasChanged('scheduled_on')) {
            self::send(self::members($model->course->classroom_id), 'schedule', 'Jadwal pertemuan diperbarui: '.$model->title, 'Periksa tanggal kegiatan pada kalender kelas.', '/siswa/belajar?kegiatan=calendar', $event, $classroom);
        } elseif ($model instanceof AssignmentSubmission) {
            if (in_array($model->status, ['reviewed', 'needs_revision'], true)) {
                self::send($model->member_ids, 'feedback', 'Pesan guru untuk tugasmu', $model->status === 'needs_revision' ? 'Ada bagian yang perlu kamu perbaiki.' : 'Hasil pekerjaanmu sudah ditinjau guru.', '/siswa/belajar?kegiatan=task', $event, $classroom);
            } elseif ($model->status === 'submitted') {
                self::send(self::members($model->assignment->classroom_id, 'teacher'), 'submission', 'Pengumpulan tugas masuk', $model->assignment_snapshot['title'], '/siswa/belajar?kegiatan=task', $event, $classroom);
            }
        } elseif ($model instanceof AssessmentAttempt && $model->status !== 'in_progress') {
            if ($model->reviewed_by) {
                self::send([$model->student_id], 'feedback', 'Kuis sudah ditinjau guru', $model->assessment_snapshot['title'], '/siswa/belajar?kegiatan=assessment', $event, $classroom);
            } elseif ($model->status === 'pending_review') {
                self::send(self::members($model->assessment->classroom_id, 'teacher'), 'assessment_review', 'Uraian kuis perlu ditinjau', $model->assessment_snapshot['title'], '/siswa/belajar?kegiatan=assessment', $event, $classroom);
            }
        } elseif ($model instanceof LearningNote && $model->wasChanged('reviewed_at') && $model->reviewed_at) {
            self::send([$model->student_id], 'feedback', 'Guru membalas catatanmu', 'Baca apresiasi dan saran dari gurumu.', '/siswa/cerita', $event, $classroom);
        } elseif ($model instanceof MaterialAttempt && $model->wasChanged('reviewed_at') && $model->reviewed_at) {
            self::send([$model->student_id], 'feedback', 'Guru meninjau latihanmu', 'Baca pesan guru untuk latihan yang sudah kamu kerjakan.', '/siswa/cerita', $event, $classroom);
        } elseif ($model instanceof GroupWork && in_array($model->status, ['reviewed', 'needs_revision'], true)) {
            self::send($model->member_ids ?? $model->group->users()->pluck('users.id')->all(), 'feedback', 'LKPD kelompokmu ditinjau', 'Baca pesan guru bersama teman kelompokmu.', '/siswa/kelompok', $event, $classroom);
        }
    }
}
