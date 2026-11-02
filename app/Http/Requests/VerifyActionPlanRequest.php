<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class VerifyActionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && $this->route('plan')->group->canView($this->user());
    }

    public function rules(): array
    {
        return ['feedback' => ['required', 'string', 'max:3000'], 'version' => ['required', 'integer', 'min:1']];
    }
}
