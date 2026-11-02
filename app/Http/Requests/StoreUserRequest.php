<?php

namespace App\Http\Requests;

use App\PasswordPolicy;
use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Admin;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'email' => [Rule::requiredIf($this->input('role') !== 'student'), 'nullable', 'email', 'max:255', 'unique:users,email'],
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:users,username'],
            'password' => PasswordPolicy::rules(),
            'role' => ['required', Rule::enum(Role::class)],
            'school_id' => [Rule::requiredIf($this->input('role') !== 'admin'), 'nullable', 'integer', 'exists:schools,id'],
            'classroom_ids' => [Rule::requiredIf($this->input('role') === 'student'), 'array', Rule::when($this->input('role') === 'student', 'min:1'), 'max:30'],
            'classroom_ids.*' => ['integer', 'distinct', Rule::exists('classrooms', 'id')->where('school_id', $this->input('school_id'))],
        ];
    }
}
