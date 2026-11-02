<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiscussionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $discussion = $this->route('discussion');

        return $this->user()->role !== Role::Student && (! $discussion || ($discussion->canView($this->user()) && ($this->user()->role === Role::Admin || $discussion->author_id === $this->user()->id)));
    }

    public function rules(): array
    {
        return ['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))], 'title' => ['required', 'string', 'max:180'], 'content' => ['required', 'string', 'max:8000'], 'status' => ['required', Rule::in(['draft', 'open', 'closed'])], 'version' => [$this->route('discussion') ? 'required' : 'nullable', 'integer', 'min:1']];
    }
}
