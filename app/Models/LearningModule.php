<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class LearningModule extends Model
{
    use HasFactory;

    protected $fillable = ['topic', 'title', 'description', 'instructions', 'fields', 'source_text', 'source_document', 'source_hash', 'status', 'version', 'uploaded_by', 'updated_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'topic' => 'integer', 'version' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function recordRevision(): void
    {
        DB::table('learning_module_revisions')->insert(['learning_module_id' => $this->id, 'version' => $this->version, 'snapshot' => json_encode($this->attributesToArray(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public static function publishedForTopic(int $topic): self
    {
        return self::where('topic', $topic)->where('status', 'published')->firstOrFail();
    }
}
