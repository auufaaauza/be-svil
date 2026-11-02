<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student;
    }

    public function rules(): array
    {
        $rules = [
            'classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))],
            'student_id' => ['required', 'integer', Rule::exists('classroom_user', 'user_id')->where('classroom_id', $this->input('classroom_id')), Rule::exists('users', 'id')->where('role', 'student')],
            'topic' => ['required', 'integer', 'between:0,3'], 'occasion' => ['required', 'integer', 'between:1,2'],
            'version' => ['required', 'integer', 'min:0'], 'observed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'evidence' => ['required', 'string', 'max:3000'], 'feedback' => ['required', 'string', 'max:3000'],
            'scores' => ['required', 'array:participation,empathy,honesty,responsibility'],
        ];
        foreach (['participation', 'empathy', 'honesty', 'responsibility'] as $aspect) {
            $rules['scores.'.$aspect] = ['required', 'integer', 'between:1,4'];
        }

        return $rules;
    }
}
