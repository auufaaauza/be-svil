<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveActionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canUseSvil() && $this->route('group')->canContribute($this->user());
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'title' => ['required', 'string', 'max:150'], 'start_date' => ['required', 'date_format:Y-m-d'], 'location' => ['required', 'string', 'max:200'], 'indicator' => ['required', 'string', 'max:3000'], 'steps' => ['required', 'string', 'max:3000'], 'responsibilities' => ['required', 'string', 'max:3000'], 'evidence_method' => ['required', 'string', 'max:3000']];
    }
}
