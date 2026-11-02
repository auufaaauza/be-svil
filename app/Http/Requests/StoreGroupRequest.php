<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))],
            'name' => ['required', 'string', 'max:80', Rule::unique('learning_groups')->where('classroom_id', $this->input('classroom_id'))],
            'student_ids' => ['required', 'array', 'min:1', 'max:10'],
            'student_ids.*' => ['required', 'integer', 'distinct', Rule::exists('classroom_user', 'user_id')->where('classroom_id', $this->input('classroom_id'))->where('is_active', true), Rule::exists('users', 'id')->where('role', 'student')->where('is_active', true)],
        ];
    }
}
