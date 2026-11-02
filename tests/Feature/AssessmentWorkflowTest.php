<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\QuestionBankItem;
use App\Models\ResearchStudy;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    private CourseLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = Classroom::factory()->create();
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
        $course = Course::factory()->create(['classroom_id' => $this->classroom->id]);
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);
        $this->lesson = CourseLesson::factory()->create(['course_id' => $course->id, 'course_chapter_id' => $chapter->id]);
    }

    private function questions(): array
    {
        $items = [];
        foreach (range(1, 8) as $i) {
            $items[] = ['id' => 'q'.$i, 'type' => 'choice', 'prompt' => 'Pilih tindakan peduli '.$i, 'options' => ['Bantu', 'Ejek', 'Rusak', 'Abaikan'], 'correctIndex' => 0, 'points' => 6, 'cognitive_level' => 'C3'];
        }foreach ([9, 10] as $i) {
            $items[] = ['id' => 'q'.$i, 'type' => 'text', 'prompt' => 'Jelaskan alasan '.$i, 'options' => [], 'points' => 13, 'modelAnswer' => 'Pedoman rahasia', 'cognitive_level' => 'C4'];
        }

return $items;
    }

    private function assessment(array $overrides = []): Assessment
    {
        return Assessment::factory()->create([...['classroom_id' => $this->classroom->id, 'course_lesson_id' => $this->lesson->id, 'created_by' => $this->teacher->id, 'questions' => $this->questions(), 'max_score' => 74], ...$overrides]);
    }

    public function test_sumative_74_points_snapshot_and_manual_reviews_are_correct_and_private(): void
    {
        $a = $this->assessment();
        $this->actingAs($this->student)->getJson('/api/v1/assessments')->assertOk()->assertJsonMissingPath('data.0.questions.0.correctIndex')->assertJsonMissingPath('data.0.questions.8.modelAnswer');
        $start = $this->postJson('/api/v1/assessments/'.$a->id.'/start')->assertOk()->assertJsonPath('data.max_score', 74);
        $id = $start->json('data.id');
        $this->postJson('/api/v1/assessments/'.$a->id.'/start')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('assessment_attempts', 1);
        $answers = array_fill_keys(array_map(fn ($i) => 'q'.$i, range(1, 8)), 0);
        $answers += ['q9' => 'Alam memengaruhi pekerjaan.', 'q10' => 'Mempertimbangkan warga dan lingkungan.'];
        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/assessment-attempts/'.$id.'/submit', ['request_id' => $requestId, 'version' => 1, 'answers' => $answers])->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.raw_score', null);
        $this->postJson('/api/v1/assessment-attempts/'.$id.'/submit', ['request_id' => $requestId, 'version' => 1, 'answers' => $answers])->assertOk();
        $a->update(['questions' => [['id' => 'new', 'type' => 'text', 'prompt' => 'Changed', 'options' => [], 'points' => 100]], 'max_score' => 100, 'version' => 2]);
        $this->actingAs($this->teacher)->putJson('/api/v1/assessment-attempts/'.$id.'/review', ['version' => 2, 'scores' => ['q9' => 10, 'q10' => 12], 'feedback' => 'Argumen dan bukti sudah jelas.'])->assertOk()->assertJsonPath('data.raw_score', 70)->assertJsonPath('data.max_score', 74)->assertJsonPath('data.normalized_score', 94.59);
        $this->putJson('/api/v1/assessment-attempts/'.$id.'/review', ['version' => 3, 'scores' => ['q1' => 0, 'q9' => 10, 'q10' => 12], 'feedback' => 'Override'])->assertUnprocessable();
        $this->actingAs($this->student)->getJson('/api/v1/assessment-attempts')->assertOk()->assertJsonMissingPath('data.0.questions.0.correctIndex')->assertJsonPath('data.0.feedback', 'Argumen dan bukti sudah jelas.');
    }

    public function test_expired_submission_uses_saved_answers_and_cannot_accept_late_edits(): void
    {
        $a = $this->assessment(['duration_minutes' => 1]);
        $id = $this->actingAs($this->student)->postJson('/api/v1/assessments/'.$a->id.'/start')->json('data.id');
        $this->putJson('/api/v1/assessment-attempts/'.$id.'/draft', ['version' => 1, 'answers' => ['q1' => 0]])->assertOk()->assertJsonPath('data.version', 2);
        $this->travel(2)->minutes();
        $this->putJson('/api/v1/assessment-attempts/'.$id.'/draft', ['version' => 2, 'answers' => ['q1' => 1]])->assertUnprocessable();
        $this->postJson('/api/v1/assessment-attempts/'.$id.'/submit', ['request_id' => (string) Str::uuid(), 'version' => 2, 'answers' => ['q1' => 1]])->assertOk()->assertJsonPath('data.answers.q1', 0)->assertJsonPath('data.item_scores.q1', 6);
    }

    public function test_bank_questions_and_schedules_are_scoped(): void
    {
        $a = $this->assessment(['opens_at' => now()->addDay()]);
        $this->actingAs($this->student)->postJson('/api/v1/assessments/'.$a->id.'/start')->assertUnprocessable();
        $this->getJson('/api/v1/question-bank')->assertForbidden();
        $this->actingAs($this->teacher)->postJson('/api/v1/question-bank', ['classroom_id' => $this->classroom->id, 'subject' => 'IPAS', 'question' => $this->questions()[0]])->assertCreated();
        $bank = QuestionBankItem::firstOrFail();
        $payload = ['course_lesson_id' => $this->lesson->id, 'title' => 'Asesmen baru', 'instructions' => 'Jawab dengan tenang', 'purpose' => 'practice', 'status' => 'published', 'max_attempts' => 1, 'questions' => [['bank_item_id' => $bank->id, 'bank_version' => 1, 'id' => 'reuse', 'points' => 7]]];
        $this->postJson('/api/v1/assessments', $payload)->assertCreated()->assertJsonPath('data.max_score', 7)->assertJsonPath('data.questions.0.correctIndex', 0);
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->postJson('/api/v1/assessments', $payload)->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/assessments/'.$a->id.'/start')->assertForbidden();
    }

    public function test_bank_edits_keep_existing_assessments_and_reject_stale_versions(): void
    {
        $bank = QuestionBankItem::factory()->create(['classroom_id' => $this->classroom->id, 'created_by' => $this->teacher->id, 'question' => $this->questions()[0]]);
        $payload = ['version' => 1, 'subject' => 'IPAS', 'category' => 'Peduli', 'question' => [...$this->questions()[0], 'prompt' => 'Butir hasil revisi']];
        $this->actingAs($this->teacher)->putJson('/api/v1/question-bank/'.$bank->id, $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/question-bank/'.$bank->id, $payload)->assertConflict();
        $this->actingAs($this->student)->putJson('/api/v1/question-bank/'.$bank->id, [...$payload, 'version' => 2])->assertForbidden();
    }

    public function test_research_requires_an_active_approved_link_with_stable_question_hash(): void
    {
        $a = $this->assessment(['purpose' => 'research']);
        $this->actingAs($this->student)->getJson('/api/v1/assessments')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/assessments/'.$a->id.'/start')->assertForbidden();
        $study = ResearchStudy::factory()->create(['status' => 'active']);
        DB::table('research_enrollments')->insert(['research_study_id' => $study->id, 'student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'arm' => 'experimental', 'created_at' => now(), 'updated_at' => now()]);
        $reordered = array_map(fn ($q) => array_reverse($q, true), $a->questions);
        $this->assertSame(Assessment::questionHash($a->questions), Assessment::questionHash($reordered));
        DB::table('research_assessments')->insert(['research_study_id' => $study->id, 'assessment_id' => $a->id, 'period' => 'pre', 'approval_reference' => 'Persetujuan sumber', 'assessment_hash' => Assessment::questionHash($reordered), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/assessments/'.$a->id.'/start')->assertOk();
        $a->update(['questions' => [['id' => 'changed', 'prompt' => 'Belum disetujui', 'type' => 'choice', 'options' => ['A', 'B', 'C', 'D'], 'correctIndex' => 0, 'points' => 6]]]);
        $this->postJson('/api/v1/assessments/'.$a->id.'/start')->assertForbidden();
    }
}
