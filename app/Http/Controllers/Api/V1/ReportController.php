<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionPlan;
use App\Models\Discussion;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\Observation;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $classes = $request->user()->accessibleClassrooms()->pluck('id');
        $request->validate(['classroom_id' => ['nullable', 'integer', Rule::in($classes->all())]]);
        if ($request->filled('classroom_id')) {
            $classes = $classes->filter(fn ($id) => $id === $request->integer('classroom_id'))->values();
        }
        $groups = LearningGroup::whereIn('classroom_id', $classes)->select('id');
        $plans = ActionPlan::whereIn('learning_group_id', clone $groups)->select('id');
        $materials = Material::whereIn('classroom_id', $classes)->select('id');

        return response()->json(['data' => [
            'classrooms' => $classes->count(),
            'students' => User::where('role', Role::Student)->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $classes))->count(),
            'materials' => Material::whereIn('classroom_id', $classes)->count(),
            'notes' => LearningNote::whereIn('classroom_id', $classes)->count(),
            'reviewedNotes' => LearningNote::whereIn('classroom_id', $classes)->whereNotNull('reviewed_at')->count(),
            'practiceAttempts' => MaterialAttempt::whereIn('material_id', clone $materials)->count(),
            'pendingPracticeReviews' => MaterialAttempt::whereIn('material_id', clone $materials)->whereNull('correct')->whereNull('reviewed_at')->count(),
            'groups' => (clone $groups)->count(),
            'submittedWorks' => GroupWork::whereIn('learning_group_id', clone $groups)->where('status', '!=', 'draft')->count(),
            'reviewedWorks' => GroupWork::whereIn('learning_group_id', clone $groups)->where('status', 'reviewed')->count(),
            'actionPlans' => (clone $plans)->count(),
            'actionJournals' => DB::table('action_journals')->whereIn('action_plan_id', clone $plans)->count(),
            'verifiedActions' => ActionPlan::whereIn('learning_group_id', clone $groups)->whereNotNull('verified_at')->count(),
            'observations' => Observation::whereIn('classroom_id', $classes)->count(),
            'researchInstrumentsReady' => false,
            'learningModules' => LearningModule::where('status', 'published')->count(),
            'quizzes' => Material::whereIn('classroom_id', $classes)->where('kind', 'quiz')->count(),
            'draftMaterials' => Material::whereIn('classroom_id', $classes)->where('status', 'draft')->count(),
            'publishedMaterials' => Material::whereIn('classroom_id', $classes)->where('status', 'published')->count(),
            'discussions' => Discussion::whereIn('classroom_id', $classes)->count(),
            'openDiscussions' => Discussion::whereIn('classroom_id', $classes)->where('status', 'open')->count(),
            'administration' => $request->user()->role === Role::Admin ? [
                'schools' => School::count(), 'teachers' => User::where('role', Role::Teacher)->count(), 'students' => User::where('role', Role::Student)->count(),
                'activeUsers' => User::where('is_active', true)->count(), 'inactiveUsers' => User::where('is_active', false)->count(),
            ] : null,
        ]]);
    }

    private static function csvCell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return preg_match('/^\s*[=+\-@]|^[\t\r]/u', $text) ? "'".$text : $text;
    }

    public function notes(Request $request): StreamedResponse
    {
        $query = LearningNote::whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['jenis_data', 'peserta', 'kelas_id', 'pertemuan', 'fase', 'catatan', 'umpan_balik', 'diperbarui'], escape: '');
            foreach ($query->cursor() as $note) {
                fputcsv($stream, array_map(self::csvCell(...), ['CATATAN BELAJAR BUKAN TES RISET', 'P-'.$note->student_id, $note->classroom_id, $note->topic + 1, $note->phase + 1, $note->content, $note->feedback, $note->updated_at->toIso8601String()]), escape: '');
            }
            fclose($stream);
        }, 'svil-catatan-belajar.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function learning(Request $request): StreamedResponse
    {
        $classes = $request->user()->accessibleClassrooms()->pluck('id')->all();

        return response()->streamDownload(function () use ($classes) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['jenis_data', 'rekaman_id', 'peserta', 'kelompok', 'kelas_id', 'pertemuan', 'versi', 'status', 'isi_json', 'diperbarui'], escape: '');
            $write = static function (string $kind, mixed $id, ?int $student, ?int $group, int $classroom, ?int $topic, int $version, string $status, array $payload, string $updated) use ($stream): void {
                fputcsv($stream, array_map(self::csvCell(...), [$kind, $id, $student ? 'P-'.$student : '', $group ? 'K-'.$group : '', $classroom, $topic !== null ? $topic + 1 : '', $version, $status, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $updated]), escape: '');
            };
            foreach (Material::whereIn('classroom_id', $classes)->orderBy('id')->cursor() as $material) {
                $write($material->kind === 'quiz' ? 'KUIS' : 'MATERI', $material->id, null, null, $material->classroom_id, $material->topic, $material->version, $material->status, [
                    'title' => $material->title, 'subject' => $material->subject, 'content' => $material->content,
                    'uploaded_by' => 'U-'.$material->teacher_id, 'updated_by' => $material->updated_by ? 'U-'.$material->updated_by : null,
                    'question' => $material->question, 'attachment_name' => $material->attachment_name,
                ], $material->updated_at->toIso8601String());
            }
            foreach (Discussion::whereIn('classroom_id', $classes)->orderBy('id')->cursor() as $discussion) {
                $write('DISKUSI', $discussion->id, null, null, $discussion->classroom_id, null, $discussion->version, $discussion->status, [
                    'title' => $discussion->title, 'content' => $discussion->content, 'author' => 'U-'.$discussion->author_id,
                ], $discussion->updated_at->toIso8601String());
            }
            $replies = DB::table('discussion_replies as r')->join('discussions as d', 'd.id', '=', 'r.discussion_id')->join('users as u', 'u.id', '=', 'r.author_id')
                ->whereIn('d.classroom_id', $classes)->select('r.*', 'd.classroom_id', 'u.role')->orderBy('r.id');
            foreach ($replies->cursor() as $reply) {
                $write('TANGGAPAN_DISKUSI', $reply->id, $reply->role === 'student' ? $reply->author_id : null, null, $reply->classroom_id, null, 1, $reply->is_hidden ? 'hidden' : 'visible', [
                    'discussion_id' => $reply->discussion_id, 'author' => ($reply->role === 'student' ? 'P-' : 'U-').$reply->author_id, 'content' => $reply->content, 'moderated_at' => $reply->moderated_at,
                ], $reply->updated_at);
            }

            $works = GroupWork::with('group:id,classroom_id')->whereHas('group', fn ($q) => $q->whereIn('classroom_id', $classes))->orderBy('id');
            foreach ($works->lazy(200) as $work) {
                $write('LKPD', $work->id, null, $work->learning_group_id, $work->group->classroom_id, $work->topic, $work->version, $work->status, [
                    'module_version' => $work->module_version, 'schema' => $work->schema_snapshot, 'answers' => $work->answers, 'feedback' => $work->feedback, 'product_scores' => $work->product_scores, 'product_maximum' => 16, 'presentation_scores' => $work->presentation_scores, 'presentation_maximum' => 8, 'product_value' => $work->summary()['product_value'], 'presentation_value' => $work->summary()['presentation_value'],
                ], $work->updated_at->toIso8601String());
            }
            $revisions = DB::table('group_work_revisions as r')->join('group_works as w', 'w.id', '=', 'r.group_work_id')->join('learning_groups as g', 'g.id', '=', 'w.learning_group_id')
                ->whereIn('g.classroom_id', $classes)->select('r.*', 'g.classroom_id', 'w.learning_group_id', 'w.topic')->orderBy('r.id');
            foreach ($revisions->cursor() as $revision) {
                $snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $snapshot['members'] = array_map(fn ($id) => 'P-'.$id, $snapshot['member_ids'] ?? []);
                unset($snapshot['member_ids'],$snapshot['reviewed_by']);
                $write('LKPD_RIWAYAT', $revision->group_work_id, null, $revision->learning_group_id, $revision->classroom_id, $revision->topic, $revision->version, $snapshot['status'], $snapshot, $revision->created_at);
            }
            foreach (ActionPlan::with('group:id,classroom_id')->whereHas('group', fn ($q) => $q->whereIn('classroom_id', $classes))->orderBy('id')->lazy(200) as $plan) {
                $write('RENCANA_AKSI', $plan->id, null, $plan->learning_group_id, $plan->group->classroom_id, 3, $plan->version, $plan->verified_at ? 'verified' : 'unverified', [
                    'title' => $plan->title, 'start_date' => $plan->start_date->format('Y-m-d'), 'location' => $plan->location, 'indicator' => $plan->indicator, 'steps' => $plan->steps, 'responsibilities' => $plan->responsibilities, 'evidence_method' => $plan->evidence_method, 'teacher_feedback' => $plan->teacher_feedback, 'verified_at' => $plan->verified_at?->toIso8601String(),
                ], $plan->updated_at->toIso8601String());
            }
            $journals = DB::table('action_journals as j')->join('action_plans as p', 'p.id', '=', 'j.action_plan_id')->join('learning_groups as g', 'g.id', '=', 'p.learning_group_id')
                ->whereIn('g.classroom_id', $classes)->select('j.*', 'p.learning_group_id', 'g.classroom_id')->orderBy('j.id');
            foreach ($journals->cursor() as $journal) {
                $snapshot = json_decode($journal->plan_snapshot, true, flags: JSON_THROW_ON_ERROR);
                unset($snapshot['verified_by']);
                $write('JURNAL_AKSI', $journal->id, $journal->student_id, $journal->learning_group_id, $journal->classroom_id, 3, $journal->plan_version, 'recorded', [
                    'action_plan_id' => $journal->action_plan_id, 'occurred_on' => $journal->occurred_on, 'activity' => $journal->activity, 'evidence' => $journal->evidence, 'obstacle' => $journal->obstacle, 'plan_snapshot' => $snapshot,
                ], $journal->updated_at);
            }
            $attempts = MaterialAttempt::with('material:id,classroom_id,topic')->whereHas('material', fn ($q) => $q->whereIn('classroom_id', $classes))->orderBy('id');
            foreach ($attempts->lazy(200) as $attempt) {
                $write('LATIHAN', $attempt->id, $attempt->student_id, null, $attempt->material->classroom_id, $attempt->material->topic, $attempt->review_version, $attempt->reviewed_at ? 'reviewed' : ($attempt->correct === null ? 'pending' : 'automatic'), [
                    'material_id' => $attempt->material_id, 'material_version' => $attempt->material_version, 'question' => $attempt->question_snapshot, 'answer' => $attempt->answer, 'correct' => $attempt->correct, 'feedback' => $attempt->teacher_feedback,
                ], $attempt->updated_at->toIso8601String());
            }
            $reviews = DB::table('attempt_reviews as r')->join('material_attempts as a', 'a.id', '=', 'r.material_attempt_id')->join('materials as m', 'm.id', '=', 'a.material_id')
                ->whereIn('m.classroom_id', $classes)->select('r.*', 'a.student_id', 'm.classroom_id', 'm.topic')->orderBy('r.id');
            foreach ($reviews->cursor() as $review) {
                $write('TINJAUAN_LATIHAN_RIWAYAT', $review->material_attempt_id, $review->student_id, null, $review->classroom_id, $review->topic, $review->version, 'reviewed', ['correct' => $review->correct === null ? null : (bool) $review->correct, 'feedback' => $review->feedback], $review->created_at);
            }
            foreach (Observation::whereIn('classroom_id', $classes)->orderBy('id')->cursor() as $observation) {
                $write('OBSERVASI', $observation->id, $observation->student_id, null, $observation->classroom_id, $observation->topic, $observation->version, 'recorded', [
                    'occasion' => $observation->occasion, 'observed_on' => $observation->observed_on->format('Y-m-d'), 'scores' => $observation->scores, 'maximum' => 16, 'value' => round(array_sum($observation->scores) / 16 * 100, 2), 'evidence' => $observation->evidence, 'feedback' => $observation->feedback,
                ], $observation->updated_at->toIso8601String());
            }
            $observations = DB::table('observation_revisions as r')->join('observations as o', 'o.id', '=', 'r.observation_id')->whereIn('o.classroom_id', $classes)->select('r.*', 'o.student_id', 'o.classroom_id', 'o.topic')->orderBy('r.id');
            foreach ($observations->cursor() as $revision) {
                $snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);
                unset($snapshot['student_id'],$snapshot['teacher_id']);
                $write('OBSERVASI_RIWAYAT', $revision->observation_id, $revision->student_id, null, $revision->classroom_id, $revision->topic, $revision->version, 'recorded', $snapshot, $revision->created_at);
            }
            fclose($stream);
        }, 'svil-kegiatan-belajar.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
