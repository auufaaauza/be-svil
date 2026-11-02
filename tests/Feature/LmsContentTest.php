<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Discussion;
use App\Models\Material;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LmsContentTest extends TestCase
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
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
    }

    public function test_pdf_upload_is_private_filtered_and_attributed_to_the_actual_uploader(): void
    {
        Storage::fake('local');
        $payload = ['classroom_id' => $this->classroom->id, 'title' => 'Bacaan warga', 'content' => 'Baca bersama guru.', 'topic' => 0, 'status' => 'published', 'kind' => 'material', 'subject' => 'IPAS', 'teacher_id' => $this->student->id, 'attachment' => UploadedFile::fake()->createWithContent('bahan.pdf', "%PDF-1.4\n%%EOF")];
        $response = $this->actingAs($this->teacher)->post('/api/v1/materials', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.uploadedBy.id', $this->teacher->id);
        $id = $response->json('data.id');
        $material = Material::findOrFail($id);
        Storage::disk('local')->assertExists($material->attachment_path);
        $this->actingAs($this->student)->get('/api/v1/materials/'.$id.'/attachment')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/materials?kind=quiz')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/materials?kind=material&classroom_id='.$this->classroom->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create())->get('/api/v1/materials/'.$id.'/attachment')->assertForbidden();
        $this->actingAs($this->teacher)->postJson('/api/v1/materials', [...collect($payload)->except('attachment')->all(), 'kind' => 'quiz', 'question' => null])->assertUnprocessable();
        $this->post('/api/v1/materials', [...$payload, 'attachment' => UploadedFile::fake()->create('bad.html', 10, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_discussions_are_class_scoped_idempotent_and_moderated(): void
    {
        $payload = ['classroom_id' => $this->classroom->id, 'title' => 'Rawat lingkungan', 'content' => 'Bagaimana kita menghemat air?', 'status' => 'open'];
        $id = $this->actingAs($this->teacher)->postJson('/api/v1/discussions', $payload)->assertCreated()->assertJsonPath('data.author.id', $this->teacher->id)->json('data.id');
        $thread = Discussion::findOrFail($id);
        $this->actingAs($this->student)->postJson('/api/v1/discussions', $payload)->assertForbidden();
        $reply = ['request_id' => (string) Str::uuid(), 'content' => 'Menutup keran setelah digunakan.'];
        $replyId = $this->postJson('/api/v1/discussions/'.$id.'/replies', $reply)->assertOk()->json('data.id');
        $this->postJson('/api/v1/discussions/'.$id.'/replies', $reply)->assertOk();
        $this->assertDatabaseCount('discussion_replies', 1);
        $this->patchJson('/api/v1/discussion-replies/'.$replyId.'/moderate', ['is_hidden' => true])->assertForbidden();
        $this->getJson('/api/v1/discussions/'.$id.'/replies')->assertOk()->assertJsonPath('data.0.author.name', 'Teman P-'.$this->student->id);
        $this->actingAs($this->teacher)->patchJson('/api/v1/discussion-replies/'.$replyId.'/moderate', ['is_hidden' => true])->assertOk();
        $this->actingAs($this->student)->getJson('/api/v1/discussions/'.$id.'/replies')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->teacher)->putJson('/api/v1/discussions/'.$id, [...$payload, 'version' => 1, 'status' => 'closed'])->assertOk();
        $csv = $this->get('/api/v1/reports/learning.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('TANGGAPAN_DISKUSI', $csv);
        $this->assertStringContainsString('Menutup keran setelah digunakan.', $csv);
        $this->actingAs($this->student)->postJson('/api/v1/discussions/'.$id.'/replies', [...$reply, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/discussions/'.$id)->assertForbidden();
        $this->getJson('/api/v1/discussions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_drafts_and_admin_metrics_are_not_leaked_to_students_or_unassigned_teachers(): void
    {
        Discussion::factory()->create(['classroom_id' => $this->classroom->id, 'author_id' => $this->teacher->id, 'status' => 'draft']);
        $this->actingAs($this->student)->getJson('/api/v1/discussions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->teacher)->getJson('/api/v1/reports/summary')->assertOk()->assertJsonPath('data.administration', null);
        $foreign = Classroom::factory()->create();
        $this->getJson('/api/v1/reports/summary?classroom_id='.$foreign->id)->assertUnprocessable();
    }
}
