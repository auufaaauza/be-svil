<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'student_id', 'teacher_id', 'topic', 'occasion', 'observed_on', 'scores', 'evidence', 'feedback', 'version'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'observed_on' => 'date:Y-m-d', 'version' => 'integer'];
    }
}
