<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionPlan extends Model
{
    use HasFactory;

    protected $fillable = ['learning_group_id', 'title', 'start_date', 'location', 'indicator', 'steps', 'responsibilities', 'evidence_method', 'version', 'verified_at', 'verified_by', 'teacher_feedback'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'start_date' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LearningGroup::class, 'learning_group_id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(ActionJournal::class);
    }
}
