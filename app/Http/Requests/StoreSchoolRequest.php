<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Foundation\Http\FormRequest;

class StoreSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Admin;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:50', 'unique:schools,code'], 'name' => ['required', 'string', 'max:150']];
    }
}
