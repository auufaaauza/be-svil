<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateStudentAccountsRequest;
use App\Learning\LearningNotifier;
use App\Models\Classroom;
use App\Models\User;
use App\Role;
use App\StudentAccounts;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StudentAccountController extends Controller
{
    public function generate(GenerateStudentAccountsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $classroom = Classroom::findOrFail($data['classroom_id']);
        abort_unless($request->user()->canAccessClassroom($classroom->id), 403);
        abort_if($classroom->archived_at, 422, 'Pilih kelas aktif untuk membuat akun siswa.');
        $hash = hash('sha256', json_encode([$classroom->id, $data['students']], JSON_THROW_ON_ERROR));
        if (! $request->boolean('commit')) {
            $rows = StudentAccounts::plan($data['students']);
            $token = Crypt::encryptString(json_encode(['actor_id' => $request->user()->id, 'classroom_id' => $classroom->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'expires_at' => now()->addMinutes(15)->timestamp, 'rows' => $rows], JSON_THROW_ON_ERROR));

            return response()->json(['data' => ['rows' => $rows, 'count' => count($rows), 'initial_password' => StudentAccounts::DEFAULT_PASSWORD, 'preview_token' => $token]]);
        }
        try {
            [$accounts, $replayed] = DB::transaction(function () use ($request, $data, $classroom, $hash): array {
                $lockedClass = Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
                abort_unless($request->user()->canAccessClassroom($lockedClass->id), 403);
                abort_if($lockedClass->archived_at, 422, 'Kelas sudah diarsipkan.');
                $batch = DB::table('student_account_batches')->where('id', $data['request_id'])->first();
                if ($batch) {
                    abort_if($batch->actor_id !== $request->user()->id || $batch->classroom_id !== $classroom->id || ! hash_equals($batch->payload_hash, $hash), 409, 'Daftar permintaan berbeda. Siapkan pratinjau baru.');

                    return [json_decode($batch->accounts, true, flags: JSON_THROW_ON_ERROR), true];
                }
                try {
                    $preview = json_decode(Crypt::decryptString($data['preview_token']), true, flags: JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException $error) {
                    throw ValidationException::withMessages(['preview_token' => 'Pratinjau tidak valid. Siapkan ulang daftar akun.']);
                }
                abort_if(($preview['actor_id'] ?? null) !== $request->user()->id || ($preview['classroom_id'] ?? null) !== $classroom->id || ($preview['request_id'] ?? null) !== $data['request_id'] || ! hash_equals($preview['payload_hash'] ?? '', $hash) || ($preview['expires_at'] ?? 0) < now()->timestamp, 409, 'Pratinjau berubah atau kedaluwarsa. Siapkan ulang daftar akun.');
                $rows = $preview['rows'];
                abort_if(User::whereIn('username', array_column($rows, 'username'))->exists() || User::whereIn('email', array_filter(array_column($rows, 'email')))->exists(), 409, 'Username atau email baru saja digunakan. Siapkan ulang pratinjau untuk mendapatkan daftar terbaru.');
                $password = Hash::make(StudentAccounts::DEFAULT_PASSWORD);
                $accounts = [];
                foreach ($rows as $row) {
                    $student = User::create([...$row, 'password' => $password, 'role' => Role::Student, 'school_id' => $lockedClass->school_id, 'is_active' => true]);
                    $lockedClass->users()->attach($student);
                    $accounts[] = ['id' => $student->id, ...$row];
                }
                DB::table('student_account_batches')->insert(['id' => $data['request_id'], 'actor_id' => $request->user()->id, 'classroom_id' => $lockedClass->id, 'payload_hash' => $hash, 'accounts' => json_encode($accounts, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                LearningNotifier::audit($request->user()->id, 'student_accounts_generated', 'classroom', $lockedClass->id, ['request_id' => $data['request_id'], 'count' => count($accounts), 'student_ids' => array_column($accounts, 'id')]);

                return [$accounts, false];
            });
        } catch (UniqueConstraintViolationException $error) {
            abort(409, 'Daftar akun berubah saat diproses. Siapkan ulang pratinjau. Tidak ada akun yang dibuat sebagian.');
        }

        return response()->json(['data' => ['created' => array_map(fn (array $row): array => [...$row, 'initial_password' => StudentAccounts::DEFAULT_PASSWORD], $accounts), 'count' => count($accounts), 'replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
