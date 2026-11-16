<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLearningModuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()->role, [Role::Teacher, Role::Admin], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'classroom_id' => [Rule::requiredIf($this->user()->role === Role::Teacher), 'nullable', 'integer', 'exists:classrooms,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:2000'],
            'instructions' => ['required', 'string', 'max:8000'],
            'fields' => ['required', 'array', 'min:1', 'max:150'],
            'fields.*' => ['array:key,label,hint,phase,source,video_url'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/D', 'max:60', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:500'],
            'fields.*.hint' => ['nullable', 'string', 'max:1500'],
            'fields.*.phase' => ['required', 'integer', 'between:0,4'],
            'fields.*.source' => ['nullable', 'string', 'max:250'],
            'fields.*.video_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
