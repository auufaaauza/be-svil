<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['material_id', 'student_id', 'request_id', 'material_version', 'answer', 'question_snapshot', 'correct', 'explanation', 'teacher_feedback', 'reviewed_at', 'reviewed_by', 'review_version'];

    protected $hidden = ['question_snapshot'];

    protected function casts(): array
    {
        return ['answer' => 'array', 'question_snapshot' => 'array', 'correct' => 'boolean', 'reviewed_at' => 'datetime', 'review_version' => 'integer'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
