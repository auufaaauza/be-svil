<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class ReviewNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && $this->user()->canAccessClassroom($this->route('note')->classroom_id);
    }

    public function rules(): array
    {
        return ['feedback' => ['required', 'string', 'max:3000'], 'content_hash' => ['sometimes', 'string', 'size:64']];
    }
}
