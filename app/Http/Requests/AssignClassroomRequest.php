<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Admin && ! $this->route('classroom')->archived_at;
    }

    public function rules(): array
    {
        return ['user_ids' => ['required', 'array', 'min:1', 'max:100'], 'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('school_id', $this->route('classroom')->school_id)->whereIn('role', ['student', 'teacher'])->where('is_active', true)]];
    }
}
