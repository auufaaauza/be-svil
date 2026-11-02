<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ReflectionController;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\LearningModule;
use App\Models\ResearchInstrument;
use App\Models\ResearchStudy;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private User $teacher;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->classroom = Classroom::factory()->create();
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->student->id, $this->teacher->id]);
    }

    private function study(string $arm = 'experimental'): ResearchStudy
    {
        $s = ResearchStudy::factory()->create(['created_by' => $this->admin->id, 'status' => 'active']);
        DB::table('research_enrollments')->insert(['research_study_id' => $s->id, 'student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'arm' => $arm, 'created_at' => now(), 'updated_at' => now()]);

        return $s;
    }

    public function test_active_controls_do_not_receive_svil_intervention_but_keep_standard_courses(): void
    {
        $s = $this->study('control');
        LearningModule::factory()->create();
        Course::factory()->create(['classroom_id' => $this->classroom->id, 'method' => 'svil']);
        Course::factory()->create(['classroom_id' => $this->classroom->id, 'method' => 'standard']);
        $this->actingAs($this->student)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.canUseSvil', false);
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.method', 'standard');
        $this->getJson('/api/v1/learning-modules')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/reflections', ['classroom_id' => $this->classroom->id, 'topic' => 0, 'version' => 0, 'answers' => []])->assertForbidden();
        $s->update(['status' => 'closed']);
        $this->getJson('/api/v1/me')->assertJsonPath('data.canUseSvil', true);
    }

    public function test_approved_surveys_are_scheduled_versioned_and_keep_raw_and_reverse_scores(): void
    {
        $s = $this->study();
        $instrument = ResearchInstrument::factory()->create(['research_study_id' => $s->id, 'status' => 'draft', 'target_role' => 'student', 'period' => 'pre', 'items' => [['id' => 'i1', 'label' => 'Mudah digunakan', 'type' => 'scale', 'min' => 1, 'max' => 4, 'dimension' => 'usability', 'reverse' => true]]]);
        $payload = ['request_id' => (string) Str::uuid(), 'period' => 'pre', 'answers' => ['i1' => 1]];
        $this->actingAs($this->student)->postJson('/api/v1/research-instruments/'.$instrument->id.'/responses', $payload)->assertUnprocessable();
        $instrument->update(['status' => 'approved', 'approval_reference' => 'Dokumen pengesahan pengujian', 'opens_at' => now()->addDay()]);
        $this->postJson('/api/v1/research-instruments/'.$instrument->id.'/responses', $payload)->assertUnprocessable();
        $instrument->update(['opens_at' => null]);
        $this->postJson('/api/v1/research-instruments/'.$instrument->id.'/responses', $payload)->assertOk();
        $this->postJson('/api/v1/research-instruments/'.$instrument->id.'/responses', $payload)->assertOk();
        $this->assertDatabaseCount('research_responses', 1);
        $row = DB::table('research_responses')->first();
        $this->assertSame(1, json_decode($row->answers, true)['i1']);
        $this->assertSame(4, json_decode($row->scores, true)['usability']);
        $this->postJson('/api/v1/research-instruments/'.$instrument->id.'/responses', [...$payload, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/research-studies/'.$s->id.'/responses')->assertForbidden();
    }

    public function test_research_snapshot_is_private_stable_and_hash_verifiable(): void
    {
        Storage::fake('local');
        $s = $this->study();
        $this->actingAs($this->student)->postJson('/api/v1/research-studies/'.$s->id.'/exports')->assertForbidden();
        $record = ['kind' => 'field_note', 'source_reference' => 'Catatan pertemuan pertama', 'content' => 'Siswa berdiskusi dan mencari fakta.'];
        $this->actingAs($this->admin)->postJson('/api/v1/research-studies/'.$s->id.'/records', $record)->assertCreated();
        $result = $this->postJson('/api/v1/research-studies/'.$s->id.'/exports')->assertCreated()->json('data');
        $first = $this->get($result['download_url'])->assertOk()->streamedContent();
        $this->assertSame($result['sha256'], hash('sha256', $first));
        $this->postJson('/api/v1/research-studies/'.$s->id.'/records', [...$record, 'content' => 'Catatan setelah ekspor'])->assertCreated();
        $this->assertSame($first, $this->get($result['download_url'])->assertOk()->streamedContent());
        $this->assertStringNotContainsString('Catatan setelah ekspor', $first);
        $this->assertStringNotContainsString($this->student->email, $first);
        $this->actingAs($this->teacher)->get($result['download_url'])->assertForbidden();
    }

    public function test_roster_preview_and_commit_validate_duplicates_and_do_not_grant_roles(): void
    {
        $csv = "name,username,email,role\nMurid Satu,murid.satu,murid.satu@svil.test,admin\nMurid Dua,murid.dua,murid.dua@svil.test,teacher\n";
        $file = fn () => UploadedFile::fake()->createWithContent('roster.csv', $csv);
        $this->actingAs($this->teacher)->post('/api/v1/roster/import', ['classroom_id' => $this->classroom->id, 'file' => $file()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->admin)->post('/api/v1/roster/import', ['classroom_id' => $this->classroom->id, 'file' => $file()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.valid', 2)->assertJsonPath('data.invalid', 0);
        $this->assertDatabaseMissing('users', ['username' => 'murid.satu']);
        $this->post('/api/v1/roster/import', ['classroom_id' => $this->classroom->id, 'file' => $file(), 'commit' => 1], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.count', 2);
        $this->assertDatabaseHas('users', ['username' => 'murid.satu', 'role' => 'student', 'school_id' => $this->classroom->school_id]);
        $this->post('/api/v1/roster/import', ['classroom_id' => $this->classroom->id, 'file' => $file(), 'commit' => 1], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_reflections_are_individual_versioned_and_get_feedback_without_scores(): void
    {
        $fields = ReflectionController::fields(3);
        $payload = ['classroom_id' => $this->classroom->id, 'topic' => 3, 'version' => 0, 'answers' => array_fill_keys(array_keys($fields), 'Aku belajar saling membantu.')];
        $this->actingAs($this->student)->putJson('/api/v1/reflections', $payload)->assertOk()->assertJsonPath('data.answers.gratitude', $payload['answers']['gratitude'])->assertJsonPath('data.schema_snapshot.gratitude', $fields['gratitude'])->assertJsonCount(count($fields), 'data.answers');
        $id = DB::table('individual_reflections')->value('id');
        $this->putJson('/api/v1/reflections', $payload)->assertConflict();
        $this->actingAs($this->teacher)->putJson('/api/v1/reflections/'.$id.'/review', ['version' => 1, 'feedback' => 'Terima kasih sudah jujur.'])->assertOk()->assertJsonMissingPath('data.score')->assertJsonPath('data.answers.gratitude', $payload['answers']['gratitude']);
        $this->actingAs($this->student)->getJson('/api/v1/reflections')->assertOk()->assertJsonPath('data.0.feedback', 'Terima kasih sudah jujur.');
        $this->putJson('/api/v1/reflections', [...$payload, 'version' => 2])->assertOk()->assertJsonPath('data.feedback', 'Terima kasih sudah jujur.')->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('reflection_revisions', 2);
        $changed = $payload['answers'];
        $changed[array_key_first($changed)] = 'Sekarang aku ingin membantu temanku.';
        $this->putJson('/api/v1/reflections', [...$payload, 'version' => 2, 'answers' => $changed])->assertOk()->assertJsonPath('data.feedback', null)->assertJsonPath('data.version', 3);
        $this->assertDatabaseCount('reflection_revisions', 3);
        $other = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($other);
        $this->actingAs($other)->getJson('/api/v1/reflections')->assertOk()->assertJsonCount(0, 'data');
    }
}
