<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class ReviewAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && $this->user()->canAccessClassroom($this->route('attempt')->material->classroom_id);
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'correct' => ['present', 'nullable', 'boolean'], 'feedback' => ['required', 'string', 'max:3000']];
    }
}
