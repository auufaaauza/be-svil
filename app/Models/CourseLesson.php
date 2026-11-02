<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLesson extends Model
{
    use HasFactory;

    protected $fillable = ['course_id', 'course_chapter_id', 'title', 'objective', 'position', 'scheduled_on', 'mode', 'status', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'scheduled_on' => 'date:Y-m-d'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    public function canView(User $u): bool
    {
        return $this->course->canView($u) && ($u->role !== Role::Student || $this->status !== 'draft');
    }
}
