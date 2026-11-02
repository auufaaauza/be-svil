<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LearningGroup extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'name', 'version', 'archived_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->wherePivot('is_active', true)->withPivot('is_active')->withTimestamps();
    }

    public function canView(User $user): bool
    {
        return $user->canAccessClassroom($this->classroom_id)
            && ($user->role !== Role::Student || $this->users()->whereKey($user->id)->exists());
    }

    public function canContribute(User $user): bool
    {
        return $user->role === Role::Student && ! $this->archived_at && $this->canView($user);
    }
}
