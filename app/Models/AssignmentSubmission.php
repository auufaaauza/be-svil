<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $fillable = ['assignment_id', 'owner_key', 'student_id', 'learning_group_id', 'member_ids', 'content', 'file_path', 'file_name', 'file_mime', 'file_size', 'source', 'offline_evidence', 'recorded_by', 'status', 'version', 'assignment_snapshot', 'submitted_at', 'is_late', 'share_with_class', 'gallery_published', 'score', 'rubric_scores', 'feedback', 'reviewed_by', 'reviewed_at', 'submission_count'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['member_ids' => 'array', 'assignment_snapshot' => 'array', 'rubric_scores' => 'array', 'version' => 'integer', 'score' => 'float', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'is_late' => 'boolean', 'share_with_class' => 'boolean', 'gallery_published' => 'boolean'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        return $q->whereIn('assignment_id', Assignment::visibleTo($u)->select('id'))->when($u->role === Role::Student, fn ($q) => $q->where(fn ($p) => $p->where('student_id', $u->id)->orWhereJsonContains('member_ids', $u->id)));
    }
}
