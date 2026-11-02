<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\LearningGroup;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolLmsTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    private Course $course;

    private CourseLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = Classroom::factory()->create();
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
        $this->course = Course::factory()->create(['classroom_id' => $this->classroom->id, 'created_by' => $this->teacher->id, 'updated_by' => $this->teacher->id]);
        $chapter = CourseChapter::factory()->create(['course_id' => $this->course->id]);
        $this->lesson = CourseLesson::factory()->create(['course_id' => $this->course->id, 'course_chapter_id' => $chapter->id]);
    }

    private function task(array $override = []): Assignment
    {
        return Assignment::factory()->create([...['classroom_id' => $this->classroom->id, 'course_lesson_id' => $this->lesson->id, 'created_by' => $this->teacher->id], ...$override]);
    }

    public function test_course_structure_is_scoped_and_can_be_copied_as_drafts(): void
    {
        $this->actingAs($this->student)->getJson('/api/v1/courses/'.$this->course->id)->assertOk()->assertJsonCount(1, 'data.chapters');
        $payload = ['classroom_id' => $this->classroom->id, 'subject' => 'Matematika', 'title' => 'Matematika kelas V', 'semester' => 'ganjil', 'method' => 'standard', 'description' => 'Bilangan dan pecahan', 'status' => 'published'];
        $this->postJson('/api/v1/courses', $payload)->assertForbidden();
        $id = $this->actingAs($this->teacher)->postJson('/api/v1/courses', $payload)->assertCreated()->json('data.id');
        $chapter = $this->postJson('/api/v1/courses/'.$id.'/chapters', ['title' => 'Bab pecahan', 'position' => 1])->assertCreated()->json('data.id');
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/v1/course-chapters/'.$chapter.'/lessons', ['title' => 'Pertemuan '.$i, 'objective' => 'Membandingkan pecahan', 'position' => $i, 'mode' => 'blended', 'status' => 'open'])->assertCreated();
        }
        $copy = $this->postJson('/api/v1/courses/'.$id.'/copy', ['classroom_id' => $this->classroom->id, 'title' => 'Matematika semester berikut', 'semester' => 'genap'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/courses/'.$copy)->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonCount(6, 'data.chapters.0.lessons');
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->getJson('/api/v1/courses/'.$id)->assertForbidden();
    }

    public function test_submission_pdf_revision_and_gradebook_preserve_snapshot_and_privacy(): void
    {
        Storage::fake('local');
        $task = $this->task(['due_at' => now()->subDay()]);
        $payload = ['request_id' => (string) Str::uuid(), 'version' => 0, 'status' => 'submitted', 'content' => 'Aku mengamati lingkungan.', 'file' => UploadedFile::fake()->createWithContent('karya.pdf', "%PDF-1.4\n%%EOF"), 'share_with_class' => false];
        $response = $this->actingAs($this->student)->post('/api/v1/assignments/'.$task->id.'/submissions', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.is_late', true)->assertJsonPath('data.source', 'online');
        $id = $response->json('data.id');
        $submission = AssignmentSubmission::findOrFail($id);
        $this->get('/api/v1/submissions/'.$id.'/file')->assertOk();
        Storage::disk('local')->assertExists($submission->file_path);
        $this->actingAs(User::factory()->create())->get('/api/v1/submissions/'.$id.'/file')->assertForbidden();
        $this->actingAs($this->teacher)->putJson('/api/v1/submissions/'.$id.'/review', ['version' => 1, 'status' => 'reviewed', 'score' => 80, 'feedback' => 'Bukti sudah jelas.'])->assertOk()->assertJsonPath('data.normalized_score', 80);
        $this->putJson('/api/v1/submissions/'.$id.'/review', ['version' => 1, 'status' => 'reviewed', 'score' => 90, 'feedback' => 'Stale'])->assertConflict();
        $task->update(['max_score' => 200, 'version' => 2]);
        $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$task->id.'/submissions', ['request_id' => (string) Str::uuid(), 'version' => 2, 'status' => 'submitted', 'content' => 'Jawaban diperbaiki'])->assertOk()->assertJsonPath('data.assignment_snapshot.max_score', 100)->assertJsonPath('data.score', null);
        $this->assertSame(3, DB::table('submission_revisions')->count());
        $this->getJson('/api/v1/gradebook?classroom_id='.$this->classroom->id)->assertOk()->assertJsonCount(1, 'data.students')->assertJsonPath('data.students.0.progress.tasks_submitted', 1)->assertJsonPath('data.students.0.progress.tasks_reviewed', 0);
    }

    public function test_group_work_gallery_and_peer_feedback_require_consent_and_moderation(): void
    {
        $second = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($second);
        $group = LearningGroup::factory()->create(['classroom_id' => $this->classroom->id]);
        $group->users()->attach([$this->student->id, $second->id]);
        $task = $this->task(['kind' => 'project', 'submission_mode' => 'group']);
        $payload = ['request_id' => (string) Str::uuid(), 'version' => 0, 'status' => 'submitted', 'content' => 'Poster hemat air', 'group_id' => $group->id, 'share_with_class' => true];
        $id = $this->actingAs($this->student)->postJson('/api/v1/assignments/'.$task->id.'/submissions', $payload)->assertOk()->json('data.id');
        $this->postJson('/api/v1/assignments/'.$task->id.'/submissions', $payload)->assertOk();
        $this->assertDatabaseCount('assignment_submissions', 1);
        $this->actingAs($second)->getJson('/api/v1/submissions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/gallery')->assertJsonCount(0, 'data');
        $this->actingAs($this->teacher)->putJson('/api/v1/submissions/'.$id.'/review', ['version' => 1, 'status' => 'reviewed', 'score' => 90, 'feedback' => 'Ajakan jelas', 'gallery_published' => true])->assertOk();
        $this->actingAs($second)->getJson('/api/v1/gallery')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.score');
        $this->putJson('/api/v1/submissions/'.$id.'/comments', ['appreciation' => 'Ajakan mudah dipahami.', 'question' => 'Bagaimana mengecek penghematan?'])->assertOk();
        $comment = DB::table('project_comments')->value('id');
        $this->actingAs($this->teacher)->patchJson('/api/v1/project-comments/'.$comment.'/moderate', ['is_hidden' => true])->assertOk();
        $this->actingAs($second)->getJson('/api/v1/submissions/'.$id.'/comments')->assertJsonCount(0, 'data');
    }

    public function test_offline_records_require_evidence_and_assigned_class(): void
    {
        $task = $this->task();
        $payload = ['request_id' => (string) Str::uuid(), 'version' => 0, 'status' => 'submitted', 'content' => 'Jawaban dari lembar cetak', 'student_id' => $this->student->id, 'offline_evidence' => 'Lembar tugas diperiksa guru.'];
        $this->actingAs($this->teacher)->postJson('/api/v1/assignments/'.$task->id.'/submissions', $payload)->assertOk()->assertJsonPath('data.source', 'offline');
        $this->postJson('/api/v1/course-lessons/'.$this->lesson->id.'/records', ['kind' => 'attendance', 'version' => 0, 'student_id' => $this->student->id, 'status' => 'present', 'evidence' => 'Hadir saat diskusi kelas.'])->assertOk();
        $this->postJson('/api/v1/course-lessons/'.$this->lesson->id.'/records', ['kind' => 'phase', 'version' => 0, 'phase' => 1, 'status' => 'done', 'evidence' => 'Peta ditandai bersama dan sumber dicatat.'])->assertOk();
        $this->actingAs($this->student)->getJson('/api/v1/course-lessons/'.$this->lesson->id.'/records')->assertOk()->assertJsonCount(1,'data.attendance')->assertJsonCount(0,'data.phases');
        $this->postJson('/api/v1/assignments/'.$task->id.'/submissions',$payload)->assertUnprocessable();
    }
}
