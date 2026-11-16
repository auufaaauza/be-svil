<?php

namespace App\Models;

use App\Learning\QuestionMedia;
use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class LearningModule extends Model
{
    use HasFactory;

    protected $fillable = ['topic', 'title', 'description', 'instructions', 'fields', 'source_text', 'source_document', 'source_hash', 'status', 'version', 'uploaded_by', 'updated_by', 'youtube_video_id', 'video_caption', 'classroom_id', 'create_request_id', 'page_size'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'topic' => 'integer', 'version' => 'integer', 'classroom_id' => 'integer', 'page_size' => 'integer'];
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('classroom_id')->orWhereIn('classroom_id', $user->accessibleClassrooms()->select('classrooms.id')));
    }

    public function availableIn(int $classroom): bool
    {
        return $this->classroom_id === null || $this->classroom_id === $classroom;
    }

    public function canView(User $user): bool
    {
        return ($this->classroom_id === null || $user->canAccessClassroom($this->classroom_id))
            && ($user->role !== Role::Student || $user->canUseSvil() && $this->status === 'published');
    }

    public function mediaFor(?int $classroom): array
    {
        $override = $classroom ? DB::table('module_class_media')->where('learning_module_id', $this->id)->where('classroom_id', $classroom)->first() : null;

        $useOverride = $override && ($override->video_is_overridden ?? true);

        return ['videoId' => $useOverride ? $override->youtube_video_id : $this->youtube_video_id, 'videoCaption' => $useOverride ? $override->video_caption : $this->video_caption, 'videoVersion' => $override?->version ?? 0, 'videoOverride' => (bool) $useOverride];
    }

    public function contentFor(?int $classroom): array
    {
        $row = $classroom ? DB::table('module_class_media')->where('learning_module_id', $this->id)->where('classroom_id', $classroom)->first() : null;
        $content = $row?->content ? json_decode($row->content, true, flags: JSON_THROW_ON_ERROR) : [];

        return ['title' => $content['title'] ?? $this->title, 'description' => $content['description'] ?? $this->description, 'instructions' => $content['instructions'] ?? $this->instructions, 'fields' => QuestionMedia::fields($content['fields'] ?? $this->fields, $this->id, $classroom), 'contentVersion' => $row?->content_version ?? 0, 'contentOverride' => ! empty($content)];
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
