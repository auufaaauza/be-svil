<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_documents_import_once_with_uploader_and_source(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->artisan('svil:import-lkpd', ['--by' => $admin->email])->assertExitCode(0);
        $this->artisan('svil:import-lkpd', ['--by' => $admin->email])->assertExitCode(0);
        $this->assertDatabaseCount('learning_modules', 4);
        $this->assertDatabaseCount('learning_module_revisions', 4);
        $module = LearningModule::where('topic', 0)->firstOrFail();
        $this->assertStringContainsString('PETA DAN LAUT SEBAGAI PENGHUBUNG', $module->source_text);
        $this->assertSame(hash('sha256', $module->source_text), $module->source_hash);
        $this->actingAs($admin)->getJson('/api/v1/learning-modules')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.uploadedBy.name', $admin->name)->assertJsonMissingPath('data.0.sourceText');
        $this->getJson('/api/v1/learning-modules/'.$module->id)->assertOk()->assertJsonPath('data.sourceText', $module->source_text);
    }

    public function test_lkpd_edits_are_admin_only_and_existing_group_answers_keep_their_schema(): void
    {
        $classroom = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $classroom->school_id]);
        $classroom->users()->attach($student);
        $group = LearningGroup::factory()->create(['classroom_id' => $classroom->id]);
        $group->users()->attach($student);
        $module = LearningModule::factory()->create();
        $this->actingAs($student)->putJson('/api/v1/groups/'.$group->id.'/work/0', ['version' => 0, 'status' => 'draft', 'answers' => ['facts' => 'Fakta peta']])->assertOk()->assertJsonPath('data.module_version', 1);
        $payload = ['version' => 1, 'title' => $module->title, 'description' => $module->description, 'instructions' => $module->instructions, 'status' => 'published', 'fields' => [['key' => 'new_field', 'label' => 'Pertanyaan baru', 'hint' => 'Cari sumber', 'phase' => 1]]];
        $this->putJson('/api/v1/learning-modules/'.$module->id, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->putJson('/api/v1/learning-modules/'.$module->id, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->putJson('/api/v1/learning-modules/'.$module->id, $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/learning-modules/'.$module->id, $payload)->assertConflict();
        $this->actingAs($student)->getJson('/api/v1/groups/'.$group->id.'/work/0')->assertOk()->assertJsonPath('fields.0.key', 'facts')->assertJsonPath('module.version', 1);
        $this->putJson('/api/v1/groups/'.$group->id.'/work/0', ['version' => 1, 'status' => 'draft', 'answers' => ['facts' => 'Fakta diperbaiki']])->assertOk();
    }
}
