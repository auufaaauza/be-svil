<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ReflectionController;
use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function classroomMembers(): array
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id, 'name' => 'Murid Kelas V']);
        $teacher = User::factory()->create(['school_id' => $class->school_id, 'role' => Role::Teacher]);
        $other = User::factory()->create(['school_id' => $class->school_id, 'role' => Role::Teacher]);
        $class->users()->attach([$student->id, $teacher->id]);

        return [$class, $student, $teacher, $other];
    }

    public function test_lkpd_submissions_notify_only_the_assigned_teacher_and_link_to_the_student(): void
    {
        [$class, $student, $teacher, $other] = $this->classroomMembers();
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id, 'name' => 'Kelompok Peduli']);
        $group->users()->attach($student);
        $this->actingAs($student);
        $module = LearningModule::factory()->create(['topic' => 0]);
        $answers = array_fill_keys(array_column($module->fields, 'key'), 'Hasil pengamatan kelompok.');
        $url = '/api/v1/groups/'.$group->id.'/work/0';
        $this->putJson($url, ['version' => 0, 'status' => 'draft', 'answers' => $answers])->assertOk();
        $this->assertDatabaseCount('learning_notifications', 0);
        $this->putJson($url, ['version' => 1, 'status' => 'submitted', 'answers' => $answers])->assertOk();
        $notice = $this->actingAs($teacher)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->json('data.0');
        $this->assertSame('lkpd_submission', $notice['kind']);
        $this->assertStringContainsString($student->name, $notice['body']);
        $this->assertStringContainsString('/guru/tinjauan/'.$student->id.'?pertemuan=1', $notice['target_path']);
        $this->assertStringContainsString('kelas='.$class->id, $notice['target_path']);
        $this->actingAs($other)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reflection_notifications_are_class_scoped_and_an_unchanged_save_preserves_the_message(): void
    {
        [$class, $student, $teacher, $other] = $this->classroomMembers();
        $payload = ['classroom_id' => $class->id, 'topic' => 0, 'version' => 0, 'answers' => array_fill_keys(array_keys(ReflectionController::fields(0)), 'Aku ingin membantu.')];
        $id = $this->actingAs($student)->putJson('/api/v1/reflections', $payload)->assertOk()->json('data.id');
        $this->actingAs($teacher)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.kind', 'reflection_submission')->assertJsonPath('unread_count', 1);
        $this->putJson('/api/v1/reflections/'.$id.'/review', ['version' => 1, 'feedback' => 'Terima kasih sudah berbagi.'])->assertOk();
        $this->actingAs($student)->putJson('/api/v1/reflections', [...$payload, 'version' => 2])->assertOk()->assertJsonPath('data.feedback', 'Terima kasih sudah berbagi.');
        $this->assertSame(1, DB::table('learning_notifications')->where('recipient_id', $teacher->id)->where('kind', 'reflection_submission')->count());
        $this->actingAs($other)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    }
}
