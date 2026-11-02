<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewGroupWorkRequest;
use App\Http\Requests\SaveGroupWorkRequest;
use App\Learning\LkpdSchema;
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

        $module = LearningModule::where('topic', $topic)->firstOrFail();
        abort_if($request->user()->role === Role::Student && $module->status !== 'published' && ! $work, 403);

        return response()->json(['data' => $work?->summary(), 'fields' => $work?->schema_snapshot ?? $module->fields, 'module' => [...$module->mediaFor($group->classroom_id), 'title' => $module->title, 'instructions' => $module->instructions, 'version' => $work?->module_version ?? $module->version], 'rubrics' => LkpdSchema::rubrics(), 'guidance' => LkpdSchema::guidance()]);
    }

    public function store(SaveGroupWorkRequest $request, LearningGroup $group, int $topic): JsonResponse
    {
        $work = DB::transaction(function () use ($request, $group, $topic) {
            LearningGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $work = GroupWork::where('learning_group_id', $group->id)->where('topic', $topic)->first();
            abort_if(($work?->version ?? 0) !== $request->integer('version'), 409, 'LKPD telah berubah. Muat ulang sebelum melanjutkan.');
            abort_if($work?->member_ids && ! in_array($request->user()->id, $work->member_ids, true), 422, 'Anggota LKPD ini sudah dibekukan. Gunakan kelompok baru untuk kegiatan baru.');
            $version = ($work?->version ?? 0) + 1;
            $work ??= new GroupWork(['learning_group_id' => $group->id, 'topic' => $topic]);
            if (! $work->schema_snapshot) {
                $work->fill(['schema_snapshot' => $request->moduleSchema()['fields'], 'module_version' => $request->moduleSchema()['version']]);
            }
            $work->member_ids ??= $group->users()->pluck('users.id')->all();
            $work->fill(['answers' => $request->validated('answers'), 'status' => $request->validated('status'), 'version' => $version, 'feedback' => null, 'product_scores' => null, 'presentation_scores' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'submitted_at' => $request->input('status') === 'submitted' ? now() : null]);
            $work->save();
            $work->recordRevision($request->user());

            return $work;
        });

        return response()->json(['data' => $work->summary()]);
    }

    public function review(ReviewGroupWorkRequest $request, GroupWork $work): JsonResponse
    {
        $review = DB::transaction(function () use ($request, $work) {
            $target = GroupWork::whereKey($work->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $request->integer('version'), 409, 'LKPD telah diperbarui. Muat ulang sebelum menilai.');
            abort_if($target->status === 'draft', 422, 'Kelompok belum mengirimkan LKPD ini.');
            $target->fill([...$request->safe()->except('version'), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'version' => $target->version + 1]);
            $target->save();
            $target->recordRevision($request->user());

            return $target;
        });

        return response()->json(['data' => $review->summary()]);
    }
}
