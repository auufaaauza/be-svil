<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionJournal extends Model
{
    use HasFactory;

    protected $fillable = ['action_plan_id', 'student_id', 'request_id', 'occurred_on', 'activity', 'evidence', 'obstacle', 'plan_version', 'plan_snapshot'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'plan_snapshot' => 'array'];
    }
}
