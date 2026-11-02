<?php

namespace App\Console\Commands;

use App\Models\User;
use App\PasswordPolicy;
use App\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'svil:create-admin {email} {--name=Administrator}';

    protected $description = 'Create the first admin without demo accounts or a password in shell history';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Jalankan perintah ini secara interaktif agar kata sandi dapat dimasukkan tersembunyi.');

            return self::FAILURE;
        }
        $password = $this->secret('Kata sandi admin (6–12 karakter)');
        $values = ['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password];
        $validator = Validator::make($values, ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => PasswordPolicy::rules()]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create([...$values, 'role' => Role::Admin, 'is_active' => true]);
        $this->info('Admin berhasil dibuat. Kata sandi tidak ditampilkan.');

        return self::SUCCESS;
    }
}
