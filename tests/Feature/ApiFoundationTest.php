<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = Classroom::factory()->create();
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->student = User::factory()->create(['role' => Role::Student, 'school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'classroom_id' => $this->classroom->id, 'title' => 'Jaga laut', 'content' => 'Pilah sampah dengan aman.',
            'topic' => 0, 'status' => 'published',
            'question' => ['type' => 'choice', 'prompt' => 'Apa tindakan yang baik?', 'options' => ['Jaga alam', 'Rusak alam', 'Buang sampah', 'Abaikan'], 'correctIndex' => 0, 'modelAnswer' => '', 'explanation' => 'Saling peduli.'],
        ], $overrides);
    }

    private function material(array $overrides = []): Material
    {
        return Material::factory()->create([...$this->payload($overrides), 'teacher_id' => $this->teacher->id]);
    }

    public function test_database_is_mysql_and_private_routes_require_authentication(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('svil_app_testing', config('database.connections.mysql.database'));
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/materials')->assertUnauthorized();
        $this->getJson('/')->assertOk()->assertJsonPath('name', 'SVIL API');
    }

    public function test_admin_bootstrap_creates_an_account_without_overwriting_existing_users(): void
    {
        $this->artisan('svil:create-admin', ['email' => 'bootstrap@svil.test', '--name' => 'Bootstrap Admin'])
            ->expectsQuestion('Kata sandi admin (minimal 12 karakter)', 'AmanSekali!12345')
            ->assertExitCode(0);
        $this->assertSame(Role::Admin, User::where('email', 'bootstrap@svil.test')->firstOrFail()->role);
        $before = User::where('email', 'bootstrap@svil.test')->firstOrFail()->password;
        $this->artisan('svil:create-admin', ['email' => 'bootstrap@svil.test'])
            ->expectsQuestion('Kata sandi admin (minimal 12 karakter)', 'BerbedaSekali!12345')
            ->assertExitCode(1);
        $this->assertSame($before, User::where('email', 'bootstrap@svil.test')->firstOrFail()->password);
    }

    public function test_cookie_login_logout_and_inactive_account(): void
    {
        $headers = ['Origin' => 'http://127.0.0.1:3000'];
        $this->withHeaders($headers)->postJson('/api/v1/auth/login', ['email' => $this->student->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/api/v1/auth/login', ['email' => $this->student->email, 'password' => 'password', 'role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'student')->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->student->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $this->student->email, 'password' => 'password'])->assertUnprocessable();
        $this->actingAs($this->student)->getJson('/api/v1/materials')->assertForbidden();
    }

    public function test_students_cannot_create_materials_see_drafts_or_receive_answer_keys(): void
    {
        $published = $this->material();
        $draft = $this->material(['status' => 'draft']);
        $foreign = Material::factory()->create(['status' => 'published']);
        $this->actingAs($this->student)->getJson('/api/v1/materials')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question.hasKey', true)
            ->assertJsonMissingPath('data.0.question.correctIndex')
            ->assertJsonMissingPath('data.0.question.modelAnswer')
            ->assertJsonMissingPath('data.0.question.explanation');
        $this->getJson('/api/v1/materials/'.$published->id)->assertOk()->assertJsonMissingPath('data.question.correctIndex');
        $this->getJson('/api/v1/materials/'.$draft->id)->assertForbidden();
        $this->getJson('/api/v1/materials/'.$foreign->id)->assertForbidden();
        $this->postJson('/api/v1/materials', $this->payload())->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_teacher_can_publish_edit_and_detect_stale_updates(): void
    {
        $response = $this->actingAs($this->teacher)->postJson('/api/v1/materials', $this->payload())->assertCreated()->assertJsonPath('data.question.correctIndex', 0);
        $id = $response->json('data.id');
        $this->putJson('/api/v1/materials/'.$id, $this->payload(['version' => 1, 'title' => 'Judul baru', 'status' => 'draft']))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/materials/'.$id, $this->payload(['version' => 1]))->assertConflict();
        $foreignClass = Classroom::factory()->create();
        $this->postJson('/api/v1/materials', $this->payload(['classroom_id' => $foreignClass->id]))->assertUnprocessable();
        $foreign = Material::factory()->create();
        $this->putJson('/api/v1/materials/'.$foreign->id, $this->payload(['version' => 1]))->assertForbidden();
    }

    public function test_optional_keys_and_invalid_questions_are_validated(): void
    {
        $payload = $this->payload();
        $payload['question']['correctIndex'] = null;
        $this->actingAs($this->teacher)->postJson('/api/v1/materials', $payload)->assertCreated();
        $this->postJson('/api/v1/materials', $this->payload(['question' => null]))->assertCreated();
        $this->postJson('/api/v1/materials', $this->payload(['question' => '{broken']))->assertUnprocessable();
        $payload['question']['options'] = ['Only one'];
        $this->postJson('/api/v1/materials', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/materials', $this->payload(['question' => ['type' => 'text', 'prompt' => 'Ceritakan', 'options' => [], 'correctIndex' => null, 'modelAnswer' => 'Peduli', 'explanation' => '']]))->assertCreated();
    }

    public function test_question_visual_topic_is_validated_and_shared_without_the_key(): void
    {
        $payload = $this->payload();
        $payload['question']['visualTopic'] = 2;
        $response = $this->actingAs($this->teacher)->postJson('/api/v1/materials', $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->actingAs($this->student)->getJson('/api/v1/materials/'.$id)
            ->assertOk()->assertJsonPath('data.question.visualTopic', 2)
            ->assertJsonMissingPath('data.question.correctIndex');
        $payload['question']['visualTopic'] = 4;
        $this->actingAs($this->teacher)->postJson('/api/v1/materials', $payload)->assertUnprocessable();
    }

    public function test_existing_material_cannot_move_to_another_assigned_class(): void
    {
        $material = $this->material();
        $secondClass = Classroom::factory()->create(['school_id' => $this->classroom->school_id]);
        $secondClass->users()->attach($this->teacher);
        $this->actingAs($this->teacher)->putJson('/api/v1/materials/'.$material->id, $this->payload(['version' => 1, 'classroom_id' => $secondClass->id]))->assertUnprocessable();
        $this->assertSame($this->classroom->id, $material->fresh()->classroom_id);
    }

    public function test_images_are_validated_stored_privately_and_authorized(): void
    {
        Storage::fake('local');
        $response = $this->actingAs($this->teacher)->post('/api/v1/materials', [...$this->payload(), 'image' => UploadedFile::fake()->image('laut.png'), 'image_alt' => 'Ilustrasi laut'], ['Accept' => 'application/json'])->assertCreated();
        $material = Material::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($material->image_path);
        $this->actingAs($this->student)->get('/api/v1/materials/'.$material->id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/materials/'.$material->id.'/image')->assertForbidden();
        $this->actingAs($this->teacher)->post('/api/v1/materials', [...$this->payload(), 'image' => UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'), 'image_alt' => 'Tidak aman'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/materials', [...$this->payload(), 'image' => UploadedFile::fake()->image('big.png')->size(1100), 'image_alt' => 'Terlalu besar'], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_attempts_are_graded_on_server_and_retries_are_idempotent(): void
    {
        $material = $this->material();
        $payload = ['request_id' => (string) Str::uuid(), 'material_version' => 1, 'choice' => 0];
        $this->actingAs($this->student)->postJson('/api/v1/materials/'.$material->id.'/attempts', $payload)->assertOk()->assertJsonPath('data.correct', true)->assertJsonMissingPath('data.correctIndex');
        $this->postJson('/api/v1/materials/'.$material->id.'/attempts', $payload)->assertOk();
        $this->assertSame(1, MaterialAttempt::count());
        $this->postJson('/api/v1/materials/'.$material->id.'/attempts', [...$payload, 'request_id' => (string) Str::uuid(), 'choice' => 1])->assertOk()->assertJsonPath('data.correct', false);
        $material->update(['version' => 2]);
        $this->postJson('/api/v1/materials/'.$material->id.'/attempts', [...$payload, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->actingAs($this->teacher)->postJson('/api/v1/materials/'.$material->id.'/attempts', $payload)->assertForbidden();
    }

    public function test_open_answers_wait_for_teacher_review_and_empty_answers_fail(): void
    {
        $material = $this->material(['question' => ['type' => 'text', 'prompt' => 'Ceritakan perasaanmu', 'options' => [], 'modelAnswer' => 'PRIVATE', 'explanation' => 'Terima kasih.']]);
        $payload = ['request_id' => (string) Str::uuid(), 'material_version' => 1, 'answer' => 'Aku ingin membantu teman'];
        $this->actingAs($this->student)->postJson('/api/v1/materials/'.$material->id.'/attempts', $payload)->assertOk()->assertJsonPath('data.correct', null)->assertJsonPath('data.pendingReview', true);
        $this->postJson('/api/v1/materials/'.$material->id.'/attempts', [...$payload, 'request_id' => (string) Str::uuid(), 'answer' => ''])->assertUnprocessable();
        $this->getJson('/api/v1/materials/'.$material->id)->assertJsonMissingPath('data.question.modelAnswer');
    }

    public function test_notes_are_private_scoped_and_feedback_is_reset_on_revision(): void
    {
        $payload = ['classroom_id' => $this->classroom->id, 'topic' => 0, 'phase' => 3, 'content' => 'Aku bersyukur.'];
        $response = $this->actingAs($this->student)->putJson('/api/v1/notes', $payload)->assertOk();
        $noteId = $response->json('data.id');
        $other = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($other);
        $this->actingAs($other)->getJson('/api/v1/notes')->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/notes/'.$noteId.'/review', ['feedback' => 'Tidak diizinkan'])->assertForbidden();
        $this->actingAs($this->teacher)->putJson('/api/v1/notes/'.$noteId.'/review', ['feedback' => 'Terima kasih sudah bercerita'])->assertOk();
        $this->actingAs($this->student)->putJson('/api/v1/notes', [...$payload, 'content' => 'Aku mau memperbaiki kebiasaan.'])->assertOk()->assertJsonPath('data.feedback', null);
        $this->assertSame(1, LearningNote::count());
        $this->putJson('/api/v1/notes', [...$payload, 'classroom_id' => Classroom::factory()->create()->id])->assertUnprocessable();
    }

    public function test_admin_can_provision_accounts_and_reject_cross_school_memberships(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $school = School::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/schools', ['code' => 'NEW-SCHOOL', 'name' => 'Madrasah Baru'])->assertCreated();
        $this->postJson('/api/v1/classrooms', ['school_id' => $school->id, 'name' => 'V A', 'academic_year' => '2026/2027'])->assertCreated();
        $this->postJson('/api/v1/users', ['name' => 'Siswa baru', 'email' => 'new@svil.test', 'password' => 'AmanSekali!1234', 'role' => 'student', 'school_id' => $this->classroom->school_id, 'classroom_ids' => [$this->classroom->id]])->assertCreated()->assertJsonPath('data.role', 'student');
        $this->postJson('/api/v1/classrooms/'.$this->classroom->id.'/members', ['user_ids' => [User::factory()->create(['school_id' => $school->id])->id]])->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$admin->id.'/active', ['is_active' => false])->assertUnprocessable();
        $this->actingAs($this->teacher)->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_reports_and_groups_are_scoped_and_csv_cells_are_safe(): void
    {
        LearningNote::factory()->create(['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id, 'content' => '=HYPERLINK("bad")']);
        LearningNote::factory()->create(['content' => 'FOREIGN PRIVATE NOTE']);
        $this->actingAs($this->teacher)->getJson('/api/v1/reports/summary')->assertOk()->assertJsonPath('data.notes', 1)->assertJsonPath('data.researchInstrumentsReady', false);
        $csv = $this->get('/api/v1/reports/notes.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('FOREIGN PRIVATE NOTE', $csv);
        $this->postJson('/api/v1/groups', ['classroom_id' => $this->classroom->id, 'name' => 'Nusantara', 'student_ids' => [$this->student->id]])->assertCreated();
        $this->postJson('/api/v1/groups', ['classroom_id' => $this->classroom->id, 'name' => 'Kedua', 'student_ids' => [$this->student->id]])->assertUnprocessable();
        $this->actingAs($this->student)->getJson('/api/v1/reports/summary')->assertForbidden();
    }
}
