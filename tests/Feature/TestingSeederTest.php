<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\CourseLesson;
use App\Models\GroupWork;
use App\Models\User;
use App\Role;
use Database\Seeders\TestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class TestingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_demo_is_repeatable_and_all_requested_accounts_can_use_their_class(): void
    {
        $this->seed(TestingSeeder::class);
        $class = Classroom::where('join_code', 'SVILTEST2026')->firstOrFail();
        $this->assertSame(12, strlen($class->join_code));
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('learning_groups', 2);
        $this->assertDatabaseCount('learning_modules', 4);
        $this->assertDatabaseCount('lesson_attendances', 5);
        $this->assertNotNull($class->active_lkpd_id);
        $credentials = [['admin', 'admin@gmail.com', 'admin123', 'admin'], ['guru1', 'guru@gmail.com', 'guru123', 'teacher']];
        foreach (range(1, 5) as $n) {
            $credentials[] = ['siswa'.$n, 'siswa'.$n.'@gmail.com', 'siswa123', 'student'];
        }
        foreach ($credentials as [$username, $email, $password, $role]) {
            foreach ([$username, $email] as $identifier) {
                $this->app['auth']->forgetGuards();
                $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])->postJson('/api/v1/auth/login', ['login' => $identifier, 'password' => $password])->assertOk()->assertJsonPath('data.role', $role)->assertJsonPath('data.mustChangePassword', false);
                $this->postJson('/api/v1/auth/logout')->assertOk();
            }
        }
        $student = User::where('username', 'siswa4')->firstOrFail();
        $this->actingAs($student)->getJson('/api/v1/materials?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.0.status', 'published');
        $this->getJson('/api/v1/assignments?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.0.title', 'Tugas Demo: Pengamatan Lingkungan');
        $this->getJson('/api/v1/gradebook?classroom_id='.$class->id)->assertOk()->assertJsonPath('data.students.0.group_work.0.score', 85);
        $lesson = CourseLesson::firstOrFail();
        $this->getJson('/api/v1/course-lessons/'.$lesson->id.'/records')->assertOk()->assertJsonCount(1, 'data.attendance')->assertJsonPath('data.attendance.0.student_id', $student->id);
        $before = GroupWork::firstOrFail()->getAttributes();
        $this->seed(TestingSeeder::class);
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertDatabaseCount('materials', 1);
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseCount('group_works', 1);
        $this->assertSame($before, GroupWork::firstOrFail()->getAttributes());
        $this->assertTrue(Hash::check('siswa123', $student->fresh()->password));
    }

    public function test_conflicting_identity_rolls_back_all_demo_changes(): void
    {
        $existing = User::factory()->create(['username' => 'guru1', 'role' => Role::Student]);
        $before = $existing->fresh()->getAttributes();
        try {
            $this->seed(TestingSeeder::class);
            $this->fail('Conflicting identities must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bertabrakan', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(0, DB::table('schools')->count());
    }
}
