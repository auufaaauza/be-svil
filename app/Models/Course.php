<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'subject', 'title', 'semester', 'method', 'description', 'status', 'version', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CourseChapter::class)->orderBy('position')->orderBy('id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->whereIn('classroom_id', $user->accessibleClassrooms()->select('classrooms.id'))->when($user->role === Role::Student, fn ($q) => $q->whereIn('status', ['published', 'archived'])->when(! $user->canUseSvil(), fn ($q) => $q->where('method', '!=', 'svil')));
    }

    public function canView(User $user): bool
    {
        return self::visibleTo($user)->whereKey($this->id)->exists();
    }

    public function canManage(User $user): bool
    {
        return $user->role !== Role::Student && $user->canAccessClassroom($this->classroom_id);
    }
}
