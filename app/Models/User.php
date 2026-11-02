<?php

namespace App\Models;

use App\Role;
use App\StudentAccounts;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'school_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['role' => 'student', 'is_active' => true];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->username) {
                $base = preg_replace('/[^a-z0-9._-]/', '', Str::lower(Str::before($user->email ?? '', '@')));
                $base = strlen($base) >= 3 ? substr($base, 0, 30) : StudentAccounts::usernameBase($user->name);
                $candidate = $base;
                while (static::where('username', $candidate)->exists()) {
                    $candidate = $base.'-'.Str::lower(Str::random(6));
                }
                $user->username = $candidate;
            }
        });
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => Role::class, 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'profile_version' => 'integer'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class)->wherePivot('is_active', true)->withPivot('is_active')->withTimestamps();
    }

    public function accessibleClassrooms(): Builder
    {
        return Classroom::query()->when($this->role !== Role::Admin, fn (Builder $query) => $query
            ->where('school_id', $this->school_id)
            ->whereHas('users', fn (Builder $users) => $users->whereKey($this->id)));
    }

    public function canAccessClassroom(int $id): bool
    {
        return $this->accessibleClassrooms()->whereKey($id)->exists();
    }

    public function canUseSvil(): bool
    {
        return $this->role !== Role::Student || ! DB::table('research_enrollments as e')->join('research_studies as s', 's.id', '=', 'e.research_study_id')->where('e.student_id', $this->id)->where('e.arm', 'control')->where('s.status', 'active')->exists();
    }
}
