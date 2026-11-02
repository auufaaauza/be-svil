<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'course_lesson_id', 'created_by', 'title', 'instructions', 'kind', 'submission_mode', 'status', 'due_at', 'max_score', 'rubric', 'version', 'allow_late', 'allow_revisions', 'lock_after_review', 'max_submissions'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'rubric' => 'array', 'version' => 'integer', 'max_score' => 'float', 'allow_late' => 'boolean', 'allow_revisions' => 'boolean', 'lock_after_review' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'course_lesson_id');
    }

    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        return $q->whereIn('classroom_id', $u->accessibleClassrooms()->select('classrooms.id'))->when($u->role === Role::Student, fn ($q) => $q->whereIn('status', ['published', 'closed'])->whereHas('lesson', fn ($l) => $l->where('status', '!=', 'draft')->whereIn('course_id', Course::visibleTo($u)->select('id'))));
    }

    public function canView(User $u): bool
    {
        return self::visibleTo($u)->whereKey($this->id)->exists();
    }
}
