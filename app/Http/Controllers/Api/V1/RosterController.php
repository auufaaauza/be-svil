<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\User;
use App\PasswordPolicy;
use App\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RosterController extends Controller
{
    public function import(Request $r): JsonResponse
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:512'], 'commit' => ['sometimes', 'boolean']]);
        $classroom = Classroom::findOrFail($data['classroom_id']);
        $file = fopen($r->file('file')->getRealPath(), 'r');
        $first = fgets($file);
        abort_unless($first !== false, 422, 'CSV kosong.');
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($file);
        $headers = fgetcsv($file, escape: '', separator: $delimiter);
        $headers = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), $headers);
        abort_unless(count(array_intersect(['name', 'username', 'email'], $headers)) === 3, 422, 'Header CSV harus memuat name,username,email; password opsional.');
        $rows = [];
        $seenNames = [];
        $seenEmails = [];
        $number = 1;
        while (($values = fgetcsv($file, escape: '', separator: $delimiter)) !== false) {
            $number++;
            if (count($values) === 1 && trim($values[0] ?? '') === '') {
                continue;
            }abort_if(count($rows) >= 500, 422, 'Maksimum 500 siswa setiap impor.');
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = trim($values[$i] ?? '');
            }$row['username'] = Str::lower($row['username']);
            $row['email'] = Str::lower($row['email']);
            $password = $row['password'] ?? '';
            $rules = ['name' => ['required', 'string', 'max:120'], 'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:users,username'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']];
            if ($password !== '') {
                $rules['password'] = PasswordPolicy::rules(false);
            }$validator = Validator::make($row, $rules);
            $errors = $validator->errors()->all();
            if (isset($seenNames[$row['username']]) || isset($seenEmails[$row['email']])) {
                $errors[] = 'Username/email berulang dalam CSV.';
            }$seenNames[$row['username']] = true;
            $seenEmails[$row['email']] = true;
            $rows[] = ['line' => $number, 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'password' => $password, 'errors' => $errors];
        }
        fclose($file);
        abort_unless(count($rows), 422, 'CSV belum berisi siswa.');
        $invalid = array_sum(array_map(fn ($row) => count($row['errors']) ? 1 : 0, $rows));
        if (! $r->boolean('commit')) {
            return response()->json(['data' => ['rows' => array_map(fn ($row) => collect($row)->except('password')->all(), $rows), 'valid' => count($rows) - $invalid, 'invalid' => $invalid]]);
        }
        abort_if($invalid > 0, 422, 'Perbaiki seluruh kesalahan CSV sebelum mengimpor.');
        try {
            $created = DB::transaction(function () use ($rows, $classroom) {
                Classroom::whereKey($classroom->id)->lockForUpdate()->firstOrFail();
                $created = [];
                foreach ($rows as $row) {
                    $password = $row['password'] !== '' ? $row['password'] : Str::random(PasswordPolicy::MAX_LENGTH);
                    $user = User::create(['name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'password' => $password, 'school_id' => $classroom->school_id, 'role' => Role::Student, 'is_active' => true]);
                    $classroom->users()->attach($user);
                    $created[] = ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'initial_password' => $password];
                }

                return $created;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['file' => ['Username atau email baru saja digunakan. Pratinjau ulang CSV.']]);
        }

        return response()->json(['data' => ['created' => $created, 'count' => count($created)]], 201);
    }
}
