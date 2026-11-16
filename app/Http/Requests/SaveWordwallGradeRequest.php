<?php

namespace App\Http\Requests;

use App\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWordwallGradeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->role === Role::Teacher && $this->user()->canAccessClassroom($this->integer('classroom_id'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $games = json_decode(file_get_contents(database_path('data/lkpd-games.json')), true, flags: JSON_THROW_ON_ERROR);

        return ['classroom_id' => ['required', 'integer', 'exists:classrooms,id'], 'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'student')], 'game_id' => ['required', Rule::in(array_column($games, 'id'))], 'score' => ['required', 'numeric', 'between:0,100'], 'feedback' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:0']];
    }
}
