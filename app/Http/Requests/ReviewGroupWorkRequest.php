<?php

namespace App\Http\Requests;

use App\Learning\LkpdPages;
use App\Learning\LkpdSchema;
use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewGroupWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::Teacher && $this->route('work')->group->canView($this->user());
    }

    public function rules(): array
    {
        $total = (int) ceil(count($this->route('work')->schema_snapshot ?? []) / LkpdPages::size($this->route('work')));
        $rules = ['score' => ['nullable', 'numeric', 'between:0,100'], 'version' => ['required', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:0', 'max:'.max(0, $total - 1)], 'status' => ['required', Rule::in(['reviewed', 'needs_revision'])], 'feedback' => ['required', 'string', 'max:3000']];
        foreach (LkpdSchema::rubrics() as $name => $aspects) {
            $key = $name.'_scores';
            $rules[$key] = ['nullable', Rule::prohibitedIf($this->route('work')->topic !== 3), 'array:'.implode(',', array_keys($aspects))];
            foreach ($aspects as $aspect => $label) {
                $rules[$key.'.'.$aspect] = ['required_with:'.$key, 'integer', 'between:1,4'];
            }
        }

        return $rules;
    }
}
