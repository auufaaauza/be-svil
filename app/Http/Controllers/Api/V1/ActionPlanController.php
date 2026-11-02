<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveActionPlanRequest;
use App\Http\Requests\StoreActionJournalRequest;
use App\Http\Requests\VerifyActionPlanRequest;
use App\Models\ActionJournal;
use App\Models\ActionPlan;
use App\Models\LearningGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActionPlanController extends Controller
{
    public function show(Request $request, LearningGroup $group): JsonResponse
    {
        abort_unless($group->canView($request->user()) && $request->user()->canUseSvil(), 403);

        return response()->json(['data' => ActionPlan::where('learning_group_id', $group->id)->with('journals')->first()]);
    }

    public function store(SaveActionPlanRequest $request, LearningGroup $group): JsonResponse
    {
        $plan = DB::transaction(function () use ($request, $group) {
            LearningGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $plan = ActionPlan::where('learning_group_id', $group->id)->first();
            abort_if(($plan?->version ?? 0) !== $request->integer('version'), 409, 'Rencana aksi telah berubah. Muat ulang datanya.');
            $next = ($plan?->version ?? 0) + 1;
            $plan ??= new ActionPlan(['learning_group_id' => $group->id]);
            $plan->fill([...$request->safe()->except('version'), 'version' => $next, 'verified_at' => null, 'verified_by' => null, 'teacher_feedback' => null]);
            $plan->save();

            return $plan;
        });

        return response()->json(['data' => $plan->load('journals')]);
    }

    public function journal(StoreActionJournalRequest $request, ActionPlan $plan): JsonResponse
    {
        $journal = DB::transaction(function () use ($request, $plan) {
            $target = ActionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $existing = ActionJournal::where('student_id', $request->user()->id)->where('request_id', $request->validated('request_id'))->first();
            if ($existing) {
                abort_if($existing->action_plan_id !== $plan->id, 409, 'ID pengiriman telah digunakan untuk aksi lain.');

                return $existing;
            }
            abort_if($request->validated('occurred_on') < $target->start_date->format('Y-m-d'), 422, 'Tanggal jurnal tidak boleh mendahului rencana aksi.');
            $journal = ActionJournal::create([...$request->validated(), 'action_plan_id' => $plan->id, 'student_id' => $request->user()->id, 'plan_version' => $target->version, 'plan_snapshot' => $target->only(['title', 'start_date', 'location', 'indicator', 'steps', 'responsibilities', 'evidence_method'])]);
            $target->update(['version' => $target->version + 1, 'verified_at' => null, 'verified_by' => null, 'teacher_feedback' => null]);

            return $journal;
        });

        return response()->json(['data' => $journal]);
    }

    public function verify(VerifyActionPlanRequest $request, ActionPlan $plan): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $plan) {
            $target = ActionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $request->integer('version'), 409, 'Jurnal atau rencana telah berubah. Muat ulang sebelum memverifikasi.');
            abort_unless($target->journals()->exists(), 422, 'Belum ada jurnal pelaksanaan yang dapat diverifikasi.');
            $target->update(['version' => $target->version + 1, 'verified_at' => now(), 'verified_by' => $request->user()->id, 'teacher_feedback' => $request->validated('feedback')]);

            return $target;
        });

        return response()->json(['data' => $result->load('journals')]);
    }
}
