<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LkpdContentTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $teacher = User::factory()->create(['school_id' => $class->school_id, 'role' => Role::Teacher]);
        $class->users()->attach([$student->id, $teacher->id]);
        $module = LearningModule::factory()->create(['topic' => 0, 'youtube_video_id' => 'abcdefghijk']);
        $payload = ['classroom_id' => $class->id, 'version' => 0, 'title' => 'LKPD Kelasku', 'description' => 'Belajar bersama.', 'instructions' => 'Amati media dan tuliskan pendapatmu.', 'fields' => [['key' => 'observasi', 'label' => 'Apa yang kamu lihat?', 'hint' => 'Amati dengan teliti.', 'phase' => 0, 'video_url' => 'https://www.tiktok.com/@guru/video/6718335390845095173', 'image_alt' => 'Ilustrasi pembelajaran.']]];

        return [$class, $student, $teacher, $module, $payload];
    }

    public function test_teacher_can_edit_only_their_class_and_the_content_keeps_the_default_video(): void
    {
        [$class,$student,$teacher,$module,$payload] = $this->context();
        $url = '/api/v1/learning-modules/'.$module->id.'/content';
        $this->actingAs($teacher)->putJson($url, $payload)->assertOk()->assertJsonPath('data.title', 'LKPD Kelasku')->assertJsonPath('data.contentVersion', 1)->assertJsonPath('data.fields.0.video.provider', 'tiktok')->assertJsonPath('data.videoId', 'abcdefghijk');
        $this->putJson($url, $payload)->assertConflict();
        $this->assertNotSame('LKPD Kelasku', $module->fresh()->title);
        $other = Classroom::factory()->create(['school_id' => $class->school_id]);
        $this->putJson($url, [...$payload, 'classroom_id' => $other->id])->assertForbidden();
        $this->actingAs($student)->putJson($url, [...$payload, 'version' => 1])->assertForbidden();
    }

    public function test_question_photos_are_private_and_a_started_submission_keeps_its_question_snapshot(): void
    {
        [$class,$student,$teacher,$module,$payload] = $this->context();
        $payload['fields'][0]['video_url'] = null;
        $uploaded = [...$payload, '_method' => 'PUT', 'fields' => json_encode($payload['fields']), 'photos' => ['observasi' => UploadedFile::fake()->image('gambar.png', 100, 100)]];
        $saved = $this->actingAs($teacher)->post('/api/v1/learning-modules/'.$module->id.'/content', $uploaded, ['Accept' => 'application/json'])->assertOk()->json('data');
        $photo = $saved['fields'][0]['image_url'];
        $this->actingAs($student)->get($photo)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $outsider = User::factory()->create(['school_id' => $class->school_id]);
        $this->actingAs($outsider)->getJson($photo)->assertForbidden();
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id]);
        $group->users()->attach($student);
        $workUrl = '/api/v1/groups/'.$group->id.'/work/0';
        $this->actingAs($student)->putJson($workUrl, ['version' => 0, 'status' => 'submitted', 'page' => 0, 'answers' => ['observasi' => 'Aku melihat contoh pembelajaran.']])->assertOk();
        $payload['version'] = 1;
        $payload['fields'][0]['label'] = 'Pertanyaan versi baru.';
        $this->actingAs($teacher)->putJson('/api/v1/learning-modules/'.$module->id.'/content', $payload)->assertOk();
        $this->actingAs($student)->getJson($workUrl)->assertOk()->assertJsonPath('fields.0.label', 'Apa yang kamu lihat?')->assertJsonPath('fields.0.image_url', $photo);
        $this->get($photo)->assertOk();
        $payload['version'] = 2;
        $payload['fields'][0]['video_url'] = 'javascript:alert(1)';
        $this->actingAs($teacher)->putJson('/api/v1/learning-modules/'.$module->id.'/content', $payload)->assertUnprocessable();
    }

    public function test_admin_can_explicitly_update_unsubmitted_drafts_without_losing_answers_or_changing_submitted_work(): void
    {
        [$class, $student, $teacher, $module, $payload] = $this->context();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contentUrl = '/api/v1/learning-modules/'.$module->id.'/content';
        $this->actingAs($admin)->putJson($contentUrl, $payload)->assertOk();
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id]);
        $group->users()->attach($student);
        $workUrl = '/api/v1/groups/'.$group->id.'/work/0';
        $work = $this->actingAs($student)->putJson($workUrl, ['version' => 0, 'status' => 'draft', 'page' => 0, 'answers' => ['observasi' => 'Jawabanku tetap tersimpan.']])->assertOk()->json('data');
        $payload['version'] = 1;
        $payload['apply_to_drafts'] = true;
        $payload['fields'][0]['label'] = 'Pertanyaan dengan media baru.';
        $this->actingAs($admin)->putJson($contentUrl, $payload)->assertOk();
        $this->actingAs($student)->getJson($workUrl)->assertOk()->assertJsonPath('fields.0.label', 'Pertanyaan dengan media baru.')->assertJsonPath('data.answers.observasi', 'Jawabanku tetap tersimpan.')->assertJsonPath('data.version', 2);
        $this->putJson($workUrl, ['version' => $work['version'], 'status' => 'draft', 'page' => 0, 'answers' => ['observasi' => 'Versi lama.']])->assertConflict();
        $this->putJson($workUrl, ['version' => 2, 'status' => 'submitted', 'page' => 0, 'answers' => ['observasi' => 'Jawabanku tetap tersimpan.']])->assertOk();
        $payload['version'] = 2;
        $payload['fields'][0]['label'] = 'Pertanyaan selanjutnya.';
        $this->actingAs($admin)->putJson($contentUrl, $payload)->assertOk();
        $this->actingAs($student)->getJson($workUrl)->assertOk()->assertJsonPath('fields.0.label', 'Pertanyaan dengan media baru.')->assertJsonPath('data.version', 3);
        $this->assertDatabaseCount('module_class_content_revisions', 3);
    }
}
