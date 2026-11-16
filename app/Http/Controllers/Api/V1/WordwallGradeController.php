<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveWordwallGradeRequest;
use App\Learning\LearningNotifier;
use App\Models\Classroom;
use App\Models\User;
use App\Models\WordwallGrade;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WordwallGradeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id']]);
        abort_unless($request->user()->canAccessClassroom($request->integer('classroom_id')) && $request->user()->canUseSvil(), 403);

        return response()->json(WordwallGrade::where('classroom_id', $request->integer('classroom_id'))->when($request->user()->role === Role::Student, fn ($q) => $q->where('student_id', $request->user()->id))->with(['teacher:id,name', 'student:id,name,username'])->orderBy('student_id')->orderBy('game_id')->paginate(50))->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveWordwallGradeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $game = collect(json_decode(file_get_contents(database_path('data/lkpd-games.json')), true, flags: JSON_THROW_ON_ERROR))->firstWhere('id', $data['game_id']);
        $grade = DB::transaction(function () use ($request, $data, $game): WordwallGrade {
            $class = Classroom::whereKey($data['classroom_id'])->lockForUpdate()->firstOrFail();
            abort_if($class->archived_at, 422, 'Kelas arsip tidak dapat dinilai.');
            $student = User::findOrFail($data['student_id']);
            abort_unless($student->is_active && $student->canAccessClassroom($class->id) && $student->canUseSvil(), 422, 'Siswa bukan peserta aktif kelas ini.');
            $key = collect($data)->only(['classroom_id', 'student_id', 'game_id'])->all();
            $grade = WordwallGrade::where($key)->lockForUpdate()->first();
            abort_if(($grade?->version ?? 0) !== $data['version'], 409, 'Nilai sudah diperbarui. Muat nilai terbaru sebelum menyimpan.');
            $before = $grade?->toArray();
            $grade ??= new WordwallGrade($key);
            $grade->fill(['teacher_id' => $request->user()->id, 'game_title' => $game['title'], 'topic' => $game['topic'], 'score' => $data['score'], 'feedback' => $data['feedback'] ?? null, 'version' => ($grade->version ?? 0) + 1])->save();
            LearningNotifier::audit($request->user()->id, 'wordwall_grade_saved', 'wordwall_grade', $grade->id, ['source' => 'wordwall_manual', 'before' => $before, 'after' => $grade->toArray()]);
            LearningNotifier::send([$student->id], 'feedback', 'Nilai kuis dari guru', $game['title'], '/siswa/nilai', 'wordwall-grade:'.$grade->id.':'.$grade->version, $class->id);

            return $grade;
        });

        return response()->json(['data' => $grade->load(['teacher:id,name', 'student:id,name,username'])]);
    }
}
