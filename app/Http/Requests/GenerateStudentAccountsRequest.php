<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Role;
use Illuminate\Support\Str;

class GenerateStudentAccountsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'request_id' => ['required', 'uuid'], 'commit' => ['sometimes', 'boolean'],
            'preview_token' => ['required_if:commit,true', 'nullable', 'string', 'max:512000'],
            'students' => ['required', 'array', 'min:1', 'max:500'],
            'students.*' => ['required', 'array:name,email'],
            'students.*.name' => ['required', 'string', 'max:120', 'regex:/[\p{L}\p{N}]/u'],
            'students.*.email' => ['nullable', 'email', 'max:255', 'distinct:ignore_case'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('students'))) {
            $this->merge(['students' => array_map(function (mixed $student): mixed {
                if (! is_array($student)) {
                    return $student;
                }
                $name = $student['name'] ?? null;
                $email = $student['email'] ?? null;
                return [...$student, 'name' => is_string($name) ? Str::squish($name) : $name, 'email' => is_string($email) ? (Str::lower(trim($email)) ?: null) : $email];
            }, $this->input('students'))]);
        }
    }
}
