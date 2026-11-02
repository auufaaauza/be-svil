<?php

namespace Database\Seeders;

use App\Models\User;
use App\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::query()
            ->where('username', 'admin')
            ->orWhere('email', 'admin@findmycareer.my.id')
            ->get();

        if ($existing->contains(fn (User $user): bool => $user->role !== Role::Admin)) {
            throw new RuntimeException('Username admin atau email admin@findmycareer.my.id sudah dipakai akun non-admin. Akun tersebut tidak diubah.');
        }

        if ($existing->isNotEmpty() || User::where('role', Role::Admin)->exists()) {
            $this->command?->info('Akun admin sudah tersedia. Password, profil dan status akun tidak diubah.');

            return;
        }

        User::create([
            'name' => 'Administrator SVIL',
            'username' => 'admin',
            'email' => 'admin@findmycareer.my.id',
            'password' => 'svil123',
            'role' => Role::Admin,
            'school_id' => null,
            'is_active' => true,
        ]);

        $this->command?->info('Admin pertama berhasil dibuat: admin / admin@findmycareer.my.id.');
    }
}
