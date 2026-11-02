<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionPlan;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowupController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $plans = ActionPlan::whereHas('group', fn ($q) => $q->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id'))->when($r->user()->role === Role::Student, fn ($q) => $q->whereHas('users', fn ($q) => $q->whereKey($r->user()->id))))->with('group:id,name,classroom_id')->orderBy('start_date')->paginate(30);
        $plans->through(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'group' => $p->group, 'start_date' => $p->start_date->format('Y-m-d'), 'followup_on' => $p->start_date->copy()->addWeek()->format('Y-m-d'), 'verified_at' => $p->verified_at, 'records' => DB::table('action_followups')->where('action_plan_id', $p->id)->orderBy('id')->get()]);

        return response()->json($plans);
    }

    public function store(Request $r, ActionPlan $plan): JsonResponse
    {
        abort_unless($r->user()->role !== Role::Student && $plan->group->canView($r->user()), 403);
        $data = $r->validate(['kind' => ['required', 'in:guardian_support,follow_up'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'content' => ['required', 'string', 'max:5000'], 'source_reference' => ['required', 'string', 'max:3000']]);
        $id = DB::table('action_followups')->insertGetId([...$data, 'action_plan_id' => $plan->id, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id]], 201);
    }
}
