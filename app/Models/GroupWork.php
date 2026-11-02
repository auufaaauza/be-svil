<?php

namespace App\Models;

use App\Learning\LkpdSchema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class GroupWork extends Model
{
    use HasFactory;

    protected $fillable = ['learning_group_id', 'topic', 'answers', 'status', 'version', 'feedback', 'product_scores', 'presentation_scores', 'reviewed_by', 'submitted_at', 'reviewed_at', 'schema_snapshot', 'module_version'];

    protected function casts(): array
    {
        return ['topic' => 'integer', 'version' => 'integer', 'answers' => 'array', 'product_scores' => 'array', 'presentation_scores' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'schema_snapshot' => 'array', 'module_version' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LearningGroup::class, 'learning_group_id');
    }

    public function recordRevision(User $actor): void
    {
        DB::table('group_work_revisions')->insert(['group_work_id' => $this->id, 'actor_id' => $actor->id, 'version' => $this->version, 'snapshot' => json_encode([...$this->attributesToArray(), 'rubric_reference' => LkpdSchema::guidance(), 'member_ids' => $this->group->users()->pluck('users.id')->all()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function summary(): array
    {
        return [...$this->toArray(), 'product_value' => $this->product_scores ? round(array_sum($this->product_scores) / 16 * 100, 2) : null, 'presentation_value' => $this->presentation_scores ? round(array_sum($this->presentation_scores) / 8 * 100, 2) : null];
    }
}
