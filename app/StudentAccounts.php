<?php

namespace App;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentAccounts
{
    public const DEFAULT_PASSWORD = 'siswa123';

    public static function usernameBase(string $name): string
    {
        $first = Str::before(Str::squish($name), ' ');
        $base = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($first)));
        $base = substr($base ?: 'siswa', 0, 30);

        return strlen($base) < 3 ? $base.'01' : $base;
    }

    /** @param array<int, array{name:string,email:?string}> $students */
    public static function plan(array $students): array
    {
        $bases = array_values(array_unique(array_map(fn (array $row): string => self::usernameBase($row['name']), $students)));
        $taken = User::where(function ($query) use ($bases): void {
            foreach ($bases as $base) {
                $query->orWhere('username', 'like', $base.'%');
            }
        })->pluck('username')->flip()->all();
        $emails = array_values(array_filter(array_column($students, 'email')));
        $usedEmails = User::whereIn('email', $emails)->pluck('email')->flip()->all();
        $rows = [];
        foreach ($students as $index => $student) {
            if ($student['email'] && isset($usedEmails[$student['email']])) {
                throw ValidationException::withMessages(['students.'.$index.'.email' => 'Email '.$student['email'].' sudah digunakan.']);
            }
            $base = self::usernameBase($student['name']);
            $username = $base;
            $suffix = 2;
            while (isset($taken[$username])) {
                $username = $base.$suffix++;
            }
            $taken[$username] = true;
            $rows[] = ['name' => $student['name'], 'username' => $username, 'email' => $student['email']];
        }

        return $rows;
    }
}
