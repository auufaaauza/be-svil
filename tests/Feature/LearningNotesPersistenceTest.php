<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningNote;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningNotesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_meeting_and_every_phase_saves_and_reloads_without_overwriting_other_steps(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach($student);
        $this->actingAs($student);
        foreach (range(0, 3) as $topic) {
            foreach (range(0, 4) as $phase) {
                $text = 'Catatan pertemuan '.($topic + 1).' langkah '.($phase + 1);
                $this->putJson('/api/v1/notes', ['classroom_id' => $class->id, 'topic' => $topic, 'phase' => $phase, 'content' => $text])->assertOk()->assertJsonPath('data.content', $text);
            }
        }
        $this->assertSame(20, LearningNote::where('student_id', $student->id)->count());
        $rows = $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(20, 'data')->json('data');
        foreach ($rows as $row) {
            $this->assertSame('Catatan pertemuan '.($row['topic'] + 1).' langkah '.($row['phase'] + 1), $row['content']);
        }
        $this->putJson('/api/v1/notes', ['classroom_id' => $class->id, 'topic' => 0, 'phase' => 0, 'content' => 'Perbaikan langkah pertama'])->assertOk();
        $this->assertDatabaseHas('learning_notes', ['student_id' => $student->id, 'topic' => 0, 'phase' => 1, 'content' => 'Catatan pertemuan 1 langkah 2']);
        $this->assertDatabaseCount('learning_notes', 20);
    }

    public function test_assignment_by_admin_makes_previously_unassigned_student_able_to_save_all_five_steps(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $payload = ['classroom_id' => $class->id, 'topic' => 0, 'phase' => 0, 'content' => 'Aku menemukan pulau.'];
        $this->actingAs($student)->putJson('/api/v1/notes', $payload)->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/v1/classrooms/'.$class->id.'/members', ['user_ids' => [$student->id]])->assertOk();
        $this->actingAs($student)->getJson('/api/v1/classrooms')->assertOk()->assertJsonCount(1, 'data');
        foreach (range(0, 4) as $phase) {
            $this->putJson('/api/v1/notes', [...$payload, 'phase' => $phase])->assertOk();
        }
    }

    public function test_admin_must_assign_a_class_when_creating_a_student(): void
    {
        $class = Classroom::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $payload = ['name' => 'Murid', 'username' => 'murid-baru', 'email' => 'murid-baru@svil.test', 'password' => 'AmanUntukBelajar123', 'role' => 'student', 'school_id' => $class->school_id];
        $this->actingAs($admin)->postJson('/api/v1/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('classroom_ids');
        $this->postJson('/api/v1/users', [...$payload, 'classroom_ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/users', [...$payload, 'classroom_ids' => [$class->id]])->assertCreated();
    }
}
