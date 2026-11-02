<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['classroom_id', 'teacher_id', 'title', 'content', 'topic', 'status', 'version', 'image_path', 'image_alt', 'question', 'kind', 'subject', 'attachment_path', 'attachment_name', 'attachment_size', 'updated_by', 'course_lesson_id', 'topic_label', 'attachment_mime'];

    protected $hidden = ['question', 'image_path', 'attachment_path'];

    protected function casts(): array
    {
        return ['question' => 'array', 'topic' => 'integer', 'version' => 'integer'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'course_lesson_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when($user->role !== Role::Admin, function (Builder $query) use ($user) {
            $query->whereIn('classroom_id', $user->accessibleClassrooms()->select('classrooms.id'));
            if ($user->role === Role::Student) {
                $query->where('status', 'published')->where(fn ($q) => $q->whereNull('course_lesson_id')->orWhereHas('lesson', fn ($l) => $l->where('status', '!=', 'draft')->whereIn('course_id', Course::visibleTo($user)->select('id'))));
                if (! $user->canUseSvil()) {
                    $query->where(fn ($q) => $q->whereNull('topic')->orWhereHas('lesson.course', fn ($c) => $c->where('method', 'standard')));
                }
            } else {
                $query->where('teacher_id', $user->id);
            }
        });
    }
}
