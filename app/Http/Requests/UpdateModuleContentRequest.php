<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdateModuleContentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['apply_to_drafts' => ['sometimes', 'boolean'], 'classroom_id' => ['required', 'integer', 'exists:classrooms,id'], 'version' => ['required', 'integer', 'min:0'], 'title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:2000'], 'instructions' => ['required', 'string', 'max:8000'], 'fields' => ['required', 'array', 'min:1', 'max:150'], 'fields.*' => ['required', 'array:key,label,hint,phase,source,video_url,image_id,image_alt,media_caption'], 'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/D', 'max:60', 'distinct'], 'fields.*.label' => ['required', 'string', 'max:500'], 'fields.*.hint' => ['required', 'string', 'max:1500'], 'fields.*.phase' => ['required', 'integer', 'between:0,4'], 'fields.*.source' => ['nullable', 'string', 'max:250'], 'fields.*.video_url' => ['nullable', 'string', 'max:2048'], 'fields.*.image_id' => ['nullable', 'string', 'regex:/^[a-f0-9-]{36}\.(jpg|png|webp)$/D'], 'fields.*.image_alt' => ['nullable', 'string', 'max:500'], 'fields.*.media_caption' => ['nullable', 'string', 'max:1000'], 'photos' => ['sometimes', 'array', 'max:150'], 'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=6000,max_height=6000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['apply_to_drafts' => $this->boolean('apply_to_drafts')]);
        if (is_string($this->input('fields'))) {
            $decoded = json_decode($this->input('fields'), true);
            if (is_array($decoded)) {
                $this->merge(['fields' => $decoded]);
            }
        }
    }
}
