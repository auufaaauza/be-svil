<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewAttemptRequest;
use App\Http\Requests\SubmitAttemptRequest;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['material_id' => ['nullable', 'uuid']]);
        $staff = $request->user()->role !== Role::Student;
        $attempts = MaterialAttempt::whereIn('material_id', Material::whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))->select('id'))
            ->when(! $staff, fn ($query) => $query->where('student_id', $request->user()->id))
            ->when($request->query('material_id'), fn ($query) => $query->where('material_id', $request->query('material_id')))
            ->with('material:id,title,topic,classroom_id')->latest()->paginate(50);
        $attempts->through(function (MaterialAttempt $attempt) use ($staff) {
            if ($staff) {
                $attempt->makeVisible('question_snapshot');
            }

            return $attempt;
        });

        return response()->json($attempts);
    }

    public function review(ReviewAttemptRequest $request, MaterialAttempt $attempt): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $attempt) {
            $target = MaterialAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_if($target->review_version !== $request->integer('version'), 409, 'Jawaban sudah ditinjau. Muat ulang sebelum mengubah tinjauan.');
            abort_if(($target->question_snapshot['type'] ?? '') === 'choice' && isset($target->question_snapshot['correctIndex']), 422, 'Jawaban berkunci sudah dinilai otomatis.');
            $target->update(['correct' => $request->validated('correct'), 'teacher_feedback' => $request->validated('feedback'), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_version' => $target->review_version + 1]);
            DB::table('attempt_reviews')->insert(['material_attempt_id' => $target->id, 'teacher_id' => $request->user()->id, 'version' => $target->review_version, 'correct' => $target->correct, 'feedback' => $target->teacher_feedback, 'created_at' => now()]);

            return $target;
        });

        return response()->json(['data' => $result]);
    }

    public function store(SubmitAttemptRequest $request, Material $material): JsonResponse
    {
        $attempt = DB::transaction(function () use ($request, $material) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = MaterialAttempt::where('student_id', $request->user()->id)->where('request_id', $request->validated('request_id'))->first();
            if ($existing) {
                abort_if($existing->material_id !== $material->id, 409, 'ID pengiriman sudah dipakai untuk materi lain.');

                return $existing;
            }
            $material = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            abort_if($material->version !== $request->integer('material_version'), 409, 'Materi berubah. Buka kembali materi sebelum menjawab.');
            $question = $material->question;
            abort_unless($question, 422, 'Materi ini tidak memiliki soal.');
            $choice = $request->input('choice');
            if ($question['type'] === 'choice' && (! is_int($choice) || ! array_key_exists($choice, $question['options']))) {
                throw ValidationException::withMessages(['choice' => ['Pilih salah satu jawaban.']]);
            }
            if ($question['type'] === 'text' && ! trim($request->input('answer', ''))) {
                throw ValidationException::withMessages(['answer' => ['Tuliskan jawabanmu terlebih dahulu.']]);
            }

            return MaterialAttempt::create([
                'material_id' => $material->id, 'student_id' => $request->user()->id,
                'request_id' => $request->validated('request_id'), 'material_version' => $material->version,
                'answer' => $question['type'] === 'choice' ? ['choice' => $choice] : ['text' => $request->validated('answer')],
                'question_snapshot' => $question,
                'correct' => $question['type'] === 'choice' && isset($question['correctIndex']) ? $choice === $question['correctIndex'] : null,
                'explanation' => $question['explanation'] ?? null,
            ]);
        });

        return response()->json(['data' => ['id' => $attempt->id, 'correct' => $attempt->correct, 'explanation' => $attempt->explanation, 'teacherFeedback' => $attempt->teacher_feedback, 'pendingReview' => $attempt->correct === null && $attempt->reviewed_at === null, 'message' => $attempt->reviewed_at ? 'Guru sudah memberikan tinjauan pada jawabanmu.' : ($attempt->correct === null ? 'Jawabanmu tersimpan dan menunggu tinjauan guru.' : ($attempt->correct ? 'Tepat! Kamu sudah memahami idenya.' : 'Belum tepat. Baca kembali ceritanya lalu coba lagi, ya.'))]]);
    }
}
