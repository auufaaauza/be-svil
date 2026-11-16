<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewGroupWorkRequest;
use App\Http\Requests\SaveGroupWorkRequest;
use App\Learning\LearningNotifier;
use App\Learning\LkpdPages;
use App\Learning\LkpdSchema;
use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupWorkController extends Controller
{
    public function show(Request $request, LearningGroup $group, int $topic): JsonResponse
    {
        abort_unless($group->canView($request->user()) && $request->user()->canUseSvil(), 403);
        $work = GroupWork::where('learning_group_id', $group->id)->where('topic', $topic)->first();
        abort_if($request->user()->role === Role::Student && $work?->member_ids && ! in_array($request->user()->id, $work->member_ids, true), 403);

        $module = LearningModule::where('topic', $topic)->firstOrFail();
        abort_if($request->user()->role === Role::Student && $module->status !== 'published' && ! $work, 403);

        abort_unless($module->availableIn($group->classroom_id), 403);
        $content = $module->contentFor($group->classroom_id);

        return response()->json(['data' => $work?->summary(), 'fields' => LkpdPages::fields($module, $work, $group->classroom_id), 'module' => [...$module->mediaFor($group->classroom_id), 'pageSize' => LkpdPages::size($work ?? $module), 'title' => $content['title'], 'instructions' => $content['instructions'], 'version' => $work?->module_version ?? $module->version], 'rubrics' => LkpdSchema::rubrics(), 'guidance' => LkpdSchema::guidance()]);
    }

    public function store(SaveGroupWorkRequest $request, LearningGroup $group, int $topic): JsonResponse
    {
        $work = DB::transaction(function () use ($request, $group, $topic) {
            Classroom::whereKey($group->classroom_id)->lockForUpdate()->firstOrFail();
            LearningGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $work = GroupWork::where('learning_group_id', $group->id)->where('topic', $topic)->first();
            abort_if(($work?->version ?? 0) !== $request->integer('version'), 409, 'LKPD telah berubah. Muat ulang sebelum melanjutkan.');
            abort_if($work?->member_ids && ! in_array($request->user()->id, $work->member_ids, true), 422, 'Anggota LKPD ini sudah dibekukan. Gunakan kelompok baru untuk kegiatan baru.');
            $version = ($work?->version ?? 0) + 1;
            $work ??= new GroupWork(['learning_group_id' => $group->id, 'topic' => $topic]);
            $pages = LkpdPages::entries($work);
            $fields = $request->moduleSchema()['fields'];
            $size = $request->moduleSchema()['page_size'];
            $work->page_size = $size;
            $before = $work->answers ?? [];
            $answers = array_replace($before, $request->validated('answers'));
            foreach ($fields as $index => $field) {
                if (($before[$field['key']] ?? '') !== ($answers[$field['key']] ?? '')) {
                    $existingPage = $pages[(int) floor($index / $size)] ?? null;
                    abort_if($size === 1 && $existingPage && ($existingPage['review_status'] ?? '') !== 'needs_revision', 422, 'Section sudah dikirim. Tunggu guru membuka perbaikan sebelum mengubah jawaban.');
                    unset($pages[(int) floor($index / $size)]);
                }
            }
            if (! $request->exists('page') && $before !== $answers && $request->input('status') === 'draft') {
                $pages = [];
            }
            if (! $work->schema_snapshot || ! count(LkpdPages::entries($work))) {
                $work->fill(['schema_snapshot' => $fields, 'module_version' => $request->moduleSchema()['version']]);
            }
            $total = (int) ceil(count($fields) / $size);
            if ($request->input('status') === 'submitted') {
                $indices = $request->exists('page') ? [$request->integer('page')] : range(0, $total - 1);
                foreach ($indices as $index) {
                    $pages[$index] = ['submitted_at' => now()->toIso8601String(), 'submitted_by' => $request->user()->id, 'review_status' => 'pending', 'feedback' => null, 'reviewed_at' => null, 'reviewed_by' => null];
                }
            }
            if ($work->exists && $before === $answers && $work->page_submissions == $pages && $request->input('status') === 'draft') {
                return $work;
            }
            $work->member_ids ??= $group->users()->pluck('users.id')->all();
            $work->fill(['answers' => $answers, 'page_submissions' => $pages, 'status' => LkpdPages::status($pages, $total), 'version' => $version, 'feedback' => null, 'product_scores' => null, 'presentation_scores' => null, 'score' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'submitted_at' => $request->input('status') === 'submitted' ? now() : ($pages ? $work->submitted_at : null)]);
            $work->save();
            $work->recordRevision($request->user());

            return $work;
        });

        if ($request->input('status') === 'submitted') {
            $sheet = $request->exists('page') ? ', lembar '.($request->integer('page') + 1) : '';
            LearningNotifier::send(LearningNotifier::members($group->classroom_id, 'teacher'), 'lkpd_submission', 'Kiriman LKPD '.($topic + 1).' masuk', $request->user()->name.' mengirim LKPD '.($topic + 1).$sheet.' untuk '.$group->name.'.', '/siswa/belajar?kegiatan=lkpd', 'GroupWork:'.$work->id.':'.$work->version, $group->classroom_id, '/guru/tinjauan/'.$request->user()->id.'?pertemuan='.($topic + 1));
        }

        return response()->json(['data' => $work->summary()]);
    }

    public function review(ReviewGroupWorkRequest $request, GroupWork $work): JsonResponse
    {
        $review = DB::transaction(function () use ($request, $work) {
            $target = GroupWork::whereKey($work->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $request->integer('version'), 409, 'LKPD telah diperbarui. Muat ulang sebelum menilai.');
            abort_if($target->status === 'draft', 422, 'Kelompok belum mengirimkan LKPD ini.');
            $pages = LkpdPages::entries($target);
            $total = (int) ceil(count($target->schema_snapshot ?? []) / LkpdPages::size($target));
            if ($request->exists('page')) {
                $indices = [$request->integer('page')];
                abort_unless(isset($pages[$indices[0]]), 422, 'Lembar ini belum dikirim murid.');
            } else {
                abort_if(count($pages) < $total, 422, 'LKPD belum lengkap. Tinjau lembar yang sudah dikirim terlebih dahulu.');
                $indices = array_keys($pages);
            }
            foreach ($indices as $index) {
                $pages[$index] = [...$pages[$index], 'feedback' => $request->validated('feedback'), 'review_status' => $request->validated('status'), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()->toIso8601String()];
            }
            $status = LkpdPages::status($pages, $total);
            $scores = $request->safe()->only(['product_scores', 'presentation_scores', 'score']);
            abort_if(count($pages) < $total && array_filter($scores), 422, 'Rubrik akhir diisi setelah seluruh lembar dikirim.');
            abort_if($request->filled('score') && ($status !== 'reviewed' || count($pages) < $total), 422, 'Nilai akhir diterbitkan setelah semua section selesai ditinjau.');
            $target->fill([...$scores, 'page_submissions' => $pages, 'status' => $status, 'feedback' => $request->exists('page') ? null : $request->validated('feedback'), 'reviewed_by' => $status === 'reviewed' ? $request->user()->id : null, 'reviewed_at' => $status === 'reviewed' ? now() : null, 'version' => $target->version + 1]);
            $target->save();
            $target->recordRevision($request->user());

            return $target;
        });

        $sheet = $request->exists('page') ? ' lembar '.($request->integer('page') + 1) : '';
        LearningNotifier::send($review->member_ids ?? $review->group->users()->pluck('users.id')->all(), 'feedback', 'Guru meninjau LKPD '.($review->topic + 1).$sheet, 'Baca apresiasi dan saran untuk pekerjaan kelompokmu.', '/siswa/belajar?kegiatan=lkpd&pertemuan='.($review->topic + 1).($request->exists('page') ? '&lembar='.($request->integer('page') + 1) : ''), 'GroupWork:'.$review->id.':'.$review->version, $review->group->classroom_id);

        return response()->json(['data' => $review->summary()]);
    }
}
