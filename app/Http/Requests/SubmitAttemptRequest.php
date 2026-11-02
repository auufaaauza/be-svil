<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Student && $this->user()->can('view', $this->route('material'));
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'material_version' => ['required', 'integer', 'min:1'], 'choice' => ['nullable', 'integer', 'between:0,3'], 'answer' => ['nullable', 'string', 'max:8000']];
    }
}
