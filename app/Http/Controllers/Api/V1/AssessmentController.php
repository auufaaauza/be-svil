<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAssessmentRequest;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CourseLesson;
use App\Models\QuestionBankItem;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public static function publicQuestions(array $questions): array
    {
        return array_map(fn ($q) => array_diff_key($q, array_flip(['correctIndex', 'modelAnswer', 'explanation', 'bank_item_id', 'bank_version'])), $questions);
    }

    private function present(Assessment $a, User $u): array
    {
        return [...$a->toArray(), 'questions' => $u->role === Role::Student ? self::publicQuestions($a->questions) : $a->questions, 'question_count' => count($a->questions)];
    }

    private function presentAttempt(AssessmentAttempt $a, User $u): array
    {
        $snapshot = $a->assessment_snapshot;

        return [...$a->toArray(), 'questions' => $u->role === Role::Student ? self::publicQuestions($snapshot['questions']) : $snapshot['questions'], 'title' => $snapshot['title'], 'assessment_version' => $snapshot['version'], 'normalized_score' => $a->raw_score === null ? null : round($a->raw_score / $a->max_score * 100, 2)];
    }

    public function index(Request $r): JsonResponse
    {
        $rows = Assessment::visibleTo($r->user())->with('lesson:id,title,course_id')->when($r->integer('classroom_id'), fn ($q) => $q->where('classroom_id', $r->integer('classroom_id')))->when($r->integer('lesson_id'), fn ($q) => $q->where('course_lesson_id', $r->integer('lesson_id')))->latest()->paginate(30);
        $rows->through(fn ($a) => $this->present($a, $r->user()));

        return response()->json($rows);
    }

    public function show(Request $r, Assessment $assessment): JsonResponse
    {
        abort_unless($assessment->canView($r->user()), 403);

        return response()->json(['data' => $this->present($assessment->load('lesson:id,title,course_id'), $r->user())]);
    }

    public function store(SaveAssessmentRequest $r): JsonResponse
    {
        $lesson = CourseLesson::findOrFail($r->integer('course_lesson_id'));
        $data = $r->safe()->except('version');
        abort_if($data['purpose'] === 'research' && $data['status'] === 'published', 422, 'Simpan draf dan tautkan persetujuan instrumen penelitian melalui admin dahulu.');
        $a = Assessment::create([...$data, 'classroom_id' => $lesson->course->classroom_id, 'created_by' => $r->user()->id, 'max_score' => array_sum(array_column($data['questions'], 'points'))]);

        return response()->json(['data' => $this->present($a, $r->user())], 201);
    }

    public function update(SaveAssessmentRequest $r, Assessment $assessment): JsonResponse
    {
        $saved = DB::transaction(function () use ($r, $assessment) {
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            abort_if($a->version !== $r->integer('version'), 409, 'Asesmen sudah berubah. Muat ulang.');
            abort_if($a->course_lesson_id !== $r->integer('course_lesson_id'), 422, 'Pertemuan asesmen tidak dapat dipindah.');
            if ($r->input('purpose') === 'research' && $r->input('status') === 'published') {
                $link = DB::table('research_assessments')->where('assessment_id', $a->id)->first();
                abort_unless($link && $link->assessment_hash === Assessment::questionHash($r->validated('questions')), 422, 'Butir ini belum ditautkan pada persetujuan penelitian.');
            }
            $a->update([...$r->safe()->except('version'), 'version' => $a->version + 1, 'max_score' => array_sum(array_column($r->validated('questions'), 'points'))]);

            return $a;
        });

        return response()->json(['data' => $this->present($saved, $r->user())]);
    }

    public function bank(Request $r): JsonResponse
    {
        return response()->json(QuestionBankItem::whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id'))->latest()->paginate(40));
    }

    public function saveBank(Request $r): JsonResponse
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $r->user()->accessibleClassrooms()->pluck('id'))], 'subject' => ['required', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:100'], 'question' => ['required', 'array'], ...SaveAssessmentRequest::questionRules('question.')]);
        $q = $data['question'];
        abort_if($q['type'] === 'choice' && (count($q['options']) !== 4 || ! isset($q['correctIndex'])), 422, 'Lengkapi empat opsi dan kunci.');
        abort_if($q['type'] === 'text' && (count($q['options']) || isset($q['correctIndex'])), 422, 'Uraian tidak memakai opsi atau kunci indeks.');

        return response()->json(['data' => QuestionBankItem::create([...$data, 'created_by' => $r->user()->id, 'cognitive_level' => $q['cognitive_level'] ?? null])], 201);
    }

    private function researchAccess(Assessment $assessment, User $student): void
    {
        if ($assessment->purpose !== 'research') {
            return;
        }
        abort_unless(DB::table('research_assessments as a')->join('research_studies as s', 's.id', '=', 'a.research_study_id')->join('research_enrollments as e', 'e.research_study_id', '=', 's.id')->where('a.assessment_id', $assessment->id)->where('e.student_id', $student->id)->where('e.classroom_id', $assessment->classroom_id)->where('s.status', 'active')->where('a.assessment_hash', Assessment::questionHash($assessment->questions))->exists(), 403, 'Pengukuran belum disahkan atau belum diaktifkan untuk peserta.');
    }

    public function updateBank(Request $r, QuestionBankItem $item): JsonResponse
    {
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($item->classroom_id), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'subject' => ['required', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:100'], 'question' => ['required', 'array'], ...SaveAssessmentRequest::questionRules('question.')]);
        $q = $data['question'];
        abort_if($q['type'] === 'choice' && (count($q['options']) !== 4 || ! isset($q['correctIndex'])), 422, 'Lengkapi empat opsi dan kunci.');
        abort_if($q['type'] === 'text' && (count($q['options']) || isset($q['correctIndex'])), 422, 'Uraian tidak memakai opsi atau kunci indeks.');
        $saved = DB::transaction(function () use ($item, $data, $q) {
            $row = QuestionBankItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($row->version !== $data['version'], 409, 'Bank soal sudah berubah. Muat ulang.');
            $row->update([...$data, 'cognitive_level' => $q['cognitive_level'] ?? null, 'version' => $row->version + 1]);

            return $row;
        });

        return response()->json(['data' => $saved]);
    }

    public function start(Request $r, Assessment $assessment): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $assessment->canView($r->user()), 403);
        $attempt = DB::transaction(function () use ($r, $assessment) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $a = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            abort_unless($a->status === 'published', 422, 'Asesmen belum dibuka.');
            $this->researchAccess($a, $r->user());
            $current = AssessmentAttempt::where('assessment_id', $a->id)->where('student_id', $r->user()->id)->where('status', 'in_progress')->latest('id')->first();
            if ($current) {
                return $current;
            }
            abort_if($a->opens_at && now()->lt($a->opens_at), 422, 'Asesmen belum masuk jadwal.');
            abort_if($a->closes_at && now()->gte($a->closes_at), 422, 'Waktu asesmen sudah ditutup.');
            if ($a->purpose === 'research') {
                abort_unless(DB::table('research_assessments as a')->join('research_studies as s', 's.id', '=', 'a.research_study_id')->join('research_enrollments as e', 'e.research_study_id', '=', 's.id')->where('a.assessment_id', $a->id)->where('e.student_id', $r->user()->id)->where('s.status', 'active')->exists(), 403, 'Pengukuran belum diaktifkan untuk peserta.');
            } else {
                abort_unless($a->lesson->status === 'open' && $a->lesson->course->status === 'published', 422, 'Pertemuan belum dibuka.');
            }
            $number = AssessmentAttempt::where('assessment_id', $a->id)->where('student_id', $r->user()->id)->count() + 1;
            abort_if($number > $a->max_attempts, 422, 'Batas percobaan sudah tercapai.');
            $expires = $a->duration_minutes ? now()->addMinutes($a->duration_minutes) : null;
            if ($a->closes_at && (! $expires || $a->closes_at->lt($expires))) {
                $expires = $a->closes_at;
            }

            return AssessmentAttempt::create(['assessment_id' => $a->id, 'student_id' => $r->user()->id, 'attempt_number' => $number, 'assessment_snapshot' => [...$a->only(['title', 'instructions', 'version', 'questions', 'purpose']), 'questions' => $a->questions], 'started_at' => now(), 'expires_at' => $expires, 'max_score' => $a->max_score, 'recorded_by' => $r->user()->id, 'answers' => []]);
        });

        return response()->json(['data' => $this->presentAttempt($attempt, $r->user())]);
    }

    private function answers(Request $r, array $questions): array
    {
        $keys = array_column($questions, 'id');
        $rules = ['answers' => ['required', 'array:'.implode(',', $keys)]];
        foreach ($questions as $q) {
            $rules['answers.'.$q['id']] = $q['type'] === 'choice' ? ['nullable', 'integer', 'between:0,3'] : ['nullable', 'string', 'max:6000'];
        }

        return $r->validate($rules)['answers'];
    }

    private function grade(array $questions, array $answers): array
    {
        $scores = [];
        foreach ($questions as $q) {
            $answer = $answers[$q['id']] ?? null;
            $scores[$q['id']] = $q['type'] === 'choice' ? (is_int($answer) && $answer === $q['correctIndex'] ? (float) $q['points'] : 0) : (trim((string) $answer) === '' ? 0 : null);
        }

return $scores;
    }

    private function finish(AssessmentAttempt $a, array $answers, string $requestId): void
    {
        $scores = $this->grade($a->assessment_snapshot['questions'], $answers);
        $pending = in_array(null, $scores, true);
        $a->update(['answers' => $answers, 'item_scores' => $scores, 'submit_request_id' => $requestId, 'submitted_at' => now(), 'status' => $pending ? 'pending_review' : 'graded', 'raw_score' => $pending ? null : array_sum($scores), 'version' => $a->version + 1]);
    }

    public function draft(Request $r, AssessmentAttempt $attempt): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $attempt->student_id === $r->user()->id && $attempt->assessment->canView($r->user()), 403);
        $answers = $this->answers($r, $attempt->assessment_snapshot['questions']);
        $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $saved = DB::transaction(function () use ($r, $attempt, $answers) {
            $a = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_if($a->version !== $r->integer('version'), 409, 'Jawaban berubah di perangkat lain.');
            abort_unless($a->status === 'in_progress', 422, 'Jawaban sudah dikirim.');
            abort_if($a->expires_at && now()->gt($a->expires_at), 422, 'Waktu menjawab sudah habis.');
            $a->update(['answers' => $answers, 'version' => $a->version + 1]);

            return $a;
        });

        return response()->json(['data' => $this->presentAttempt($saved, $r->user())]);
    }

    public function submit(Request $r, AssessmentAttempt $attempt): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $attempt->student_id === $r->user()->id, 403);
        $data = $r->validate(['request_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);
        $answers = $this->answers($r, $attempt->assessment_snapshot['questions']);
        $a = DB::transaction(function () use ($attempt, $data, $answers) {
            $a = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($a->submit_request_id === $data['request_id']) {
                return $a;
            }abort_unless($a->status === 'in_progress', 409, 'Jawaban sudah dikirim.');
            abort_if($a->version !== $data['version'], 409, 'Versi jawaban berubah. Muat ulang.');
            $expired = $a->expires_at && now()->gt($a->expires_at);
            $this->finish($a, $expired ? ($a->answers ?? []) : $answers, $data['request_id']);

            return $a;
        });

        return response()->json(['data' => $this->presentAttempt($a, $r->user())]);
    }

    public function attempts(Request $r): JsonResponse
    {
        $rows = AssessmentAttempt::visibleTo($r->user())->with('assessment:id,title,classroom_id,course_lesson_id')->when($r->integer('assessment_id'), fn ($q) => $q->where('assessment_id', $r->integer('assessment_id')))->latest()->paginate(40);
        $rows->through(fn ($a) => $this->presentAttempt($a, $r->user()));

        return response()->json($rows);
    }

    public function review(Request $r, AssessmentAttempt $attempt): JsonResponse
    {
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($attempt->assessment->classroom_id), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'scores' => ['required', 'array'], 'feedback' => ['required', 'string', 'max:3000']]);
        $a = DB::transaction(function () use ($r, $attempt, $data) {
            $a = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_if($a->version !== $data['version'], 409, 'Penilaian berubah. Muat ulang.');
            abort_if($a->status === 'in_progress', 422, 'Siswa belum mengirimkan jawaban.');
            $scores = $a->item_scores;
            foreach ($a->assessment_snapshot['questions'] as $q) {
                if ($q['type'] === 'text') {
                    abort_unless(array_key_exists($q['id'], $data['scores']) && is_numeric($data['scores'][$q['id']]) && $data['scores'][$q['id']] >= 0 && $data['scores'][$q['id']] <= $q['points'], 422, 'Skor uraian harus lengkap dan sesuai bobot.');
                    $scores[$q['id']] = (float) $data['scores'][$q['id']];
                } elseif (array_key_exists($q['id'], $data['scores'])) {
                    abort_if((float) $data['scores'][$q['id']] !== (float) $scores[$q['id']], 422, 'Nilai pilihan ganda ditentukan server.');
                }
            }
            $a->update(['item_scores' => $scores, 'raw_score' => array_sum($scores), 'status' => 'graded', 'feedback' => $data['feedback'], 'reviewed_by' => $r->user()->id, 'version' => $a->version + 1]);
            DB::table('assessment_reviews')->insert(['assessment_attempt_id' => $a->id, 'teacher_id' => $r->user()->id, 'version' => $a->version, 'snapshot' => json_encode($a->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $a;
        });

        return response()->json(['data' => $this->presentAttempt($a, $r->user())]);
    }

    public function offline(Request $r, Assessment $assessment): JsonResponse
    {
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($assessment->classroom_id), 403);
        $data = $r->validate(['student_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'offline_evidence' => ['required', 'string', 'max:3000'], 'submitted_at' => ['required', 'date', 'before_or_equal:now']]);
        $student = User::findOrFail($data['student_id']);
        abort_unless($student->role === Role::Student && $student->canAccessClassroom($assessment->classroom_id), 422, 'Siswa bukan anggota kelas.');
        $answers = $this->answers($r, $assessment->questions);
        $a = DB::transaction(function () use ($r, $assessment, $student, $data, $answers) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $retry = AssessmentAttempt::where('student_id', $student->id)->where('submit_request_id', $data['request_id'])->first();
            if ($retry) {
                abort_if($retry->assessment_id !== $assessment->id, 409, 'ID sudah dipakai.');

                return $retry;
            }$assessment = Assessment::whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assessment->canView($student), 403);
            $this->researchAccess($assessment, $student);
            abort_if(AssessmentAttempt::where('assessment_id', $assessment->id)->where('student_id', $student->id)->count() >= $assessment->max_attempts, 422, 'Batas percobaan sudah tercapai.');
            abort_if(AssessmentAttempt::where('assessment_id', $assessment->id)->where('student_id', $student->id)->where('status', 'in_progress')->exists(), 409, 'Siswa masih mempunyai percobaan daring yang berjalan.');
            $a = AssessmentAttempt::create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'attempt_number' => AssessmentAttempt::where('assessment_id',$assessment->id)->where('student_id',$student->id)->count() + 1, 'assessment_snapshot' => [...$assessment->only(['title', 'instructions', 'version', 'purpose']), 'questions' => $assessment->questions], 'source' => 'offline', 'offline_evidence' => $data['offline_evidence'], 'recorded_by' => $r->user()->id, 'started_at' => Carbon::parse($data['submitted_at']), 'max_score' => $assessment->max_score]);
            $this->finish($a,$answers,$data['request_id']);
            $a->update(['submitted_at' => Carbon::parse($data['submitted_at'])]);

            return $a;
        });

        return response()->json(['data' => $this->presentAttempt($a,$r->user())]);
    }
}
