<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\ResearchInstrument;
use App\Models\ResearchStudy;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResearchController extends Controller
{
    private function access(Request $r, ResearchStudy $study): void
    {
        if ($r->user()->role === Role::Admin) {
            return;
        }
        $q = DB::table('research_enrollments')->where('research_study_id', $study->id);
        if ($r->user()->role === Role::Student) {
            $q->where('student_id', $r->user()->id);
        } else {
            $q->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id'));
        }
        abort_unless($q->exists(), 403);
    }

    public function studies(Request $r): JsonResponse
    {
        $q = ResearchStudy::query();
        if ($r->user()->role !== Role::Admin) {
            $q->whereIn('id', DB::table('research_enrollments')->when($r->user()->role === Role::Student, fn ($q) => $q->where('student_id', $r->user()->id), fn ($q) => $q->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id')))->select('research_study_id'));
        }

        return response()->json($q->latest()->paginate(20));
    }

    public function createStudy(Request $r): JsonResponse
    {
        $data = $r->validate(['title' => ['required', 'string', 'max:180'], 'stage' => ['required', 'in:limited,expanded,effectiveness'], 'protocol_reference' => ['required', 'string', 'max:3000']]);

        return response()->json(['data' => ResearchStudy::create([...$data, 'created_by' => $r->user()->id, 'status' => 'draft'])], 201);
    }

    public function updateStudy(Request $r, ResearchStudy $study): JsonResponse
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:draft,active,closed'], 'protocol_reference' => ['required', 'string', 'max:3000']]);
        $result = DB::transaction(function () use ($study, $data) {
            $s = ResearchStudy::whereKey($study->id)->lockForUpdate()->firstOrFail();
            abort_if($s->version !== $data['version'], 409, 'Tahap riset berubah. Muat ulang.');
            if ($data['status'] === 'active') {
                $participants = DB::table('research_enrollments')->where('research_study_id', $s->id)->pluck('student_id');
                abort_if($participants->isEmpty(), 422, 'Tetapkan peserta dan kelompok riset dahulu.');
                $overlap = DB::table('research_enrollments as e')->join('research_studies as s', 's.id', '=', 'e.research_study_id')->whereIn('e.student_id', $participants)->where('s.status', 'active')->where('s.id', '!=', $s->id)->exists();
                abort_if($overlap, 422, 'Peserta masih mengikuti tahap riset aktif lain.');
            }$s->update([...$data, 'version' => $s->version + 1]);

            return $s;
        });

        return response()->json(['data' => $result]);
    }

    public function enrollments(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);
        $q = DB::table('research_enrollments')->where('research_study_id', $study->id)->when($r->user()->role === Role::Teacher, fn ($q) => $q->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id')))->when($r->user()->role === Role::Student, fn ($q) => $q->where('student_id', $r->user()->id));

        return response()->json(['data' => $q->get()]);
    }

    public function enroll(Request $r, ResearchStudy $study): JsonResponse
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id'], 'arm' => ['required', 'in:control,experimental'], 'student_ids' => ['nullable', 'array', 'max:200'], 'student_ids.*' => ['integer', 'distinct']]);
        $students = User::where('role', Role::Student)->whereHas('classrooms', fn ($q) => $q->whereKey($data['classroom_id']))->when($data['student_ids'] ?? null, fn ($q) => $q->whereIn('id', $data['student_ids']))->get();
        abort_if($students->isEmpty() || (isset($data['student_ids']) && count($data['student_ids']) !== $students->count()), 422, 'Daftar peserta harus berisi siswa kelas yang dipilih.');
        DB::transaction(function () use ($study, $students, $data) {
            $s = ResearchStudy::whereKey($study->id)->lockForUpdate()->firstOrFail();
            abort_if($s->status === 'closed', 422, 'Tahap sudah ditutup.');
            $existing = DB::table('research_enrollments')->where('research_study_id', $s->id)->where('classroom_id', $data['classroom_id'])->first();
            abort_if($existing && $existing->arm !== $data['arm'], 422, 'Satu kelas memakai satu kelompok riset pada tahap ini.');
            foreach ($students as $student) {
                $old = DB::table('research_enrollments')->where('research_study_id', $s->id)->where('student_id', $student->id)->first();
                abort_if($old && $old->classroom_id !== $data['classroom_id'], 422, 'Peserta sudah terdaftar melalui kelas lain.');
                if ($s->status === 'active') {
                    abort_if(DB::table('research_enrollments as e')->join('research_studies as s', 's.id', '=', 'e.research_study_id')->where('e.student_id', $student->id)->where('s.status', 'active')->where('s.id', '!=', $study->id)->exists(), 422, 'Peserta memiliki tahap aktif lain.');
                }DB::table('research_enrollments')->updateOrInsert(['research_study_id' => $s->id, 'student_id' => $student->id], ['classroom_id' => $data['classroom_id'], 'arm' => $data['arm'], 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => $students->count().' peserta ditetapkan.']);
    }

    public function instruments(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);

        return response()->json(ResearchInstrument::where('research_study_id', $study->id)->when($r->user()->role !== Role::Admin, fn ($q) => $q->where('status', 'approved'))->latest()->paginate(30));
    }

    public function instrument(Request $r, ResearchStudy $study): JsonResponse
    {
        $data = $r->validate(['title' => ['required', 'string', 'max:180'], 'kind' => ['required', 'in:love,practicality,implementation,interview,validity'], 'status' => ['required', 'in:draft,approved'], 'approval_reference' => ['required_if:status,approved', 'nullable', 'string', 'max:3000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:id,label,type,min,max,dimension,reverse,low_label,high_label'], 'items.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:60', 'distinct'], 'items.*.label' => ['required', 'string', 'max:2000'], 'items.*.type' => ['required', 'in:scale,text'], 'items.*.min' => ['nullable', 'integer', 'between:0,10'], 'items.*.max' => ['nullable', 'integer', 'between:1,10'], 'items.*.dimension' => ['required', 'string', 'max:80'], 'items.*.reverse' => ['sometimes', 'boolean'], 'items.*.low_label' => ['nullable', 'string', 'max:100'], 'items.*.high_label' => ['nullable', 'string', 'max:100'], 'previous_instrument_id' => ['nullable', 'integer'], 'target_role' => ['sometimes', 'in:student,teacher,both'], 'period' => ['sometimes', 'in:pre,post,feedback'], 'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date', Rule::when($r->filled('opens_at'), 'after:opens_at')]]);
        foreach ($data['items'] as $item) {
            abort_if($item['type'] === 'scale' && (! isset($item['min'],$item['max']) || $item['max'] <= $item['min']), 422, 'Butir skala memerlukan batas bawah dan atas yang sesuai.');
        }
        if ($data['kind'] === 'love' && $data['status'] === 'approved') {
            $dimensions = array_unique(array_column($data['items'], 'dimension'));
            foreach (['empathy', 'respect', 'social_care', 'social_responsibility', 'ecological_care', 'moral_awareness'] as $key) {
                abort_unless(in_array($key, $dimensions, true), 422, 'Lengkapi enam dimensi nilai cinta sesuai blueprint peneliti.');
            }
        }
        $previous = null;
        if (isset($data['previous_instrument_id'])) {
            $previous = ResearchInstrument::where('research_study_id', $study->id)->findOrFail($data['previous_instrument_id']);
        }
        $item = ResearchInstrument::create([...$data, 'research_study_id' => $study->id, 'created_by' => $r->user()->id, 'version' => $previous ? $previous->version + 1 : 1]);

        return response()->json(['data' => $item], 201);
    }

    public function respond(Request $r, ResearchInstrument $instrument): JsonResponse
    {
        $study = ResearchStudy::findOrFail($instrument->research_study_id);
        $this->access($r, $study);
        abort_unless($study->status === 'active' && $instrument->status === 'approved', 422, 'Instrumen belum aktif atau belum disahkan.');
        abort_if(($instrument->target_role ?? 'both') !== 'both' && $instrument->target_role !== $r->user()->role->value, 403);
        abort_if($instrument->opens_at && now()->lt($instrument->opens_at), 422, 'Instrumen belum masuk jadwal.');
        abort_if($instrument->closes_at && now()->gte($instrument->closes_at), 422, 'Jadwal pengisian sudah berakhir.');
        abort_if(in_array($instrument->kind, ['validity', 'interview', 'implementation']) && $r->user()->role === Role::Student, 403);
        $rules = ['request_id' => ['required', 'uuid'], 'period' => ['required', 'in:pre,post,feedback'], 'answers' => ['required', 'array:'.implode(',', array_column($instrument->items, 'id'))]];
        foreach ($instrument->items as $item) {
            $rules['answers.'.$item['id']] = $item['type'] === 'scale' ? ['required', 'integer', 'between:'.$item['min'].','.$item['max']] : ['required', 'string', 'max:5000'];
        }
        $data = $r->validate($rules);
        abort_if($data['period'] !== ($instrument->period ?? 'feedback'), 422, 'Periode respons tidak sesuai jadwal instrumen.');
        $scores = [];
        foreach ($instrument->items as $item) {
            if ($item['type'] === 'scale') {
                $value = $data['answers'][$item['id']];
                $scored = ($item['reverse'] ?? false) ? $item['min'] + $item['max'] - $value : $value;
                $scores[$item['dimension']] = ($scores[$item['dimension']] ?? 0) + $scored;
            }
        }
        $record = DB::transaction(function () use ($r, $instrument, $data, $scores) {
            $lockedStudy = ResearchStudy::whereKey($instrument->research_study_id)->lockForUpdate()->firstOrFail();
            $locked = ResearchInstrument::whereKey($instrument->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedStudy->status === 'active' && $locked->status === 'approved', 422, 'Pengisian instrumen sudah ditutup.');
            $retry = DB::table('research_responses')->where('respondent_id', $r->user()->id)->where('request_id', $data['request_id'])->first();
            if ($retry) {
                abort_if($retry->research_instrument_id !== $instrument->id || $retry->period !== $data['period'], 409, 'ID pengiriman sudah digunakan.');

                return $retry;
            }$existing = DB::table('research_responses')->where('research_instrument_id', $instrument->id)->where('respondent_id', $r->user()->id)->where('period', $data['period'])->exists();
            abort_if($existing, 409, 'Respons periode ini sudah disimpan.');
            $id = DB::table('research_responses')->insertGetId(['research_instrument_id' => $instrument->id, 'respondent_id' => $r->user()->id, 'period' => $data['period'], 'instrument_snapshot' => json_encode($instrument->toArray(), JSON_THROW_ON_ERROR), 'answers' => json_encode($data['answers'], JSON_THROW_ON_ERROR), 'scores' => json_encode($scores, JSON_THROW_ON_ERROR), 'request_id' => $data['request_id'], 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('research_responses')->find($id);
        });

        return response()->json(['data' => ['id' => $record->id, 'message' => 'Respons tersimpan. Terima kasih sudah menjawab dengan jujur.']]);
    }

    public function responses(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);
        $q = DB::table('research_responses as r')->join('research_instruments as i', 'i.id', '=', 'r.research_instrument_id')->where('i.research_study_id', $study->id)->select('r.*', 'i.title', 'i.kind');
        if ($r->user()->role === Role::Student) {
            $q->where('r.respondent_id', $r->user()->id);
        } elseif ($r->user()->role === Role::Teacher) {
            $ids = User::where('role', Role::Student)->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $r->user()->accessibleClassrooms()->select('classrooms.id')))->pluck('id')->push($r->user()->id);
            $q->whereIn('r.respondent_id', $ids);
        }

        return response()->json($q->latest('r.id')->paginate(50));
    }

    public function linkAssessment(Request $r, ResearchStudy $study): JsonResponse
    {
        $data = $r->validate(['assessment_id' => ['required', 'integer', 'exists:assessments,id'], 'period' => ['required', 'in:pre,post'], 'approval_reference' => ['required', 'string', 'max:3000']]);
        $a = Assessment::findOrFail($data['assessment_id']);
        abort_unless($a->purpose === 'research', 422, 'Gunakan asesmen bertujuan penelitian.');
        abort_if(AssessmentAttempt::where('assessment_id', $a->id)->exists(), 422, 'Asesmen yang sudah dikerjakan tidak dapat ditautkan ulang.');
        abort_unless(DB::table('research_enrollments')->where('research_study_id', $study->id)->where('classroom_id', $a->classroom_id)->exists(), 422, 'Kelas asesmen belum terdaftar dalam tahap riset.');
        $existing = DB::table('research_assessments')->where('assessment_id', $a->id)->first();
        abort_if($existing && ($existing->research_study_id !== $study->id || $existing->period !== $data['period']), 422, 'Asesmen sudah dipakai untuk tahap atau periode lain.');
        DB::table('research_assessments')->updateOrInsert(['research_study_id' => $study->id, 'assessment_id' => $a->id], ['period' => $data['period'], 'approval_reference' => $data['approval_reference'], 'assessment_hash' => Assessment::questionHash($a->questions), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Asesmen ditautkan ke periode penelitian.']);
    }

    public function records(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);
        abort_if($r->user()->role === Role::Student, 403);

        return response()->json(DB::table('research_records')->where('research_study_id', $study->id)->when($r->user()->role === Role::Teacher, fn ($q) => $q->where('recorded_by', $r->user()->id))->latest('id')->paginate(30));
    }

    public function record(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);
        abort_if($r->user()->role === Role::Student, 403);
        $data = $r->validate(['kind' => ['required', 'in:expert_review,field_note,interview,revision,guardian_support,follow_up'], 'participant_code' => ['nullable', 'string', 'max:100'], 'external_role' => ['nullable', 'string', 'max:100'], 'source_reference' => ['required', 'string', 'max:3000'], 'content' => ['required', 'string', 'max:15000'], 'data' => ['nullable', 'array']]);
        $id = DB::table('research_records')->insertGetId([...$data, 'data' => isset($data['data']) ? json_encode($data['data'], JSON_THROW_ON_ERROR) : null, 'research_study_id' => $study->id, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    private function dataset(ResearchStudy $study): array
    {
        $participants = DB::table('research_enrollments as e')->join('classrooms as c', 'c.id', '=', 'e.classroom_id')->where('e.research_study_id', $study->id)->select('e.student_id', 'e.classroom_id', 'e.arm', 'c.school_id')->get();
        $links = DB::table('research_assessments')->where('research_study_id', $study->id)->get();
        $attempts = AssessmentAttempt::whereIn('assessment_id', $links->pluck('assessment_id'))->whereIn('student_id', $participants->pluck('student_id'))->whereNotNull('submitted_at')->orderBy('id')->get();
        $responses = DB::table('research_responses')->whereIn('research_instrument_id', ResearchInstrument::where('research_study_id', $study->id)->select('id'))->get();
        $pairs = $participants->map(function ($p) use ($links, $attempts) {
            $get = function ($period) use ($p, $links, $attempts) {
                $ids = $links->where('period', $period)->whereIn('assessment_id', $attempts->where('student_id', $p->student_id)->pluck('assessment_id'))->pluck('assessment_id');

                return $attempts->where('student_id', $p->student_id)->whereIn('assessment_id', $ids)->last();
            };
            $pre = $get('pre');
            $post = $get('post');

            return ['participant' => 'P-'.$p->student_id, 'classroom_id' => $p->classroom_id, 'school_id' => $p->school_id, 'arm' => $p->arm, 'pre_score' => $pre?->raw_score, 'pre_maximum' => $pre?->max_score, 'pre_attempt_id' => $pre?->id, 'post_score' => $post?->raw_score, 'post_maximum' => $post?->max_score, 'post_attempt_id' => $post?->id, 'pair_complete' => $pre?->status === 'graded' && $post?->status === 'graded'];
        });
        $cleanRecord = fn ($row) => [...collect((array) $row)->except('recorded_by')->all(), 'recorded_by' => 'U-'.$row->recorded_by];

        return ['generated_at' => now()->toIso8601String(), 'study' => $study->toArray(), 'dictionary' => ['P' => 'Kode peserta stabil; bukan nama atau email.', 'scores' => 'Skor mentah dan maksimum; komponen penilaian tetap terpisah.', 'missing' => 'null berarti belum tersedia/ditinjau, bukan nol.', 'analysis' => 'Perbandingan instrumen, validitas, reliabilitas dan analisis efektivitas ditetapkan peneliti.', 'free_text' => 'Tinjau nama atau data pribadi yang mungkin diketik pada teks bebas sebelum dibagikan.'], 'participants' => $pairs->values()->all(), 'assessments' => $links->all(), 'assessment_answers' => $attempts->map(fn ($a) => [...$a->makeVisible('assessment_snapshot')->toArray(), 'student_id' => 'P-'.$a->student_id, 'recorded_by' => 'U-'.$a->recorded_by])->all(), 'survey_answers' => $responses->map(function ($row) {
            $data = (array) $row;
            $data['respondent_id'] = 'P-'.$row->respondent_id;
            unset($data['request_id']);

            return $data;
        })->all(), 'records' => DB::table('research_records')->where('research_study_id', $study->id)->get()->map($cleanRecord)->all()];
    }

    public function summary(Request $r, ResearchStudy $study): JsonResponse
    {
        $this->access($r, $study);
        abort_if($r->user()->role !== Role::Admin, 403);
        $data = $this->dataset($study);

        return response()->json(['data' => ['participants' => count($data['participants']), 'complete_pairs' => count(array_filter($data['participants'], fn ($p) => $p['pair_complete'])), 'assessment_answers' => count($data['assessment_answers']), 'survey_answers' => count($data['survey_answers']), 'records' => count($data['records']), 'pairs' => $data['participants']]]);
    }

    public function export(Request $r, ResearchStudy $study): JsonResponse
    {
        $data = DB::transaction(fn () => $this->dataset($study));
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $path = 'research-exports/'.$id.'.json';
        abort_unless(Storage::disk('local')->put($path, $content), 500, 'Snapshot ekspor belum tersimpan.');
        $counts = ['participants' => count($data['participants']), 'complete_pairs' => count(array_filter($data['participants'], fn ($p) => $p['pair_complete'])), 'assessment_answers' => count($data['assessment_answers']), 'survey_answers' => count($data['survey_answers']), 'records' => count($data['records'])];
        try {
            DB::table('research_exports')->insert(['id' => $id, 'research_study_id' => $study->id, 'created_by' => $r->user()->id, 'file_path' => $path, 'sha256' => hash('sha256', $content), 'counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

return response()->json(['data' => ['id' => $id, 'sha256' => hash('sha256', $content), 'counts' => $counts, 'download_url' => route('research-exports.download', $id, false)]], 201);
    }

    public function exports(Request $r,ResearchStudy $study): JsonResponse
    {
        return response()->json(DB::table('research_exports')->where('research_study_id',$study->id)->select('id','sha256','counts','created_at')->latest('created_at')->paginate(20));
    }

    public function download(Request $r,string $export): StreamedResponse
    {
        $row = DB::table('research_exports')->where('id',$export)->first();
        abort_unless($row,404);
        abort_unless(Storage::disk('local')->exists($row->file_path),404);

        return Storage::disk('local')->download($row->file_path,'svil-riset-'.$row->id.'.json',['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
