<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Student && $this->user()->canUseSvil();
    }

    public function rules(): array
    {
        return ['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))], 'topic' => ['required', 'integer', 'between:0,3'], 'phase' => ['required', 'integer', 'between:0,4'], 'content' => ['required', 'string', 'max:8000']];
    }
}
