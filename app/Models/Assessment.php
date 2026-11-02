<?php

namespace App\Models;

use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'course_lesson_id', 'created_by', 'title', 'instructions', 'purpose', 'status', 'questions', 'max_score', 'opens_at', 'closes_at', 'duration_minutes', 'max_attempts', 'version'];

    protected $hidden = ['questions'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'max_score' => 'float', 'version' => 'integer'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'course_lesson_id');
    }

    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        $q->whereIn('classroom_id', $u->accessibleClassrooms()->select('classrooms.id'));
        if ($u->role === Role::Student) {
            $researchIds = DB::table('research_assessments')->whereIn('research_study_id', DB::table('research_enrollments')->where('student_id', $u->id)->whereIn('research_study_id', DB::table('research_studies')->where('status', 'active')->select('id'))->select('research_study_id'))->select('assessment_id');
            $q->where('status', 'published')->where(fn ($access) => $access->where(fn ($normal) => $normal->where('purpose', '!=', 'research')->whereHas('lesson', fn ($l) => $l->where('status', '!=', 'draft')->whereIn('course_id', Course::visibleTo($u)->select('id'))))->orWhere(fn ($research) => $research->where('purpose', 'research')->whereIn('id', $researchIds)));
        }

        return $q;
    }

    public function canView(User $u): bool
    {
        return self::visibleTo($u)->whereKey($this->id)->exists();
    }

    public static function questionHash(array $questions): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonical($item);
                }
            }

return $value;
        };

        return hash('sha256', json_encode($canonical($questions),JSON_THROW_ON_ERROR));
    }
}
