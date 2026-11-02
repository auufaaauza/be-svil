<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Admin;
    }

    public function rules(): array
    {
        return [
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'name' => ['required', 'string', 'max:80', Rule::unique('classrooms')->where('school_id', $this->input('school_id'))->where('academic_year', $this->input('academic_year'))],
            'academic_year' => ['required', 'string', 'max:20'],
        ];
    }
}
