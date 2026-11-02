<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLearningModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Admin;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:2000'], 'instructions' => ['required', 'string', 'max:8000'], 'status' => ['required', Rule::in(['draft', 'published'])], 'fields' => ['required', 'array', 'min:1', 'max:150'], 'fields.*' => ['required', 'array:key,label,hint,phase,source'], 'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:60', 'distinct'], 'fields.*.label' => ['required', 'string', 'max:500'], 'fields.*.hint' => ['required', 'string', 'max:1500'], 'fields.*.source' => ['sometimes', 'string', 'max:250'], 'fields.*.phase' => ['required', 'integer', 'between:0,4']];
    }
}
