<?php

namespace App\Models;

use Database\Factories\WordwallGradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordwallGrade extends Model
{
    /** @use HasFactory<WordwallGradeFactory> */
    use HasFactory;

    protected $fillable = ['classroom_id', 'student_id', 'teacher_id', 'game_id', 'game_title', 'topic', 'score', 'feedback', 'version'];

    protected function casts(): array
    {
        return ['score' => 'float', 'topic' => 'integer', 'version' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
