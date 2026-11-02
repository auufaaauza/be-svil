<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && (! $this->route('assignment') || $this->user()->canAccessClassroom($this->route('assignment')->classroom_id));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('rubric'))) {
            $this->merge(['rubric' => json_decode($this->input('rubric'), true)]);
        }
    }

    public function rules(): array
    {
        return ['allow_late' => ['sometimes', 'boolean'], 'allow_revisions' => ['sometimes', 'boolean'], 'lock_after_review' => ['sometimes', 'boolean'], 'max_submissions' => ['nullable', 'integer', 'between:1,20'], 'course_lesson_id' => ['required', 'integer', Rule::exists('course_lessons', 'id')->whereIn('course_id', Course::whereIn('classroom_id', $this->user()->accessibleClassrooms()->select('classrooms.id'))->pluck('id'))], 'title' => ['required', 'string', 'max:180'], 'instructions' => ['required', 'string', 'max:8000'], 'kind' => ['required', 'in:task,project'], 'submission_mode' => ['required', 'in:individual,group'], 'status' => ['required', 'in:draft,published,closed'], 'due_at' => ['nullable', 'date'], 'max_score' => ['required', 'numeric', 'between:1,10000'], 'rubric' => ['nullable', 'array', 'min:1', 'max:12'], 'rubric.*' => ['array:key,label,max_points,description'], 'rubric.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:40', 'distinct'], 'rubric.*.label' => ['required', 'string', 'max:180'], 'rubric.*.max_points' => ['required', 'numeric', 'between:1,1000'], 'rubric.*.description' => ['nullable', 'string', 'max:2000'], 'version' => [$this->route('assignment') ? 'required' : 'nullable', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (! $validator->errors()->any() && $this->input('rubric') && abs(array_sum(array_column($this->input('rubric'), 'max_points')) - (float) $this->input('max_score')) > 0.001) {
                $validator->errors()->add('rubric', 'Jumlah bobot rubrik harus sama dengan skor maksimum.');
            }
        }];
    }
}
