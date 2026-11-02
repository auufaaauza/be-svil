<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && (! $this->route('course') || $this->route('course')->canManage($this->user()));
    }

    public function rules(): array
    {
        return ['classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->whereIn('id', $this->user()->accessibleClassrooms()->pluck('id'))], 'subject' => ['required', 'string', 'max:80'], 'title' => ['required', 'string', 'max:180'], 'semester' => ['required', 'in:ganjil,genap'], 'method' => ['required', 'in:standard,svil'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['required', 'in:draft,published,archived'], 'version' => [$this->route('course') ? 'required' : 'nullable', 'integer', 'min:1']];
    }
}
