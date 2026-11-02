<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewNoteRequest;
use App\Http\Requests\SaveNoteRequest;
use App\Models\LearningNote;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(LearningNote::query()->whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))->when($request->user()->role === Role::Student, fn ($q) => $q->where('student_id', $request->user()->id))->latest('updated_at')->paginate(100));
    }

    public function store(SaveNoteRequest $request): JsonResponse
    {
        $note = DB::transaction(function () use ($request) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $keys = [...$request->safe()->only(['classroom_id', 'topic', 'phase']), 'student_id' => $request->user()->id];
            $note = LearningNote::where($keys)->first();
            if ($note && $note->content === $request->validated('content')) {
                return $note;
            }$note ??= new LearningNote($keys);
            $note->fill(['content' => $request->validated('content'), 'feedback' => null, 'reviewed_by' => null, 'reviewed_at' => null])->save();

            return $note;
        });

        return response()->json(['data' => $note]);
    }

    public function review(ReviewNoteRequest $request, LearningNote $note): JsonResponse
    {
        $note = DB::transaction(function () use ($request, $note) {
            $target = LearningNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            abort_if($request->filled('content_hash') && ! hash_equals(hash('sha256', $target->content), $request->validated('content_hash')), 409, 'Siswa sudah memperbarui catatan. Muat ulang sebelum memberi tinjauan.');
            $target->update(['feedback' => $request->validated('feedback'), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

            return $target;
        });

        return response()->json(['data' => $note]);
    }
}
