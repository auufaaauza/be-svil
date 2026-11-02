<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Learning\LearningNotifier;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReflectionController extends Controller
{
    public static function fields(int $topic): array
    {
        if ($topic === 0) {
            return ['feeling' => 'Hari ini aku merasa ... ketika mengetahui manfaat laut Indonesia.', 'gratitude' => 'Aku bersyukur atas kekayaan Indonesia karena ...', 'intention' => 'Tindakan sederhana yang ingin aku lakukan untuk menjaga lingkungan ...'];
        }
        if ($topic === 3) {
            return ['gratitude' => 'Hal yang paling aku syukuri dari kegiatan ini', 'helped' => 'Orang atau lingkungan yang terbantu dari aksi ini', 'obstacles' => 'Hambatan saat merencanakan atau melaksanakan aksi', 'next_step' => 'Perbaikan atau langkah selanjutnya', 'feeling' => 'Perasaanku setelah melakukan aksi dan satu kalimat dari hatiku', 'commitment' => 'Saya akan ..., mulai ..., dibuktikan dengan ...; hambatan dan cara mengatasinya ...'];
        }

        return ['gratitude' => 'Apa yang aku syukuri dari pembelajaran hari ini?', 'empathy' => 'Bagaimana aku memahami kehidupan orang lain dan alam?', 'commitment' => 'Kebaikan apa yang ingin aku lakukan, kapan dimulai dan bagaimana bukti pelaksanaannya?'];
    }

    public function index(Request $r): JsonResponse
    {
        $rows = DB::table('individual_reflections')->whereIn('classroom_id', $r->user()->accessibleClassrooms()->select('classrooms.id'))->when($r->user()->role === Role::Student, fn ($q) => $q->where('student_id', $r->user()->id))->latest('updated_at')->paginate(50);
        $rows->through(fn ($row) => [...(array) $row, 'answers' => json_decode($row->answers, true), 'schema_snapshot' => json_decode($row->schema_snapshot, true)]);

        return response()->json($rows);
    }

    public function schema(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($topic) => ['topic' => $topic, 'fields' => self::fields($topic), 'reference' => $topic === 0 ? 'LKPD 1 · F · Refleksi Hati' : ($topic === 3 ? 'LKPD 4 · G dan RPPM · Komitmen individu' : 'RPPM · Refleksi individu')], range(0, 3))]);
    }

    public function store(Request $r): JsonResponse
    {
        abort_unless($r->user()->role === Role::Student && $r->user()->canUseSvil(), 403);
        $base = $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())], 'topic' => ['required', 'integer', 'between:0,3'], 'version' => ['required', 'integer', 'min:0']]);
        $existing = DB::table('individual_reflections')->where('classroom_id', $base['classroom_id'])->where('student_id', $r->user()->id)->where('topic', $base['topic'])->first();
        $fields = $existing ? json_decode($existing->schema_snapshot, true) : self::fields($base['topic']);
        $rules = ['answers' => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $key => $label) {
            $rules['answers.'.$key] = ['required', 'string', 'max:4000'];
        }$data = $r->validate($rules);
        [$row, $changed] = DB::transaction(function () use ($r, $base, $fields, $data) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $keys = ['classroom_id' => $base['classroom_id'], 'student_id' => $r->user()->id, 'topic' => $base['topic']];
            $old = DB::table('individual_reflections')->where($keys)->first();
            abort_if(($old?->version ?? 0) !== $base['version'], 409, 'Ceritamu sudah berubah. Muat ulang terlebih dahulu.');
            if ($old && json_decode($old->answers, true) == $data['answers']) {
                return [$old, false];
            }
            DB::table('individual_reflections')->updateOrInsert($keys, ['answers' => json_encode($data['answers'], JSON_THROW_ON_ERROR), 'schema_snapshot' => json_encode($fields, JSON_THROW_ON_ERROR), 'version' => ($old?->version ?? 0) + 1, 'feedback' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            $row = DB::table('individual_reflections')->where($keys)->first();
            $this->revision($row, $r->user()->id);

            return [$row, true];
        });

        if ($changed) {
            LearningNotifier::send(LearningNotifier::members($row->classroom_id, 'teacher'), 'reflection_submission', 'Refleksi pribadi murid masuk', $r->user()->name.' mengisi refleksi pertemuan '.($row->topic + 1).'.', '/siswa/cerita', 'reflection-submitted:'.$row->id.':'.$row->version, $row->classroom_id, '/guru/tinjauan/'.$row->student_id.'?pertemuan='.($row->topic + 1));
        }

        return response()->json(['data' => $this->present($row), 'message' => 'Ceritamu tersimpan. Terima kasih sudah menulis dengan jujur.']);
    }

    private function present(object $row): array
    {
        return [...(array) $row, 'answers' => json_decode($row->answers, true, flags: JSON_THROW_ON_ERROR), 'schema_snapshot' => json_decode($row->schema_snapshot, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function revision(object $row, int $actor): void
    {
        DB::table('reflection_revisions')->insert(['individual_reflection_id' => $row->id, 'actor_id' => $actor, 'version' => $row->version, 'snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function review(Request $r, int $reflection): JsonResponse
    {
        $row = DB::table('individual_reflections')->find($reflection);
        abort_unless($row, 404);
        abort_unless($r->user()->role !== Role::Student && $r->user()->canAccessClassroom($row->classroom_id), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'feedback' => ['required', 'string', 'max:3000']]);
        $row = DB::transaction(function () use ($r, $reflection, $data) {
            $row = DB::table('individual_reflections')->where('id', $reflection)->lockForUpdate()->first();
            abort_if($row->version !== $data['version'], 409, 'Cerita sudah diperbarui.');
            DB::table('individual_reflections')->where('id', $reflection)->update(['feedback' => $data['feedback'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'version' => $row->version + 1, 'updated_at' => now()]);
            $row = DB::table('individual_reflections')->find($reflection);
            $this->revision($row, $r->user()->id);

            return $row;
        });

        LearningNotifier::send([$row->student_id], 'feedback', 'Gurumu membalas cerita pribadimu', 'Baca apresiasi dan dukungan dari gurumu.', '/siswa/cerita', 'reflection:'.$row->id.':'.$row->version, $row->classroom_id);

        return response()->json(['data' => $this->present($row)]);
    }
}
