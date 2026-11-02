<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiscussionReplyRequest;
use App\Http\Requests\StoreDiscussionRequest;
use App\Models\Discussion;
use App\Models\DiscussionReply;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscussionController extends Controller
{
    private function present(Discussion $discussion, Request $request): array
    {
        return [...$discussion->only(['id', 'classroom_id', 'title', 'content', 'status', 'version', 'created_at', 'updated_at']), 'author' => ['id' => $discussion->author->id, 'name' => $discussion->author->name], 'canEdit' => $request->user()->role === Role::Admin || $discussion->author_id === $request->user()->id, 'repliesCount' => $discussion->replies_count ?? 0];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['classroom_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:draft,open,closed']]);
        $items = Discussion::with('author:id,name')->whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))->when($request->user()->role === Role::Student, fn ($q) => $q->where('status', '!=', 'draft'))->when($request->query('classroom_id'), fn ($q) => $q->where('classroom_id', $request->integer('classroom_id')))->when($request->query('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->withCount(['replies' => fn ($q) => $q->when($request->user()->role === Role::Student, fn ($visible) => $visible->where('is_hidden', false))])->latest()->paginate(30);
        $items->through(fn (Discussion $discussion) => $this->present($discussion, $request));

        return response()->json($items);
    }

    public function show(Request $request, Discussion $discussion): JsonResponse
    {
        abort_unless($discussion->canView($request->user()), 403);

        return response()->json(['data' => $this->present($discussion->load('author')->loadCount(['replies' => fn ($q) => $q->when($request->user()->role === Role::Student, fn ($visible) => $visible->where('is_hidden', false))]), $request)]);
    }

    public function store(StoreDiscussionRequest $request): JsonResponse
    {
        $discussion = Discussion::create([...$request->safe()->except('version'), 'author_id' => $request->user()->id]);

        return response()->json(['data' => $this->present($discussion->load('author'), $request)], 201);
    }

    public function update(StoreDiscussionRequest $request, Discussion $discussion): JsonResponse
    {
        $saved = DB::transaction(function () use ($request, $discussion) {
            $target = Discussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            abort_if($target->version !== $request->integer('version'), 409, 'Diskusi berubah. Muat ulang terlebih dahulu.');
            abort_if($target->classroom_id !== $request->integer('classroom_id'), 422, 'Diskusi tidak dapat dipindah kelas.');
            $target->update([...$request->safe()->except('version'), 'version' => $target->version + 1]);

            return $target;
        });

        return response()->json(['data' => $this->present($saved->load('author'), $request)]);
    }

    public function replies(Request $request, Discussion $discussion): JsonResponse
    {
        abort_unless($discussion->canView($request->user()), 403);
        $staff = $request->user()->role !== Role::Student;
        $replies = $discussion->replies()->with('author:id,name,role')->when(! $staff, fn ($q) => $q->where('is_hidden', false))->orderBy('id')->paginate(50);
        $replies->through(fn (DiscussionReply $reply) => [...$reply->only(['id', 'content', 'is_hidden', 'created_at']), 'author' => ['id' => $reply->author_id, 'name' => $staff || $reply->author->role !== Role::Student ? $reply->author->name : 'Teman P-'.$reply->author_id, 'role' => $reply->author->role->value]]);

        return response()->json($replies);
    }

    public function reply(StoreDiscussionReplyRequest $request, Discussion $discussion): JsonResponse
    {
        $reply = DB::transaction(function () use ($request, $discussion) {
            $target = Discussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $existing = DiscussionReply::where('author_id', $request->user()->id)->where('request_id', $request->validated('request_id'))->first();
            if ($existing) {
                abort_if($existing->discussion_id !== $target->id, 409, 'ID kiriman sudah digunakan.');

                return $existing;
            }
            abort_unless($target->status === 'open', 422, 'Diskusi sudah ditutup. Kamu masih bisa membaca percakapannya.');

            return DiscussionReply::create([...$request->validated(), 'discussion_id' => $target->id, 'author_id' => $request->user()->id]);
        });

        return response()->json(['data' => ['id' => $reply->id]]);
    }

    public function moderate(Request $request, DiscussionReply $reply): JsonResponse
    {
        abort_unless($request->user()->role !== Role::Student && $reply->discussion->canView($request->user()), 403);
        $values = $request->validate(['is_hidden' => ['required', 'boolean']]);
        $reply->update([...$values, 'moderated_by' => $request->user()->id, 'moderated_at' => now()]);

        return response()->json(['message' => 'Status tanggapan diperbarui.']);
    }
}
