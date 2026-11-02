<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discussion extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'author_id', 'title', 'content', 'status', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionReply::class);
    }

    public function canView(User $user): bool
    {
        return $user->canAccessClassroom($this->classroom_id) && ($user->role !== Role::Student || $this->status !== 'draft');
    }
}
