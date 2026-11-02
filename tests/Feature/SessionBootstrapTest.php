<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_session_keeps_role_scope_answer_keys_and_pagination(): void
    {
        $this->getJson('/api/v1/session-bootstrap')->assertUnauthorized();
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $other = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach([$student->id, $teacher->id, $other->id]);
        Material::factory()->count(31)->create(['classroom_id' => $class->id, 'teacher_id' => $teacher->id, 'status' => 'published', 'question' => ['type' => 'choice', 'prompt' => 'Pilih jawaban.', 'options' => ['A', 'B'], 'correctIndex' => 0]]);
        Material::factory()->create(['classroom_id' => $class->id, 'teacher_id' => $teacher->id, 'title' => 'Draf privat']);
        Material::factory()->create(['status' => 'published', 'title' => 'Kelas lain']);
        LearningNote::factory()->create(['classroom_id' => $class->id, 'student_id' => $student->id, 'content' => 'Catatan saya.']);
        LearningNote::factory()->create(['classroom_id' => $class->id, 'student_id' => $other->id, 'content' => 'Catatan teman.']);
        $response = $this->actingAs($student)->getJson('/api/v1/session-bootstrap')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.profile.id', $student->id)->assertJsonCount(1, 'data.classrooms.data')->assertJsonCount(30, 'data.materials.data')->assertJsonPath('data.materials.meta.total', 31)->assertJsonCount(1, 'data.notes.data')->assertJsonMissingPath('data.materials.data.0.question.correctIndex');
        $this->assertNotNull($response->json('data.materials.links.next'));
        $this->assertStringNotContainsString('Kelas lain', $response->getContent());
        $this->assertStringNotContainsString('Draf privat', $response->getContent());
        $this->assertStringNotContainsString('Catatan teman.', $response->getContent());
        $this->actingAs($teacher)->getJson('/api/v1/session-bootstrap')->assertOk()->assertJsonCount(2, 'data.notes.data');
        $outsider = Classroom::factory()->create();
        $this->getJson('/api/v1/class-board?classroom_id='.$outsider->id)->assertForbidden();
        $this->getJson('/api/v1/class-board?classroom_id='.$class->id)->assertOk()->assertJsonStructure(['data' => ['announcements' => ['data'], 'assignments' => ['data'], 'assessments' => ['data']]]);
    }

    public function test_reflection_bundle_is_student_only_and_preserves_the_same_private_queries(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach($student);
        $response = $this->actingAs($student)->getJson('/api/v1/reflection-workspace')->assertOk()->assertJsonCount(4, 'data.schemas.data');
        foreach (['reflections', 'attempts', 'observations'] as $section) {
            $expected = $this->getJson('/api/v1/'.$section)->assertOk()->json('data');
            $this->assertSame($expected, $response->json('data.'.$section.'.data'));
        }
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $this->actingAs($teacher)->getJson('/api/v1/reflection-workspace')->assertForbidden();
    }
}
