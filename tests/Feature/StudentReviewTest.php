<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningNote;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_names_distinguish_individual_notes_and_shared_lkpd(): void
    {
        $class = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $student = User::factory()->create(['name' => 'Murid Kelas', 'school_id' => $class->school_id]);
        $peer = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach([$teacher->id, $student->id, $peer->id]);
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id]);
        $group->users()->attach([$student->id, $peer->id]);
        GroupWork::factory()->create(['learning_group_id' => $group->id, 'topic' => 1, 'status' => 'submitted']);
        $note = LearningNote::factory()->create(['classroom_id' => $class->id, 'student_id' => $student->id, 'topic' => 1, 'phase' => 2, 'content' => 'Catatan murid yang dipilih']);
        LearningNote::factory()->create(['classroom_id' => $class->id, 'student_id' => $peer->id, 'content' => 'Catatan pribadi teman']);

        $roster = $this->actingAs($teacher)->getJson('/api/v1/student-reviews?classroom_id='.$class->id)->assertOk();
        $summary = collect($roster->json('data'))->firstWhere('id', $student->id);
        $this->assertSame('Murid Kelas', $summary['name']);
        $this->assertSame(1, $summary['lkpd'][1]['notes_filled']);
        $this->assertSame('submitted', $summary['lkpd'][1]['group_status']);
        $this->getJson('/api/v1/student-reviews/'.$student->id.'?classroom_id='.$class->id)->assertOk()
            ->assertJsonCount(1, 'data.notes')->assertJsonCount(1, 'data.works')
            ->assertJsonPath('data.notes.0.content_hash', hash('sha256', $note->content))
            ->assertJsonMissing(['content' => 'Catatan pribadi teman']);
        $this->actingAs($student)->getJson('/api/v1/student-reviews?classroom_id='.$class->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->getJson('/api/v1/student-reviews?classroom_id='.$class->id)->assertUnprocessable();
        $this->actingAs($teacher)->getJson('/api/v1/student-reviews/'.User::factory()->create()->id.'?classroom_id='.$class->id)->assertForbidden();
    }
}
