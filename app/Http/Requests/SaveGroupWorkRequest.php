<?php

namespace App\Http\Requests;

use App\Learning\LkpdPages;
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
        $module = LearningModule::where('topic', (int) $this->route('topic'))->firstOrFail();
        abort_unless($module->availableIn($this->route('group')->classroom_id), 403);
        abort_if($module->status !== 'published' && ! $work?->schema_snapshot, 403);

        return $this->moduleSchema = ['fields' => LkpdPages::fields($module, $work, $this->route('group')->classroom_id), 'version' => $work?->module_version ?? $module->version, 'page_size' => LkpdPages::size($work ?? $module)];
    }

    public function authorize(): bool
    {
        return $this->user()->canUseSvil() && $this->route('group')->canContribute($this->user());
    }

    public function rules(): array
    {
        $fields = $this->moduleSchema()['fields'];
        $size = $this->moduleSchema()['page_size'];
        $keys = array_column($this->exists('page') ? array_slice($fields, $this->integer('page') * $size, $size) : $fields, 'key');
        $rules = ['version' => ['required', 'integer', 'min:0'], 'status' => ['required', Rule::in(['draft', 'submitted'])], 'page' => ['sometimes', 'integer', 'min:0', 'max:'.max(0, (int) ceil(count($fields) / $size) - 1)], 'answers' => ['required', 'array:'.implode(',', $keys)]];
        foreach ($fields as $index => $field) {
            $required = $this->input('status') === 'submitted' && (! $this->exists('page') || (int) floor($index / $size) === $this->integer('page'));
            $rules['answers.'.$field['key']] = [$required ? 'required' : 'nullable', 'string', 'max:8000'];
        }

        return $rules;
    }
}
