<?php

namespace App\Http\Requests;

use App\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user();
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['username', 'email', 'class_code'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = $key === 'class_code' ? Str::upper(trim($this->input($key))) : Str::lower(trim($this->input($key)));
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
            'class_code' => ['required', 'string', 'size:12', Rule::exists('classrooms', 'join_code')->whereNull('archived_at')],
        ];
    }

    public function messages(): array
    {
        return ['class_code.exists' => 'Kode kelas tidak ditemukan. Minta kode kepada guru atau admin.', 'username.unique' => 'Username sudah digunakan. Pilih username lain.'];
    }
}
