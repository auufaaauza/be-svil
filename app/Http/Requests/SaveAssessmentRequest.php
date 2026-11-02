<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\QuestionBankItem;
use App\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role !== Role::Student && (! $this->route('assessment') || $this->user()->canAccessClassroom($this->route('assessment')->classroom_id));
    }

    protected function prepareForValidation(): void
    {
        $questions = $this->input('questions');
        if (is_string($questions)) {
            $questions = json_decode($questions, true);
        }
        if (is_array($questions) && $this->user()->role !== Role::Student) {
            foreach ($questions as $i => $q) {
                if (is_array($q) && isset($q['bank_item_id'])) {
                    $bank = QuestionBankItem::whereIn('classroom_id', $this->user()->accessibleClassrooms()->select('classrooms.id'))->find($q['bank_item_id']);
                    if (! $bank) {
                        throw ValidationException::withMessages(['questions' => ['Soal bank tidak tersedia dalam kelas Anda.']]);
                    }if (isset($q['bank_version']) && $bank->version !== $q['bank_version']) {
                        throw ValidationException::withMessages(['questions' => ['Versi bank soal berubah. Ambil soal terbaru.']]);
                    }$questions[$i] = [...$bank->question, 'id' => $q['id'] ?? 'bank_'.$bank->id, 'points' => $q['points'] ?? $bank->question['points'], 'bank_item_id' => $bank->id, 'bank_version' => $bank->version];
                }
            }
        }
        $this->merge(['questions' => $questions]);
    }

    public static function questionRules(string $prefix = 'questions.*.'): array
    {
        return [$prefix.'id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:60'], $prefix.'type' => ['required', 'in:choice,text'], $prefix.'prompt' => ['required', 'string', 'max:2000'], $prefix.'points' => ['required', 'numeric', 'between:0.1,1000'], $prefix.'options' => ['present', 'array', 'max:4'], $prefix.'options.*' => ['required', 'string', 'max:500'], $prefix.'correctIndex' => ['nullable', 'integer', 'between:0,3'], $prefix.'modelAnswer' => ['nullable', 'string', 'max:3000'], $prefix.'explanation' => ['nullable', 'string', 'max:3000'], $prefix.'visualTopic' => ['nullable', 'integer', 'between:0,3'], $prefix.'cognitive_level' => ['nullable', 'in:C1,C2,C3,C4,C5,C6'], $prefix.'bank_item_id' => ['nullable', 'integer'], $prefix.'bank_version' => ['nullable', 'integer']];
    }

    public function rules(): array
    {
        return ['course_lesson_id' => ['required', 'integer', Rule::exists('course_lessons', 'id')->whereIn('course_id', Course::whereIn('classroom_id', $this->user()->accessibleClassrooms()->select('classrooms.id'))->pluck('id'))], 'title' => ['required', 'string', 'max:180'], 'instructions' => ['required', 'string', 'max:5000'], 'purpose' => ['required', 'in:practice,summative,research'], 'status' => ['required', 'in:draft,published,closed'], 'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date', Rule::when($this->filled('opens_at'), 'after:opens_at')], 'duration_minutes' => ['nullable', 'integer', 'between:1,180'], 'max_attempts' => ['required', 'integer', 'between:1,10'], 'version' => [$this->route('assessment') ? 'required' : 'nullable', 'integer', 'min:1'], 'questions' => ['required', 'array', 'min:1', 'max:100'], 'questions.*' => ['required', 'array:id,type,prompt,points,options,correctIndex,modelAnswer,explanation,visualTopic,cognitive_level,bank_item_id,bank_version'], ...self::questionRules()];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->any()) {
                return;
            }$questions = $this->input('questions');
            $ids = array_column($questions, 'id');
            if (count(array_unique($ids)) !== count($ids)) {
                $validator->errors()->add('questions', 'ID setiap soal harus unik.');
            }
            foreach ($questions as $q) {
                if ($q['type'] === 'choice' && (count($q['options']) !== 4 || ! isset($q['correctIndex']))) {
                    $validator->errors()->add('questions', 'Pilihan ganda memerlukan empat opsi dan kunci.');
                }if ($q['type'] === 'text' && (count($q['options']) !== 0 || isset($q['correctIndex']))) {
                    $validator->errors()->add('questions', 'Uraian tidak memakai pilihan atau kunci indeks.');
                }
            }
        }];
    }
}
