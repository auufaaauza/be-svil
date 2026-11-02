<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResearchInstrument extends Model
{
    use HasFactory;

    protected $fillable = ['research_study_id', 'title', 'kind', 'status', 'approval_reference', 'items', 'version', 'created_by', 'previous_instrument_id', 'target_role', 'period', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'version' => 'integer', 'opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }
}
