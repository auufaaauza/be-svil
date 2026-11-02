<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionReply extends Model
{
    use HasFactory;

    protected $fillable = ['discussion_id', 'author_id', 'request_id', 'content', 'is_hidden', 'moderated_by', 'moderated_at'];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'moderated_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}
