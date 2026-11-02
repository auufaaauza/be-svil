<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material ? $this->user()->can('update', $material) : $this->user()->can('create', Material::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('topic')) {
            $this->merge(['topic_label' => null]);
        }
        if (is_string($this->input('question'))) {
            $decoded = json_decode($this->input('question'), true);
            $this->merge(['question' => json_last_error() === JSON_ERROR_NONE ? $decoded : $this->input('question')]);
        }
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))],
            'title' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:8000'],
            'topic' => ['nullable', 'integer', 'between:0,3'], 'topic_label' => ['nullable', 'string', 'max:180'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'course_lesson_id' => ['nullable', 'integer', Rule::exists('course_lessons', 'id')->whereIn('course_id', Course::where('classroom_id', $this->integer('classroom_id'))->pluck('id'))],
            'kind' => ['sometimes', Rule::in(['material', 'quiz'])],
            'subject' => ['sometimes', 'required', 'string', 'max:80'],
            'attachment' => ['nullable', 'file', 'extensions:pdf,pptx', 'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/zip,application/octet-stream', 'max:10240'],
            'remove_attachment' => ['sometimes', 'boolean'],
            'version' => [$this->route('material') ? 'required' : 'nullable', 'integer', 'min:1'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=6000,max_height=6000'],
            'remove_image' => ['sometimes', 'boolean'],
            'image_alt' => [Rule::requiredIf(fn () => $this->hasFile('image') || ($this->route('material')?->image_path && ! $this->boolean('remove_image'))), 'nullable', 'string', 'max:250'],
            'question' => [Rule::requiredIf($this->input('kind', $this->route('material')?->kind) === 'quiz'), 'nullable', 'array:type,prompt,options,correctIndex,modelAnswer,explanation,visualTopic'],
            'question.visualTopic' => ['nullable', 'integer', 'between:0,3'],
            'question.type' => ['required_with:question', Rule::in(['choice', 'text'])],
            'question.prompt' => ['required_with:question', 'string', 'max:1500'],
            'question.options' => ['required_if:question.type,choice', 'array', $this->input('question.type') === 'choice' ? 'size:4' : 'size:0'],
            'question.options.*' => ['required', 'string', 'max:300'],
            'question.correctIndex' => ['nullable', 'integer', 'between:0,3', Rule::prohibitedIf($this->input('question.type') === 'text')],
            'question.modelAnswer' => ['nullable', 'string', 'max:2000'],
            'question.explanation' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
