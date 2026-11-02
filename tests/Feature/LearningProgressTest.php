<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_opened_timestamps_default_on_insert_and_do_not_change_on_unrelated_updates(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $id = DB::table('learning_progress')->insertGetId([
            'student_id' => $student->id,
            'classroom_id' => $class->id,
            'target_type' => 'material',
            'target_key' => 'timestamp-default-test',
            'content_version' => 1,
        ]);
        $progress = DB::table('learning_progress')->find($id);
        $this->assertGreaterThan(0, strtotime($progress->first_opened_at));
        $this->assertSame($progress->first_opened_at, $progress->last_opened_at);
        $this->assertNull($progress->completed_at);

        $opened = '2020-01-01 08:00:00';
        DB::table('learning_progress')->where('id', $id)->update(['first_opened_at' => $opened, 'last_opened_at' => $opened]);
        DB::table('learning_progress')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now()]);
        $progress = DB::table('learning_progress')->find($id);
        $this->assertSame($opened, $progress->first_opened_at);
        $this->assertSame($opened, $progress->last_opened_at);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_material_completion_is_individual_versioned_and_required_by_the_lesson(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $peer = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach([$student->id, $peer->id]);
        $course = Course::factory()->create(['classroom_id' => $class->id]);
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
        $lesson = CourseLesson::factory()->create(['course_id' => $course->id, 'course_chapter_id' => $chapter->id, 'completion_rule' => 'materials']);
        $m = Material::factory()->create(['classroom_id' => $class->id, 'course_lesson_id' => $lesson->id, 'kind' => 'material', 'status' => 'published']);
        $this->actingAs($student)->putJson('/api/v1/course-lessons/'.$lesson->id.'/progress', ['status' => 'completed', 'version' => 0, 'content_version' => 1])->assertUnprocessable();
        $path = '/api/v1/materials/'.$m->id.'/progress';
        $body = ['status' => 'completed', 'version' => 0, 'content_version' => 1];
        $this->putJson($path, $body)->assertOk();
        $this->putJson($path, $body)->assertOk();
        $this->getJson('/api/v1/course-lessons/'.$lesson->id.'/progress')->assertOk()->assertJsonPath('criteria.materials_done', 1)->assertJsonPath('criteria.eligible', true);
        $this->putJson('/api/v1/course-lessons/'.$lesson->id.'/progress', ['status' => 'completed', 'version' => 0, 'content_version' => 1])->assertOk();
        $this->actingAs($peer)->getJson($path)->assertOk()->assertJsonPath('data', null);
        $m->update(['version' => 2]);
        $this->actingAs($student)->getJson($path)->assertJsonPath('is_current', false);
        $this->getJson('/api/v1/learning-overview?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.lessons.0.status', 'updated');
        $this->putJson($path, $body)->assertConflict();
        $this->getJson('/api/v1/course-lessons/'.$lesson->id.'/progress')->assertJsonPath('criteria.eligible', false)->assertJsonPath('is_current', false);
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
    }

    public function test_activity_completion_uses_the_latest_practice_score_instead_of_an_old_pass(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach($student);
        $course = Course::factory()->create(['classroom_id' => $class->id]);
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
        $lesson = CourseLesson::factory()->create(['course_id' => $course->id, 'course_chapter_id' => $chapter->id, 'completion_rule' => 'activities', 'minimum_score' => 50]);
        $quiz = Material::factory()->create(['classroom_id' => $class->id, 'course_lesson_id' => $lesson->id, 'kind' => 'quiz', 'status' => 'published']);
        $path = '/api/v1/course-lessons/'.$lesson->id.'/progress';
        $this->actingAs($student)->getJson($path)->assertOk()->assertJsonPath('criteria.eligible', false);
        MaterialAttempt::factory()->create(['material_id' => $quiz->id, 'student_id' => $student->id, 'correct' => true]);
        $this->getJson($path)->assertOk()->assertJsonPath('criteria.assessments_done', 1)->assertJsonPath('criteria.eligible', true);
        $this->putJson($path, ['status' => 'completed', 'version' => 0, 'content_version' => 1])->assertOk();
        MaterialAttempt::factory()->create(['material_id' => $quiz->id, 'student_id' => $student->id, 'correct' => false]);
        $this->getJson($path)->assertOk()->assertJsonPath('criteria.assessments_done', 0)->assertJsonPath('is_current', false);
        $this->putJson($path, ['status' => 'completed', 'version' => 1, 'content_version' => 1])->assertUnprocessable();
    }
}
