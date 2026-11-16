<?php

namespace Tests\Feature;

use App\Models\User;
use App\Role;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_creates_only_the_first_admin_and_both_login_identifiers_work(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame('admin@gmail.com', $admin->email);
        $this->assertTrue($admin->is_active);
        $this->assertNull($admin->school_id);
        $this->assertTrue(Hash::check('admin123', $admin->getRawOriginal('password')));
        $this->assertNotSame('admin123', $admin->getRawOriginal('password'));
        $passwordHash = $admin->getRawOriginal('password');
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($passwordHash, $admin->fresh()->getRawOriginal('password'));
        foreach (['schools', 'classrooms', 'materials', 'learning_groups', 'research_studies'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        foreach (['admin', 'admin@gmail.com'] as $login) {
            $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])
                ->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'admin123'])
                ->assertOk()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.id', $admin->id);
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
    }

    public function test_rerunning_the_seeder_preserves_an_existing_admin_password_and_inactive_status(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Sekolah',
            'username' => 'admin',
            'email' => 'admin@gmail.com',
            'password' => 'PasswordYangSudahDiganti',
            'role' => Role::Admin,
            'is_active' => false,
        ]);
        $before = $admin->fresh()->getAttributes();
        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertTrue(Hash::check('PasswordYangSudahDiganti', $admin->fresh()->password));
    }

    public function test_a_reserved_identity_owned_by_a_student_is_not_promoted_or_overwritten(): void
    {
        $student = User::factory()->create(['username' => 'admin', 'role' => Role::Student]);
        $before = $student->fresh()->getAttributes();
        try {
            (new AdminSeeder)->run();
            $this->fail('An identity conflict must stop seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('akun non-admin', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $student->fresh()->getAttributes());
    }

    public function test_an_existing_admin_with_another_identity_prevents_a_second_default_admin(): void
    {
        $admin = User::factory()->create(['username' => 'kepala.sekolah', 'email' => 'kepala@example.test', 'role' => Role::Admin]);
        $before = $admin->fresh()->getAttributes();
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['username' => 'admin']);
        $this->assertSame($before, $admin->fresh()->getAttributes());
    }
}
