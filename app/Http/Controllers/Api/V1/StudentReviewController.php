<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssessmentAttempt;
use App\Models\AssignmentSubmission;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\LearningNote;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentReviewController extends Controller
{
    private function classroom(Request $r): int
    {
        $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())]]);

        return $r->integer('classroom_id');
    }

    public function index(Request $r): JsonResponse
    {
        $class = $this->classroom($r);
        $groups = LearningGroup::where('classroom_id', $class)->with('users:id')->get();
        $works = GroupWork::whereIn('learning_group_id', $groups->pluck('id'))->get(['id', 'learning_group_id', 'topic', 'status']);
        $notes = LearningNote::where('classroom_id', $class)->get(['student_id', 'topic', 'phase', 'reviewed_at']);
        $reflections = DB::table('individual_reflections')->where('classroom_id', $class)->get(['student_id', 'topic', 'reviewed_at']);
        $students = User::where('role', Role::Student)->whereIn('id', DB::table('classroom_user')->where('classroom_id', $class)->select('user_id'))->orderBy('name')->paginate(50);
        $students->through(function ($student) use ($groups, $works, $notes, $reflections) {
            $group = $groups->first(fn ($g) => $g->users->contains('id', $student->id));
            $summary = [];
            foreach (range(0, 3) as $topic) {
                $personal = $notes->where('student_id', $student->id)->where('topic', $topic);
                $work = $group ? $works->first(fn ($w) => $w->learning_group_id === $group->id && $w->topic === $topic) : null;
                $reflection = $reflections->first(fn ($n) => $n->student_id === $student->id && $n->topic === $topic);
                $summary[] = ['topic' => $topic, 'notes_filled' => $personal->count(), 'notes_reviewed' => $personal->whereNotNull('reviewed_at')->count(), 'group_status' => $work?->status ?? 'not_started', 'reflection_filled' => (bool) $reflection, 'reflection_reviewed' => (bool) $reflection?->reviewed_at];
            }

            return ['id' => $student->id, 'name' => $student->name, 'username' => $student->username, 'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null, 'lkpd' => $summary];
        });

        return response()->json($students);
    }

    public function show(Request $r, User $student): JsonResponse
    {
        $class = $this->classroom($r);
        abort_unless($student->role === Role::Student && DB::table('classroom_user')->where('classroom_id', $class)->where('user_id', $student->id)->exists(), 403);
        $group = LearningGroup::where('classroom_id', $class)->whereHas('users', fn ($q) => $q->whereKey($student->id))->with('users:id,name')->first();
        $notes = LearningNote::where('classroom_id', $class)->where('student_id', $student->id)->orderBy('topic')->orderBy('phase')->get()->map(fn ($n) => [...$n->toArray(), 'content_hash' => hash('sha256', $n->content)]);
        $reflections = DB::table('individual_reflections')->where('classroom_id', $class)->where('student_id', $student->id)->get()->map(fn ($n) => [...(array) $n, 'answers' => json_decode($n->answers, true), 'schema_snapshot' => json_decode($n->schema_snapshot, true)]);
        $works = GroupWork::whereHas('group', fn ($q) => $q->where('classroom_id', $class))->where(fn ($q) => $q->whereJsonContains('member_ids', $student->id)->when($group, fn ($q) => $q->orWhere('learning_group_id', $group->id)))->with('group:id,name')->orderBy('topic')->get()->map(fn ($w) => [...$w->summary(), 'groupName' => $w->group->name]);

        return response()->json(['data' => ['student' => ['id' => $student->id, 'name' => $student->name, 'username' => $student->username], 'classroom_id' => $class, 'group' => $group, 'notes' => $notes, 'reflections' => $reflections, 'works' => $works, 'modules' => LearningModule::orderBy('topic')->get(['id', 'topic', 'title']), 'submissions' => AssignmentSubmission::whereHas('assignment', fn ($q) => $q->where('classroom_id', $class))->whereJsonContains('member_ids', $student->id)->with('assignment:id,title,classroom_id')->latest()->get(), 'assessment_attempts' => AssessmentAttempt::whereHas('assessment', fn ($q) => $q->where('classroom_id', $class))->where('student_id', $student->id)->latest()->get()]]);
    }
}
