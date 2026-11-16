<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\School;
use App\Models\User;
use App\Role;
use Database\Seeders\StudentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class StudentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_seeding_creates_five_students_with_working_logins_and_groups(): void
    {
        $this->artisan('db:seed', ['--class' => StudentSeeder::class, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Kode kelas: SVILTEST2026')->assertExitCode(0);
        $classroom = Classroom::where('join_code', 'SVILTEST2026')->firstOrFail();
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertDatabaseCount('learning_groups', 2);
        $this->assertDatabaseCount('learning_modules', 0);
        $this->assertDatabaseCount('group_works', 0);
        foreach (range(1, 5) as $number) {
            $student = User::where('username', 'siswa'.$number)->firstOrFail();
            $this->assertSame('siswa'.$number.'@gmail.com', $student->email);
            $this->assertSame(Role::Student, $student->role);
            $this->assertTrue($student->is_active);
            $this->assertFalse($student->must_change_password);
            $this->assertTrue(Hash::check('siswa123', $student->password));
            $this->assertNotSame('siswa123', $student->getRawOriginal('password'));
            $this->assertTrue($student->canAccessClassroom($classroom->id));
            $group = LearningGroup::whereHas('users', fn ($query) => $query->whereKey($student->id))->firstOrFail();
            $this->assertSame('Kelompok Demo '.($number <= 3 ? 1 : 2), $group->name);
            foreach ([$student->username, $student->email] as $login) {
                $this->app['auth']->forgetGuards();
                $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])
                    ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'siswa123'])
                    ->assertOk()->assertJsonPath('data.role', 'student')->assertJsonPath('data.id', $student->id);
                $this->postJson('/api/v1/auth/logout')->assertOk();
            }
        }
    }

    public function test_existing_teacher_gets_the_demo_class_without_changing_admin_or_teacher_accounts(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->create(['username' => 'admin', 'email' => 'admin@school.test', 'password' => 'AdminChanged123', 'role' => Role::Admin]);
        $teacher = User::factory()->create(['username' => 'guru1', 'email' => 'teacher@school.test', 'password' => 'GuruChanged123', 'role' => Role::Teacher, 'school_id' => $school->id]);
        $existingClassroom = Classroom::factory()->create(['school_id' => $school->id]);
        $existingClassroom->users()->attach($teacher->id);
        $module = LearningModule::factory()->create(['uploaded_by' => $admin->id, 'updated_by' => $admin->id]);
        $beforeAdmin = $admin->fresh()->getAttributes();
        $beforeTeacher = $teacher->fresh()->getAttributes();
        $beforeClassroom = $existingClassroom->fresh()->getAttributes();

        $this->seed(StudentSeeder::class);

        $classroom = Classroom::where('join_code', 'SVILTEST2026')->firstOrFail();
        $student = User::where('username', 'siswa1')->firstOrFail();
        $this->assertSame($school->id, $classroom->school_id);
        $this->assertSame($school->id, $student->school_id);
        $this->assertSame($module->id, $classroom->active_lkpd_id);
        $this->assertTrue($teacher->canAccessClassroom($classroom->id));
        $this->assertTrue($teacher->canAccessClassroom($existingClassroom->id));
        $this->assertSame($beforeAdmin, $admin->fresh()->getAttributes());
        $this->assertSame($beforeTeacher, $teacher->fresh()->getAttributes());
        $this->assertSame($beforeClassroom, $existingClassroom->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('schools', 1);
        $this->actingAs($student)->getJson('/api/v1/learning-modules/'.$module->id)->assertOk();
        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rerunning_preserves_changed_credentials_memberships_answers_and_grades(): void
    {
        $this->seed(StudentSeeder::class);
        $classroom = Classroom::firstOrFail();
        $student = User::where('username', 'siswa1')->firstOrFail();
        $student->forceFill(['name' => 'Nama Sudah Diubah', 'email' => 'edited@example.test', 'password' => 'Changed123', 'is_active' => false, 'must_change_password' => true])->save();
        $groups = LearningGroup::orderBy('id')->get();
        $groups[0]->users()->detach($student->id);
        $groups[1]->users()->attach($student->id);
        $otherStudent = User::where('username', 'siswa2')->firstOrFail();
        $classroom->users()->updateExistingPivot($otherStudent->id, ['is_active' => false]);
        $groups[0]->users()->updateExistingPivot($otherStudent->id, ['is_active' => false]);
        $work = GroupWork::factory()->create(['learning_group_id' => $groups[1]->id, 'answers' => ['answer' => 'Jawaban yang dipertahankan'], 'status' => 'reviewed', 'score' => 92]);
        $beforeStudent = $student->fresh()->getAttributes();
        $beforeWork = $work->fresh()->getAttributes();
        $beforeClassroom = $classroom->fresh()->getAttributes();

        $this->seed(StudentSeeder::class);
        $this->seed(StudentSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertDatabaseCount('learning_groups', 2);
        $this->assertDatabaseCount('classroom_user', 5);
        $this->assertDatabaseCount('learning_group_user', 5);
        $this->assertDatabaseCount('group_works', 1);
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertSame($beforeWork, $work->fresh()->getAttributes());
        $this->assertSame($beforeClassroom, $classroom->fresh()->getAttributes());
        $this->assertTrue(Hash::check('Changed123', $student->fresh()->password));
        $this->assertTrue($groups[1]->users()->whereKey($student->id)->exists());
        $this->assertFalse($groups[0]->users()->whereKey($student->id)->exists());
        $this->assertDatabaseHas('classroom_user', ['classroom_id' => $classroom->id, 'user_id' => $otherStudent->id, 'is_active' => false]);
        $this->assertDatabaseHas('learning_group_user', ['learning_group_id' => $groups[0]->id, 'user_id' => $otherStudent->id, 'is_active' => false]);
    }

    public function test_a_reserved_identity_owned_by_another_role_rolls_back_all_changes(): void
    {
        $existing = User::factory()->create(['username' => 'siswa5', 'role' => Role::Admin]);
        $before = $existing->fresh()->getAttributes();
        try {
            $this->seed(StudentSeeder::class);
            $this->fail('Conflicting identities must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bertabrakan', $exception->getMessage());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('classrooms', 0);
        $this->assertDatabaseCount('learning_groups', 0);
    }

    public function test_student_accounts_from_other_schools_are_not_moved_to_the_demo_class(): void
    {
        $existing = User::factory()->create(['username' => 'siswa5', 'role' => Role::Student, 'school_id' => School::factory()]);
        $before = $existing->fresh()->getAttributes();
        try {
            $this->seed(StudentSeeder::class);
            $this->fail('Existing students must not be moved to another school.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sekolah berbeda', $exception->getMessage());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('classrooms', 0);
    }

    public function test_a_class_code_owned_by_another_class_is_not_reused(): void
    {
        $existing = Classroom::factory()->create(['join_code' => 'SVILTEST2026']);
        $before = $existing->fresh()->getAttributes();
        try {
            $this->seed(StudentSeeder::class);
            $this->fail('Existing class codes must not be taken over.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dipakai kelas lain', $exception->getMessage());
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertDatabaseCount('learning_groups', 0);
    }
}
