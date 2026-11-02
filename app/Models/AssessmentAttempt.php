<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['assessment_id', 'student_id', 'attempt_number', 'assessment_snapshot', 'answers', 'item_scores', 'source', 'offline_evidence', 'recorded_by', 'started_at', 'expires_at', 'submitted_at', 'submit_request_id', 'status', 'raw_score', 'max_score', 'feedback', 'reviewed_by', 'version'];

    protected $hidden = ['assessment_snapshot'];

    protected function casts(): array
    {
        return ['assessment_snapshot' => 'array', 'answers' => 'array', 'item_scores' => 'array', 'started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'raw_score' => 'float', 'max_score' => 'float', 'version' => 'integer'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        return $q->whereHas('assessment', fn ($a) => $a->whereIn('classroom_id', $u->accessibleClassrooms()->select('classrooms.id')))->when($u->role === Role::Student, fn ($q) => $q->where('student_id', $u->id));
    }
}
