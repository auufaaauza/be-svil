<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LkpdAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $class = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach([$teacher->id, $student->id]);
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id]);
        $group->users()->attach($student);

        return [$class, $teacher, $student, $group];
    }

    private function payload(?int $class): array
    {
        return ['request_id' => (string) Str::uuid(), 'classroom_id' => $class, 'title' => 'LKPD tambahan', 'description' => 'Kegiatan kelas.', 'instructions' => 'Amati lalu jawab.', 'fields' => [['key' => 'q_first', 'label' => 'Apa yang kamu temukan?', 'hint' => '', 'phase' => 1, 'video_url' => null], ['key' => 'q_second', 'label' => 'Apa alasanmu?', 'hint' => '', 'phase' => 2, 'video_url' => 'https://youtu.be/abcdefghijk']]];
    }

    public function test_teacher_creates_a_scoped_lkpd_beyond_four_and_retry_does_not_duplicate(): void
    {
        [$class, $teacher, $student, $group] = $this->context();
        $payload = $this->payload($class->id);
        $created = $this->actingAs($teacher)->postJson('/api/v1/learning-modules', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.pageSize', 1)->json('data');
        $this->assertGreaterThanOrEqual(4, $created['topic']);
        $this->postJson('/api/v1/learning-modules', $payload)->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->assertDatabaseCount('learning_modules', 1);
        $other = Classroom::factory()->create(['school_id' => $class->school_id]);
        $this->postJson('/api/v1/learning-modules', $this->payload($other->id))->assertForbidden();
        $this->postJson('/api/v1/learning-modules', $this->payload(null))->assertUnprocessable();
        $this->actingAs($student)->postJson('/api/v1/learning-modules', $payload)->assertForbidden();
        $this->getJson('/api/v1/learning-modules/'.$created['id'])->assertForbidden();
        $this->getJson('/api/v1/learning-modules?classroom_id='.$class->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_class_editor_publishes_and_activates_without_exposing_another_class(): void
    {
        [$class, $teacher, $student, $group] = $this->context();
        $payload = $this->payload($class->id);
        $module = $this->actingAs($teacher)->postJson('/api/v1/learning-modules', $payload)->json('data');
        $content = [...$payload, 'version' => 0, 'status' => 'published'];
        $this->putJson('/api/v1/learning-modules/'.$module['id'].'/content', $content)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.fields.1.video.provider', 'youtube');
        $this->putJson('/api/v1/learning-modules/'.$module['id'].'/activate', ['classroom_id' => $class->id])->assertOk();
        $this->assertDatabaseHas('classrooms', ['id' => $class->id, 'active_lkpd_id' => $module['id']]);
        $work = '/api/v1/groups/'.$group->id.'/work/'.$module['topic'];
        $this->actingAs($student)->getJson($work)->assertOk()->assertJsonPath('module.pageSize', 1);
        $other = Classroom::factory()->create(['school_id' => $class->school_id]);
        $outsider = User::factory()->create(['school_id' => $class->school_id]);
        $other->users()->attach($outsider);
        $otherGroup = LearningGroup::factory()->create(['classroom_id' => $other->id]);
        $otherGroup->users()->attach($outsider);
        $this->actingAs($outsider)->getJson('/api/v1/learning-modules?classroom_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/learning-modules/'.$module['id'])->assertForbidden();
        $this->getJson('/api/v1/groups/'.$otherGroup->id.'/work/'.$module['topic'])->assertForbidden();
        $this->putJson('/api/v1/groups/'.$otherGroup->id.'/work/'.$module['topic'], ['version' => 0, 'page' => 0, 'status' => 'draft', 'answers' => ['q_first' => 'Isian']])->assertForbidden();
    }

    public function test_template_section_supports_both_media_and_keeps_old_snapshot_after_reorder_and_delete(): void
    {
        [$class, $teacher, $student, $group] = $this->context();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $payload = $this->payload(null);
        $module = $this->actingAs($admin)->postJson('/api/v1/learning-modules', $payload)->json('data');
        $payload['fields'][0]['video_url'] = 'https://youtu.be/abcdefghijk';
        $saved = $this->post('/api/v1/learning-modules/'.$module['id'].'/content', ['_method' => 'PUT', 'version' => 1, 'status' => 'published', 'title' => $payload['title'], 'description' => $payload['description'], 'instructions' => $payload['instructions'], 'fields' => json_encode($payload['fields']), 'photos' => ['q_first' => UploadedFile::fake()->image('gambar.png', 150, 100)]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.fields.0.video.provider', 'youtube')->json('data');
        $photo = $saved['fields'][0]['image_url'];
        $this->assertNotNull($photo);
        $work = '/api/v1/groups/'.$group->id.'/work/'.$module['topic'];
        $this->actingAs($student)->putJson($work, ['version' => 0, 'page' => 0, 'status' => 'submitted', 'answers' => ['q_first' => 'Jawaban lama']])->assertOk()->assertJsonPath('data.pages_total', 2);
        $this->get($photo)->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/learning-modules/'.$module['id'].'/content', ['version' => 2, 'status' => 'draft', 'title' => $payload['title'], 'description' => $payload['description'], 'instructions' => $payload['instructions'], 'fields' => [$payload['fields'][1]]])->assertOk();
        $this->actingAs($student)->getJson($work)->assertOk()->assertJsonPath('fields.0.key', 'q_first')->assertJsonPath('fields.0.image_url', $photo)->assertJsonPath('data.answers.q_first', 'Jawaban lama');
        $this->get($photo)->assertOk();
        $this->assertDatabaseCount('learning_module_revisions', 3);
    }

    public function test_new_sections_are_locked_until_revision_and_zero_is_a_real_published_score(): void
    {
        [$class, $teacher, $student, $group] = $this->context();
        $module = LearningModule::factory()->create(['topic' => 7, 'classroom_id' => $class->id, 'page_size' => 1, 'fields' => [$this->payload($class->id)['fields'][0]]]);
        $work = '/api/v1/groups/'.$group->id.'/work/7';
        $row = $this->actingAs($student)->putJson($work, ['version' => 0, 'page' => 0, 'status' => 'submitted', 'answers' => ['q_first' => 'Jawaban awal']])->assertOk()->json('data');
        $this->putJson($work, ['version' => 1, 'page' => 0, 'status' => 'draft', 'answers' => ['q_first' => 'Jawaban diganti']])->assertUnprocessable();
        $review = '/api/v1/group-work/'.$row['id'].'/review';
        $this->actingAs($teacher)->putJson($review, ['version' => 1, 'page' => 0, 'status' => 'needs_revision', 'feedback' => 'Tambahkan alasan.'])->assertOk();
        $this->actingAs($student)->putJson($work, ['version' => 2, 'page' => 0, 'status' => 'submitted', 'answers' => ['q_first' => 'Jawaban diperbaiki']])->assertOk();
        $this->actingAs($teacher)->putJson($review, ['version' => 3, 'status' => 'reviewed', 'feedback' => 'Sudah ditinjau.', 'score' => 0])->assertOk()->assertJsonPath('data.score', 0);
        $this->actingAs($student)->getJson('/api/v1/gradebook?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.students.0.group_work.0.score', 0);
    }

    public function test_updated_import_is_idempotent_and_preserves_started_legacy_work(): void
    {
        [$class, $teacher, $student, $group] = $this->context();
        $module = LearningModule::factory()->create(['topic' => 0]);
        $this->actingAs($student)->putJson('/api/v1/groups/'.$group->id.'/work/0', ['version' => 0, 'status' => 'draft', 'answers' => ['facts' => 'Catatan lama']])->assertOk();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->artisan('svil:import-lkpd', ['--by' => $admin->email, '--updated' => true])->assertExitCode(0);
        $count = DB::table('learning_module_revisions')->count();
        $this->artisan('svil:import-lkpd', ['--by' => $admin->email, '--updated' => true])->assertExitCode(0);
        $this->assertDatabaseCount('learning_module_revisions', $count);
        $this->assertDatabaseHas('learning_modules', ['topic' => 2, 'status' => 'draft', 'page_size' => 1]);
        $this->getJson('/api/v1/groups/'.$group->id.'/work/0')->assertOk()->assertJsonPath('fields.0.key', 'facts')->assertJsonPath('data.page_size', 4)->assertJsonPath('data.answers.facts', 'Catatan lama');
    }
}
