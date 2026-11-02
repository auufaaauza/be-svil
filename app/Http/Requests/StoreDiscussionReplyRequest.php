<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscussionReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('discussion')->canView($this->user());
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'content' => ['required', 'string', 'max:3000']];
    }
}
