<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAssignmentRequest;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseLesson;
use App\Models\LearningGroup;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        return response()->json(Assignment::visibleTo($r->user())->with('lesson:id,title,course_id')->when($r->integer('lesson_id'), fn ($q) => $q->where('course_lesson_id', $r->integer('lesson_id')))->when($r->integer('classroom_id'), fn ($q) => $q->where('classroom_id', $r->integer('classroom_id')))->latest()->paginate(30));
    }

    public function show(Request $r, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->canView($r->user()), 403);

        return response()->json(['data' => $assignment->load('lesson:id,title,course_id')]);
    }

    public function store(SaveAssignmentRequest $r): JsonResponse
    {
        $lesson = CourseLesson::findOrFail($r->integer('course_lesson_id'));

        return response()->json(['data' => Assignment::create([...$r->safe()->except('version'), 'classroom_id' => $lesson->course->classroom_id, 'created_by' => $r->user()->id])], 201);
    }

    public function update(SaveAssignmentRequest $r, Assignment $assignment): JsonResponse
    {
        return response()->json(['data' => DB::transaction(function () use ($r, $assignment) {
            $target = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $r->integer('version'), 409, 'Tugas sudah berubah. Muat ulang.');
            abort_if($target->course_lesson_id !== $r->integer('course_lesson_id'), 422, 'Pertemuan tugas tidak dapat dipindahkan.');
            $target->update([...$r->safe()->except('version'), 'version' => $target->version + 1]);

            return $target;
        })]);
    }

    private function present(AssignmentSubmission $s): array
    {
        return [...$s->toArray(), 'file_url' => $s->file_path ? route('submissions.file', $s->id, false) : null, 'normalized_score' => $s->score !== null ? round($s->score / $s->assignment_snapshot['max_score'] * 100, 2) : null];
    }

    public function submissions(Request $r): JsonResponse
    {
        $rows = AssignmentSubmission::visibleTo($r->user())->with('assignment:id,title,classroom_id')->when($r->integer('assignment_id'), fn ($q) => $q->where('assignment_id', $r->integer('assignment_id')))->latest()->paginate(40);
        $rows->through(fn ($s) => $this->present($s));

        return response()->json($rows);
    }

    public function submit(Request $r, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->canView($r->user()), 403);
        $staff = $r->user()->role !== Role::Student;
        $data = $r->validate(['request_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:draft,submitted'], 'content' => ['required', 'string', 'max:15000'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'remove_file' => ['sometimes', 'boolean'], 'share_with_class' => ['sometimes', 'boolean'], 'student_id' => [$staff ? 'required' : 'prohibited', 'integer'], 'group_id' => ['nullable', 'integer'], 'offline_evidence' => [$staff ? 'required' : 'prohibited', 'string', 'max:3000'], 'submitted_at' => [$staff ? 'nullable' : 'prohibited', 'date', 'before_or_equal:now']]);
        $student = $staff ? User::findOrFail($data['student_id']) : $r->user();
        abort_unless($student->role === Role::Student && $student->canAccessClassroom($assignment->classroom_id), 422, 'Siswa harus menjadi anggota kelas tugas.');
        $group = null;
        $members = [$student->id];
        if ($assignment->submission_mode === 'group') {
            $group = LearningGroup::where('classroom_id', $assignment->classroom_id)->whereHas('users', fn ($q) => $q->whereKey($student->id))->findOrFail($data['group_id'] ?? 0);
            $members = $group->users()->pluck('users.id')->all();
        }
        abort_if($assignment->lesson->course->method === 'svil' && ! $student->canUseSvil(), 403, 'Peserta kontrol tidak menerima tugas intervensi SVIL.');
        $owner = $group ? 'group:'.$group->id : 'student:'.$student->id;
        $newPath = null;
        try {
            $result = DB::transaction(function () use ($r, $assignment, $data, $student, $group, $members, $owner, $staff, &$newPath) {
                $targetAssignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                $retry = DB::table('submission_revisions')->where('actor_id', $r->user()->id)->where('request_id', $data['request_id'])->first();
                if ($retry) {
                    $existing = AssignmentSubmission::findOrFail($retry->assignment_submission_id);
                    abort_if($existing->assignment_id !== $assignment->id || $existing->owner_key !== $owner, 409, 'ID pengiriman sudah digunakan.');

                    return $existing;
                }
                abort_unless($targetAssignment->status === 'published' && $targetAssignment->lesson->status === 'open' && $targetAssignment->lesson->course->status === 'published', 422, 'Pengumpulan tugas belum dibuka atau sudah ditutup.');
                $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->where('owner_key', $owner)->first();
                abort_if($staff && $submission?->source === 'online', 422, 'Jawaban daring diperbaiki oleh siswa. Gunakan tinjauan untuk memberi umpan balik.');
                abort_if(($submission?->version ?? 0) !== $data['version'], 409, 'Jawaban sudah berubah. Muat versi terbaru.');
                $submitted = $staff && isset($data['submitted_at']) ? Carbon::parse($data['submitted_at']) : now();
                if ($r->hasFile('file')) {
                    $newPath = $r->file('file')->store('submissions', 'local');
                    abort_unless($newPath, 500, 'Berkas belum tersimpan.');
                }
                $submission ??= new AssignmentSubmission(['assignment_id' => $assignment->id, 'owner_key' => $owner]);
                $values = ['student_id' => $student->id, 'learning_group_id' => $group?->id, 'member_ids' => $members, 'content' => $data['content'], 'source' => $staff ? 'offline' : 'online', 'offline_evidence' => $staff ? $data['offline_evidence'] : null, 'recorded_by' => $r->user()->id, 'status' => $data['status'], 'version' => ($submission->version ?? 0) + 1, 'assignment_snapshot' => $submission->assignment_snapshot ?? $targetAssignment->only(['title', 'instructions', 'max_score', 'rubric', 'version', 'due_at', 'kind']), 'submitted_at' => $submitted, 'is_late' => $targetAssignment->due_at ? $submitted->gt($targetAssignment->due_at) : false, 'share_with_class' => $r->boolean('share_with_class'), 'gallery_published' => false, 'score' => null, 'rubric_scores' => null, 'feedback' => null, 'reviewed_by' => null, 'reviewed_at' => null];
                if ($newPath) {
                    $values += ['file_path' => $newPath, 'file_name' => basename($r->file('file')->getClientOriginalName()), 'file_mime' => $r->file('file')->getMimeType(), 'file_size' => $r->file('file')->getSize()];
                } elseif ($r->boolean('remove_file')) {
                    $values += ['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null];
                }
                $submission->fill($values)->save();
                $this->revision($submission, $r->user()->id, $data['request_id']);

                return $submission;
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }throw $e;
        }

        return response()->json(['data' => $this->present($result)]);
    }

    private function revision(AssignmentSubmission $s, int $actor, ?string $requestId = null): void
    {
        DB::table('submission_revisions')->insert(['assignment_submission_id' => $s->id, 'actor_id' => $actor, 'request_id' => $requestId, 'version' => $s->version, 'snapshot' => json_encode($s->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function review(Request $r, AssignmentSubmission $submission): JsonResponse
    {
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($submission->assignment->classroom_id), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:reviewed,needs_revision'], 'score' => ['nullable', 'numeric', 'min:0', 'max:'.$submission->assignment_snapshot['max_score']], 'feedback' => ['required', 'string', 'max:3000'], 'rubric_scores' => ['nullable', 'array'], 'gallery_published' => ['sometimes', 'boolean']]);
        $saved = DB::transaction(function () use ($r, $submission, $data) {
            $target = AssignmentSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $data['version'], 409, 'Pengumpulan sudah berubah.');
            abort_if($target->status === 'draft', 422, 'Siswa belum mengirimkan tugas.');
            $rubric = $target->assignment_snapshot['rubric'] ?? null;
            $score = $data['score'] ?? null;
            if ($rubric) {
                $scores = $data['rubric_scores'] ?? [];
                abort_unless(count($scores) === count($rubric), 422, 'Lengkapi seluruh aspek rubrik.');
                foreach ($rubric as $aspect) {
                    abort_unless(isset($scores[$aspect['key']]) && is_numeric($scores[$aspect['key']]) && $scores[$aspect['key']] >= 0 && $scores[$aspect['key']] <= $aspect['max_points'], 422, 'Skor rubrik di luar rentang.');
                }$score = array_sum($scores);
            }
            abort_if($data['status'] === 'reviewed' && $score === null, 422, 'Isi skor atau gunakan status perlu perbaikan.');
            abort_if($r->boolean('gallery_published') && (! $target->share_with_class || ($target->assignment_snapshot['kind'] ?? 'task') !== 'project'), 422, 'Galeri hanya untuk proyek yang disetujui pembuatnya.');
            $target->update([...$data, 'score' => $score, 'version' => $target->version + 1, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'gallery_published' => $r->boolean('gallery_published')]);
            $this->revision($target, $r->user()->id);

            return $target;
        });

        return response()->json(['data' => $this->present($saved)]);
    }

    private function canRead(Request $r, AssignmentSubmission $s): bool
    {
        return AssignmentSubmission::visibleTo($r->user())->whereKey($s->id)->exists() || ($s->gallery_published && $s->share_with_class && $s->assignment->canView($r->user()));
    }

    public function file(Request $r, AssignmentSubmission $submission): StreamedResponse
    {
        abort_unless($this->canRead($r, $submission), 403);
        abort_unless($submission->file_path && Storage::disk('local')->exists($submission->file_path), 404);

        return Storage::disk('local')->download($submission->file_path, $submission->file_name, ['Content-Type' => $submission->file_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function gallery(Request $r): JsonResponse
    {
        $items = AssignmentSubmission::where('gallery_published', true)->where('share_with_class', true)->whereIn('assignment_id', Assignment::visibleTo($r->user())->select('id'))->with('assignment:id,title,classroom_id')->when($r->integer('classroom_id'), fn ($q) => $q->whereHas('assignment', fn ($q) => $q->where('classroom_id', $r->integer('classroom_id'))))->latest()->paginate(30);
        $items->through(fn ($s) => ['id' => $s->id, 'assignment_id' => $s->assignment_id, 'title' => $s->assignment->title, 'content' => $s->content, 'owner_code' => $s->learning_group_id ? 'K-'.$s->learning_group_id : 'P-'.$s->student_id, 'file_name' => $s->file_name, 'file_url' => $s->file_path ? route('submissions.file', $s->id, false) : null]);

        return response()->json($items);
    }

    public function comments(Request $r, AssignmentSubmission $submission): JsonResponse
    {
        abort_unless($this->canRead($r, $submission), 403);

        return response()->json(['data' => DB::table('project_comments')->where('assignment_submission_id', $submission->id)->when($r->user()->role === Role::Student, fn ($q) => $q->where('is_hidden', false))->orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'author' => 'P-'.$c->author_id, 'appreciation' => $c->appreciation, 'question' => $c->question, 'is_hidden' => (bool) $c->is_hidden])]);
    }

    public function comment(Request $r, AssignmentSubmission $submission): JsonResponse
    {
        abort_unless($submission->gallery_published && $this->canRead($r, $submission), 403);
        $data = $r->validate(['appreciation' => ['required', 'string', 'max:1500'], 'question' => ['required', 'string', 'max:1500']]);
        $keys = ['assignment_submission_id' => $submission->id, 'author_id' => $r->user()->id];
        $old = DB::table('project_comments')->where($keys)->first();
        DB::table('project_comments')->updateOrInsert($keys, [...$data, 'is_hidden' => $old?->is_hidden ?? false, 'moderated_by' => $old?->moderated_by, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Apresiasi dan pertanyaan tersimpan.']);
    }

    public function moderateComment(Request $r, int $comment): JsonResponse
    {
        $row = DB::table('project_comments')->find($comment);
        abort_unless($row, 404);
        $submission = AssignmentSubmission::findOrFail($row->assignment_submission_id);
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($submission->assignment->classroom_id), 403);
        $data = $r->validate(['is_hidden' => ['required', 'boolean']]);
        DB::table('project_comments')->where('id',$comment)->update([...$data, 'moderated_by' => $r->user()->id, 'updated_at' => now()]);

        return response()->json(['message' => 'Moderasi tersimpan.']);
    }
}
