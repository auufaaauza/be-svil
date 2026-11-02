<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActionJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canUseSvil() && $this->route('plan')->group->canContribute($this->user());
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'activity' => ['required', 'string', 'max:3000'], 'evidence' => ['required', 'string', 'max:3000'], 'obstacle' => ['nullable', 'string', 'max:3000']];
    }
}
