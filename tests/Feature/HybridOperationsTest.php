<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\LearningNote;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HybridOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $admin;

    private User $teacher;

    private User $student;

    private CourseLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = Classroom::factory()->create();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
        $course = Course::factory()->create(['classroom_id' => $this->classroom->id]);
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
        $this->lesson = CourseLesson::factory()->create(['course_id' => $course->id, 'course_chapter_id' => $chapter->id]);
    }

    public function test_lkpd_video_is_class_scoped_and_only_stores_the_normalized_youtube_id(): void
    {
        $m = LearningModule::factory()->create();
        $payload = ['classroom_id' => $this->classroom->id, 'version' => 0, 'youtube_url' => 'https://youtu.be/abcdefghijk?si=ignored', 'video_caption' => 'Amati sikap saling membantu.'];
        $this->actingAs($this->teacher)->putJson('/api/v1/learning-modules/'.$m->id.'/video', $payload)->assertOk()->assertJsonPath('data.youtube_video_id', 'abcdefghijk');
        $this->actingAs($this->student)->getJson('/api/v1/learning-modules/'.$m->id.'?classroom_id='.$this->classroom->id)->assertOk()->assertJsonPath('data.videoId', 'abcdefghijk');
        $this->putJson('/api/v1/learning-modules/'.$m->id.'/video', $payload)->assertForbidden();
        $this->actingAs($this->teacher)->putJson('/api/v1/learning-modules/'.$m->id.'/video', [...$payload, 'version' => 1, 'youtube_url' => 'https://www.youtube.com.evil.test/watch?v=abcdefghijk'])->assertUnprocessable();
        $this->putJson('/api/v1/learning-modules/'.$m->id.'/video', [...$payload, 'version' => 1, 'youtube_url' => 'https://user@youtube.com/watch?v=abcdefghijk'])->assertUnprocessable();
        $other = Classroom::factory()->create();
        $this->putJson('/api/v1/learning-modules/'.$m->id.'/video', [...$payload, 'classroom_id' => $other->id])->assertUnprocessable();
    }

    public function test_transfer_keeps_old_answers_and_rollover_copies_structure_without_results(): void
    {
        $note = LearningNote::factory()->create(['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id]);
        $target = Classroom::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->actingAs($this->admin)->postJson('/api/v1/classrooms/'.$this->classroom->id.'/members/'.$this->student->id, ['operation' => 'transfer', 'target_classroom_id' => $target->id])->assertOk();
        $this->assertDatabaseHas('classroom_user', ['classroom_id' => $this->classroom->id, 'user_id' => $this->student->id, 'is_active' => false]);
        $this->assertDatabaseHas('learning_notes', ['id' => $note->id]);
        $this->actingAs($this->student)->getJson('/api/v1/learning-history')->assertOk()->assertJsonFragment(['content' => $note->content]);
        $this->getJson('/api/v1/classrooms')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $next = $this->actingAs($this->admin)->postJson('/api/v1/classrooms/'.$this->classroom->id.'/rollover', ['name' => 'VI', 'academic_year' => '2027/2028', 'copy_structure' => true, 'archive_source' => true])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('classrooms', ['id' => $next, 'academic_year' => '2027/2028']);
        $this->assertDatabaseHas('courses', ['classroom_id' => $next, 'status' => 'draft']);
        $this->assertDatabaseCount('learning_notes', 1);
        $this->actingAs($this->teacher)->postJson('/api/v1/materials', ['classroom_id' => $this->classroom->id])->assertUnprocessable();
    }

    public function test_late_policy_and_teacher_reopening_are_enforced_by_the_server(): void
    {
        $a = Assignment::factory()->create(['classroom_id' => $this->classroom->id, 'course_lesson_id' => $this->lesson->id, 'created_by' => $this->teacher->id, 'due_at' => now()->subDay(), 'allow_late' => false, 'max_submissions' => 1]);
        $submit = ['version' => 0, 'request_id' => (string) Str::uuid(), 'content' => 'Hasil belajarku', 'status' => 'submitted'];
        $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$a->id.'/submissions', $submit)->assertUnprocessable();
        $this->actingAs($this->teacher)->putJson('/api/v1/assignments/'.$a->id.'/reopen', ['student_id' => $this->student->id, 'version' => 0, 'valid_until' => now()->addDay()->toIso8601String(), 'extra_submissions' => 1, 'reason' => 'Kesempatan melengkapi tugas.'])->assertOk();
        $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$a->id.'/submissions', $submit)->assertOk()->assertJsonPath('data.submission_count', 1);
        $this->postJson('/api/v1/assignments/'.$a->id.'/submissions', [...$submit, 'version' => 1, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_notifications_are_private_and_dashboard_counts_new_modules(): void
    {
        $this->actingAs($this->teacher)->postJson('/api/v1/assignments', ['course_lesson_id' => $this->lesson->id, 'title' => 'Tugas Peduli', 'instructions' => 'Kerjakan bersama.', 'kind' => 'task', 'submission_mode' => 'individual', 'status' => 'published', 'max_score' => 100])->assertCreated();
        $response = $this->actingAs($this->student)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1);
        $id = $response->json('data.0.id');
        $other = User::factory()->create();
        $this->actingAs($other)->putJson('/api/v1/notifications/read', ['ids' => [$id]])->assertOk();
        $this->assertDatabaseHas('learning_notifications', ['id' => $id, 'read_at' => null]);
        $this->actingAs($this->student)->putJson('/api/v1/notifications/read', ['ids' => [$id]])->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
        $this->actingAs($this->teacher)->getJson('/api/v1/reports/summary?classroom_id='.$this->classroom->id)->assertOk()->assertJsonPath('data.assignments', 1)->assertJsonPath('data.courses', 1);
    }

    public function test_group_changes_preserve_frozen_members_and_historical_lkpd_results(): void
    {
        $peer = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($peer);
        $group = LearningGroup::factory()->create(['classroom_id' => $this->classroom->id]);
        $group->users()->attach([$peer->id, $this->student->id]);
        $work = GroupWork::factory()->create(['learning_group_id' => $group->id, 'member_ids' => [$this->student->id, $peer->id], 'status' => 'reviewed', 'answers' => ['fact' => 'Hasil pengamatan bersama.']]);
        $this->actingAs($this->teacher)->putJson('/api/v1/groups/'.$group->id, ['name' => 'Kelompok diperbarui', 'version' => 1, 'student_ids' => [$peer->id]])->assertOk()->assertJsonCount(1, 'data.users');
        $this->assertDatabaseHas('learning_group_user', ['learning_group_id' => $group->id, 'user_id' => $this->student->id, 'is_active' => false]);
        $this->assertSame([$this->student->id, $peer->id], $work->fresh()->member_ids);
        $roster = $this->getJson('/api/v1/gradebook?classroom_id='.$this->classroom->id)->assertOk()->json('data.students');
        $student = collect($roster)->firstWhere('student_id', $this->student->id);
        $this->assertSame($work->id, $student['group_work'][0]['id']);
        $this->getJson('/api/v1/student-reviews/'.$this->student->id.'?classroom_id='.$this->classroom->id)->assertOk()->assertJsonPath('data.works.0.id', $work->id);
        $this->actingAs($this->student)->getJson('/api/v1/learning-history')->assertOk()->assertJsonPath('data.0.group_work.0.id', $work->id);
    }

    public function test_review_lock_and_archived_source_copy_are_deliberate_and_scoped(): void
    {
        $assignment = Assignment::factory()->create(['classroom_id' => $this->classroom->id, 'course_lesson_id' => $this->lesson->id, 'created_by' => $this->teacher->id, 'lock_after_review' => true]);
        $submission = $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$assignment->id.'/submissions', ['version' => 0, 'request_id' => (string) Str::uuid(), 'content' => 'Jawaban pertama', 'status' => 'submitted'])->assertOk()->json('data');
        $this->actingAs($this->teacher)->putJson('/api/v1/submissions/'.$submission['id'].'/review', ['version' => 1, 'status' => 'reviewed', 'score' => 80, 'feedback' => 'Sudah baik.'])->assertOk();
        $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$assignment->id.'/submissions', ['version' => 2, 'request_id' => (string) Str::uuid(), 'content' => 'Perubahan tanpa izin', 'status' => 'submitted'])->assertUnprocessable();
        $target = Classroom::factory()->create(['school_id' => $this->classroom->school_id]);
        $target->users()->attach($this->teacher);
        $this->lesson->course->update(['status' => 'archived']);
        $this->classroom->update(['archived_at' => now()]);
        $this->actingAs($this->teacher)->postJson('/api/v1/courses/'.$this->lesson->course_id.'/copy', ['classroom_id' => $target->id, 'title' => 'Struktur semester baru', 'semester' => 'genap'])->assertCreated();
    }

    public function test_calendar_attendance_and_exports_respect_class_scope(): void
    {
        $this->lesson->update(['scheduled_on' => now()->toDateString()]);
        DB::table('lesson_attendances')->insert(['course_lesson_id' => $this->lesson->id, 'student_id' => $this->student->id, 'status' => 'present', 'evidence' => 'Terlihat mengikuti diskusi.', 'recorded_by' => $this->teacher->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $query = '?classroom_id='.$this->classroom->id;
        $this->actingAs($this->student)->getJson('/api/v1/calendar'.$query)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/attendance-summary'.$query)->assertOk()->assertJsonCount(1, 'data.students')->assertJsonPath('data.students.0.present', 1);
        $this->getJson('/api/v1/attendance-summary.csv'.$query)->assertForbidden();
        $this->actingAs($this->teacher)->get('/api/v1/attendance-summary.csv'.$query)->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/calendar'.$query)->assertUnprocessable();
    }
}
