<?php

namespace App\Models;

use App\Learning\LkpdPages;
use App\Learning\LkpdSchema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class GroupWork extends Model
{
    use HasFactory;

    protected $fillable = ['learning_group_id', 'topic', 'answers', 'status', 'version', 'feedback', 'product_scores', 'presentation_scores', 'reviewed_by', 'submitted_at', 'reviewed_at', 'schema_snapshot', 'module_version', 'member_ids', 'page_submissions', 'page_size', 'score'];

    protected function casts(): array
    {
        return ['topic' => 'integer', 'version' => 'integer', 'answers' => 'array', 'product_scores' => 'array', 'presentation_scores' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'schema_snapshot' => 'array', 'module_version' => 'integer', 'member_ids' => 'array', 'page_submissions' => 'array', 'page_size' => 'integer', 'score' => 'float'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LearningGroup::class, 'learning_group_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function recordRevision(User $actor): void
    {
        DB::table('group_work_revisions')->insert(['group_work_id' => $this->id, 'actor_id' => $actor->id, 'version' => $this->version, 'snapshot' => json_encode([...$this->attributesToArray(), 'rubric_reference' => LkpdSchema::guidance(), 'member_ids' => $this->member_ids ?? $this->group->users()->pluck('users.id')->all()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function summary(): array
    {
        $pages = LkpdPages::entries($this);

        return [...$this->toArray(), 'score' => $this->status === 'reviewed' ? $this->score : null, 'page_size' => LkpdPages::size($this), 'page_submissions' => (object) $pages, 'pages_submitted' => count($pages), 'pages_total' => (int) ceil(count($this->schema_snapshot ?? []) / LkpdPages::size($this)), 'product_value' => $this->product_scores ? round(array_sum($this->product_scores) / 16 * 100, 2) : null, 'presentation_value' => $this->presentation_scores ? round(array_sum($this->presentation_scores) / 8 * 100, 2) : null];
    }
}
