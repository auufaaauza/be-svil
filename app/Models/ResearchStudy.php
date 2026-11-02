<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResearchStudy extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'stage', 'status', 'protocol_reference', 'created_by', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
