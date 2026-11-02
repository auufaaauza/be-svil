<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = Classroom::factory()->create();
        $this->teacher = User::factory()->create(['school_id' => $this->classroom->school_id, 'role' => Role::Teacher]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
    }

    private function attempt(array $question = []): MaterialAttempt
    {
        $question = $question ?: ['type' => 'text', 'prompt' => 'Apa yang dapat kita lakukan?', 'modelAnswer' => 'Pedoman pribadi guru'];
        $material = Material::factory()->create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->classroom->id, 'status' => 'published', 'question' => $question]);

        return MaterialAttempt::factory()->create(['material_id' => $material->id, 'student_id' => $this->student->id, 'question_snapshot' => $question, 'answer' => ['text' => 'Membantu teman'], 'correct' => null]);
    }

    private function observationPayload(): array
    {
        return ['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id, 'topic' => 0, 'occasion' => 1, 'version' => 0, 'observed_on' => today()->format('Y-m-d'), 'scores' => ['participation' => 4, 'empathy' => 3, 'honesty' => 4, 'responsibility' => 3], 'evidence' => 'Mendengarkan dan bergantian saat diskusi.', 'feedback' => 'Terus bantu temanmu dengan sabar.'];
    }

    public function test_open_answer_review_is_scoped_versioned_and_private(): void
    {
        $attempt = $this->attempt();
        $url = '/api/v1/attempts/'.$attempt->id.'/review';
        $review = ['version' => 0, 'correct' => null, 'feedback' => 'Terima kasih sudah peduli.'];
        $this->actingAs($this->student)->putJson($url, $review)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->putJson($url, $review)->assertForbidden();
        $this->actingAs($this->teacher)->getJson('/api/v1/attempts')->assertOk()->assertJsonPath('data.0.question_snapshot.modelAnswer', 'Pedoman pribadi guru');
        $this->putJson($url, $review)->assertOk()->assertJsonPath('data.review_version', 1)->assertJsonMissingPath('data.question_snapshot');
        $this->putJson($url, $review)->assertConflict();
        $this->assertDatabaseCount('attempt_reviews', 1);
        $this->actingAs($this->student)->getJson('/api/v1/attempts')->assertOk()->assertJsonMissingPath('data.0.question_snapshot')->assertJsonPath('data.0.teacher_feedback', $review['feedback']);
        $this->actingAs(User::factory()->create(['school_id' => $this->classroom->school_id]))->getJson('/api/v1/attempts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_keyed_choices_cannot_be_overridden_and_feedback_only_reviews_finish(): void
    {
        $keyed = $this->attempt(['type' => 'choice', 'prompt' => 'Pilih', 'options' => ['A', 'B', 'C', 'D'], 'correctIndex' => 0]);
        $this->actingAs($this->teacher)->putJson('/api/v1/attempts/'.$keyed->id.'/review', ['version' => 0, 'correct' => false, 'feedback' => 'Ubah'])->assertUnprocessable();
        $open = $this->attempt();
        $this->putJson('/api/v1/attempts/'.$open->id.'/review', ['version' => 0, 'correct' => null, 'feedback' => 'Cerita dan perasaanmu dihargai.'])->assertOk();
        $this->actingAs($this->student)->postJson('/api/v1/materials/'.$open->material_id.'/attempts', ['request_id' => $open->request_id, 'material_version' => 1, 'answer' => 'Membantu teman'])->assertOk()->assertJsonPath('data.pendingReview', false)->assertJsonPath('data.teacherFeedback', 'Cerita dan perasaanmu dihargai.');
    }

    public function test_observations_have_two_occasions_bounds_and_revision_evidence(): void
    {
        $payload = $this->observationPayload();
        $this->actingAs($this->teacher)->postJson('/api/v1/observations', $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson('/api/v1/observations', $payload)->assertConflict();
        $this->postJson('/api/v1/observations', [...$payload, 'occasion' => 2])->assertOk();
        $payload['version'] = 1;
        $payload['scores']['empathy'] = 5;
        $this->postJson('/api/v1/observations', $payload)->assertUnprocessable();
        $payload['scores']['empathy'] = 4;
        $this->postJson('/api/v1/observations', $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('observations', 2);
        $this->assertDatabaseCount('observation_revisions', 3);
        $snapshot = json_decode(DB::table('observation_revisions')->orderBy('id')->value('snapshot'), true);
        $this->assertSame(3, $snapshot['scores']['empathy']);
        $this->actingAs($this->student)->getJson('/api/v1/observations')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/observations', $payload)->assertForbidden();
    }

    public function test_observations_cannot_cross_classes_or_leak_to_other_students(): void
    {
        $payload = $this->observationPayload();
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->postJson('/api/v1/observations', $payload)->assertUnprocessable();
        $this->actingAs($this->teacher)->postJson('/api/v1/observations', [...$payload, 'student_id' => User::factory()->create()->id])->assertUnprocessable();
        $this->postJson('/api/v1/observations', [...$payload, 'observed_on' => today()->addDay()->format('Y-m-d')])->assertUnprocessable();
        $this->postJson('/api/v1/observations', $payload)->assertOk();
        $other = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($other);
        $this->actingAs($other)->getJson('/api/v1/observations')->assertOk()->assertJsonCount(0,'data');
    }
}
