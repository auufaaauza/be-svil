<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Models\WordwallGrade;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WordwallGradeTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $class = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $student = User::factory()->create(['name' => 'Siswa A', 'school_id' => $class->school_id]);
        $other = User::factory()->create(['name' => 'Siswa B', 'school_id' => $class->school_id]);
        $class->users()->attach([$teacher->id, $student->id, $other->id]);

        return [$class, $teacher, $student, $other];
    }

    private function payload(Classroom $class, User $student): array
    {
        return ['classroom_id' => $class->id, 'student_id' => $student->id, 'game_id' => 'wordwall-123874298', 'score' => 0, 'feedback' => 'Hasil sudah diperiksa.', 'version' => 0];
    }

    public function test_teacher_records_zero_and_gradebook_csv_and_student_view_keep_it_as_a_real_score(): void
    {
        [$class, $teacher, $student, $other] = $this->context();
        $this->actingAs($teacher)->putJson('/api/v1/wordwall-grades', $this->payload($class, $student))->assertOk()->assertJsonPath('data.score', 0)->assertJsonPath('data.version', 1)->assertJsonPath('data.teacher.id', $teacher->id);
        $this->assertDatabaseHas('learning_notifications', ['recipient_id' => $student->id, 'title' => 'Nilai kuis dari guru']);
        $this->assertDatabaseHas('learning_audits', ['actor_id' => $teacher->id, 'action' => 'wordwall_grade_saved']);
        $csv = $this->get('/api/v1/gradebook.csv?classroom_id='.$class->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('wordwall_manual', $csv);
        $this->actingAs($student)->getJson('/api/v1/wordwall-grades?classroom_id='.$class->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.score', 0);
        $this->getJson('/api/v1/gradebook?classroom_id='.$class->id)->assertOk()->assertJsonCount(1, 'data.students')->assertJsonPath('data.students.0.wordwall.0.normalized_score', 0)->assertJsonPath('data.students.0.wordwall.1.normalized_score', null)->assertJsonPath('data.students.0.wordwall.0.source', 'wordwall_manual');
        $this->actingAs($other)->getJson('/api/v1/wordwall-grades?classroom_id='.$class->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revision_updates_one_record_and_stale_writes_preserve_the_latest_grade(): void
    {
        [$class, $teacher, $student] = $this->context();
        $payload = $this->payload($class, $student);
        $this->actingAs($teacher)->putJson('/api/v1/wordwall-grades', $payload)->assertOk();
        $this->putJson('/api/v1/wordwall-grades', [...$payload, 'version' => 1, 'score' => 87.5])->assertOk()->assertJsonPath('data.score', 87.5)->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/wordwall-grades', [...$payload, 'version' => 1, 'score' => 95])->assertConflict();
        $this->assertDatabaseCount('wordwall_grades', 1);
        $this->assertDatabaseHas('wordwall_grades', ['student_id' => $student->id, 'score' => 87.5, 'version' => 2]);
        $audit = json_decode(DB::table('learning_audits')->where('action', 'wordwall_grade_saved')->orderByDesc('id')->value('data'), true);
        $this->assertEquals(0, $audit['before']['score']);
        $this->assertEquals(87.5, $audit['after']['score']);
    }

    public function test_students_admins_and_teachers_from_other_classes_cannot_write_a_grade(): void
    {
        [$class, $teacher, $student] = $this->context();
        $payload = $this->payload($class, $student);
        foreach ([$student, User::factory()->create(['role' => Role::Admin]), User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id])] as $user) {
            $this->actingAs($user)->putJson('/api/v1/wordwall-grades', $payload)->assertForbidden();
        }
        $outsider = User::factory()->create(['school_id' => $class->school_id]);
        $this->actingAs($teacher)->putJson('/api/v1/wordwall-grades', [...$payload, 'student_id' => $outsider->id])->assertUnprocessable();
        $this->actingAs($outsider)->getJson('/api/v1/wordwall-grades?classroom_id='.$class->id)->assertForbidden();
        $this->assertDatabaseCount('wordwall_grades', 0);
    }

    public function test_bounds_known_games_and_archived_classes_are_enforced(): void
    {
        [$class, $teacher, $student] = $this->context();
        $payload = $this->payload($class, $student);
        $this->actingAs($teacher);
        foreach ([['score' => -1], ['score' => 101], ['game_id' => 'unknown-game']] as $invalid) {
            $this->putJson('/api/v1/wordwall-grades', [...$payload, ...$invalid])->assertUnprocessable();
        }
        $this->putJson('/api/v1/wordwall-grades', [...$payload, 'score' => 100])->assertOk()->assertJsonPath('data.score', 100);
        $class->update(['archived_at' => now()]);
        $this->putJson('/api/v1/wordwall-grades', [...$payload, 'version' => 1, 'score' => 70])->assertUnprocessable();
        $this->assertDatabaseHas('wordwall_grades', ['student_id' => $student->id, 'score' => 100]);
        $this->getJson('/api/v1/wordwall-grades?classroom_id='.$class->id)->assertOk();
    }

    public function test_admin_can_monitor_factory_grade_but_another_student_cannot_see_it(): void
    {
        [$class, $teacher, $student, $other] = $this->context();
        WordwallGrade::factory()->create(['classroom_id' => $class->id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->getJson('/api/v1/wordwall-grades?classroom_id='.$class->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/gradebook?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.students.0.wordwall.0.normalized_score', null);
    }
}
