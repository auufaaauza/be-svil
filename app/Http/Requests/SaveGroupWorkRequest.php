<?php

namespace App\Http\Requests;

use App\Models\GroupWork;
use App\Models\LearningModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGroupWorkRequest extends FormRequest
{
    private ?array $moduleSchema = null;

    public function moduleSchema(): array
    {
        if ($this->moduleSchema !== null) {
            return $this->moduleSchema;
        }
        $work = GroupWork::where('learning_group_id', $this->route('group')->id)->where('topic', (int) $this->route('topic'))->first();
        if ($work?->schema_snapshot) {
            return $this->moduleSchema = ['fields' => $work->schema_snapshot, 'version' => $work->module_version];
        }
        $module = LearningModule::publishedForTopic((int) $this->route('topic'));

        return $this->moduleSchema = ['fields' => $module->fields, 'version' => $module->version];
    }

    public function authorize(): bool
    {
        return $this->user()->canUseSvil() && $this->route('group')->canContribute($this->user());
    }

    public function rules(): array
    {
        $fields = $this->moduleSchema()['fields'];
        $rules = ['version' => ['required', 'integer', 'min:0'], 'status' => ['required', Rule::in(['draft', 'submitted'])], 'answers' => ['required', 'array:'.implode(',', array_column($fields, 'key'))]];
        foreach ($fields as $field) {
            $rules['answers.'.$field['key']] = [$this->input('status') === 'submitted' ? 'required' : 'nullable', 'string', 'max:8000'];
        }

        return $rules;
    }
}
