<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentAccountGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://127.0.0.1:3000']);
    }

    private function context(): array
    {
        $class = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $class->school_id]);
        $class->users()->attach($teacher);

        return [$class, $teacher, ['classroom_id' => $class->id, 'request_id' => (string) Str::uuid(), 'students' => [['name' => 'Aisyah Putri'], ['name' => 'Aisyah Zahra'], ['name' => 'Émile Hasan'], ['name' => 'Ai Nur']]]];
    }

    private function preview(array $payload): array
    {
        return $this->postJson('/api/v1/roster/generate', $payload)->assertOk()->json('data');
    }

    public function test_preview_create_and_login_use_unique_usernames_optional_email_and_the_default_password(): void
    {
        [$class, $teacher, $payload] = $this->context();
        User::factory()->create(['username' => 'Aisyah']);
        $this->actingAs($teacher);
        $before = User::count();
        $preview = $this->preview($payload);
        $this->assertSame(['aisyah2', 'aisyah3', 'emile', 'ai01'], array_column($preview['rows'], 'username'));
        $this->assertSame($before, User::count());
        $response = $this->postJson('/api/v1/roster/generate', [...$payload, 'commit' => true, 'preview_token' => $preview['preview_token']])->assertCreated()->assertJsonPath('data.count', 4)->assertJsonPath('data.created.0.email', null)->assertJsonPath('data.created.0.initial_password', 'siswa123');
        foreach ($response->json('data.created') as $account) {
            $student = User::findOrFail($account['id']);
            $this->assertSame(Role::Student, $student->role);
            $this->assertSame($class->school_id, $student->school_id);
            $this->assertTrue(Hash::check('siswa123', $student->password));
            $this->assertTrue($class->users()->whereKey($student->id)->exists());
        }
        $this->assertStringNotContainsString('siswa123', DB::table('student_account_batches')->first()->accounts);
        $this->assertStringNotContainsString('siswa123', DB::table('learning_audits')->get()->toJson());
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => 'aisyah2', 'password' => 'siswa123'])->assertOk()->assertJsonPath('data.email', null);
    }

    public function test_retrying_a_committed_batch_does_not_create_duplicate_students(): void
    {
        [$class, $teacher, $payload] = $this->context();
        $this->actingAs($teacher);
        $preview = $this->preview($payload);
        $body = [...$payload, 'commit' => true, 'preview_token' => $preview['preview_token']];
        $first = $this->postJson('/api/v1/roster/generate', $body)->assertCreated()->json('data.created');
        $this->postJson('/api/v1/roster/generate', $body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.created.0.id', $first[0]['id']);
        $this->assertSame(4, User::where('role', Role::Student)->count());
        $this->assertDatabaseCount('student_account_batches', 1);
        $this->postJson('/api/v1/roster/generate', [...$body, 'students' => [['name' => 'Daftar berbeda']]])->assertConflict();
    }

    public function test_role_class_and_archival_limits_apply_to_preview_and_creation(): void
    {
        [$class, $teacher, $payload] = $this->context();
        $this->actingAs($teacher);
        $preview = $this->preview($payload);
        $other = Classroom::factory()->create(['school_id' => $class->school_id]);
        $this->postJson('/api/v1/roster/generate', [...$payload, 'classroom_id' => $other->id])->assertForbidden();
        $this->postJson('/api/v1/roster/generate', [...$payload, 'classroom_id' => $other->id, 'commit' => true, 'preview_token' => $preview['preview_token']])->assertForbidden();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach($student);
        $this->actingAs($student)->postJson('/api/v1/roster/generate', $payload)->assertForbidden();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->postJson('/api/v1/roster/generate', [...$payload, 'students' => [['name' => 'Murid', 'role' => 'admin']]])->assertUnprocessable();
        $adminPreview = $this->preview($payload);
        $this->postJson('/api/v1/roster/generate', [...$payload, 'commit' => true, 'preview_token' => $adminPreview['preview_token']])->assertCreated();
        $class->update(['archived_at' => now()]);
        $this->actingAs($teacher)->postJson('/api/v1/roster/generate', $payload)->assertUnprocessable();
    }

    public function test_changed_expired_or_conflicting_previews_cannot_create_partial_batches(): void
    {
        [$class, $teacher, $payload] = $this->context();
        $this->actingAs($teacher);
        $preview = $this->preview($payload);
        $body = [...$payload, 'commit' => true, 'preview_token' => $preview['preview_token']];
        $this->postJson('/api/v1/roster/generate', [...$body, 'preview_token' => 'diubah'])->assertUnprocessable();
        $this->postJson('/api/v1/roster/generate', [...$body, 'students' => [['name' => 'Nama lain']]])->assertConflict();
        User::factory()->create(['username' => $preview['rows'][1]['username']]);
        $before = User::count();
        $this->postJson('/api/v1/roster/generate', $body)->assertConflict();
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('student_account_batches', 0);
        $next = $this->preview($payload);
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/roster/generate', [...$body, 'preview_token' => $next['preview_token']])->assertConflict();
    }

    public function test_email_validation_and_bulk_limits_allow_multiple_empty_addresses(): void
    {
        [$class, $teacher, $payload] = $this->context();
        $this->actingAs($teacher)->postJson('/api/v1/roster/generate', [...$payload, 'students' => [['name' => 'Satu', 'email' => 'sama@example.test'], ['name' => 'Dua', 'email' => 'SAMA@example.test']]])->assertUnprocessable();
        $this->postJson('/api/v1/roster/generate', [...$payload, 'students' => [['name' => 'Satu', 'email' => 'bukan email']]])->assertUnprocessable();
        $preview = $this->preview([...$payload, 'students' => [['name' => 'Satu', 'email' => null], ['name' => 'Dua', 'email' => ''], ['name' => 'Tiga', 'email' => 'TIGA@example.test']]]);
        $this->assertSame('tiga@example.test', $preview['rows'][2]['email']);
        User::factory()->create(['email' => 'TERPAKAI@example.test']);
        $this->postJson('/api/v1/roster/generate', [...$payload, 'students' => [['name' => 'Murid', 'email' => 'terpakai@example.test']]])->assertUnprocessable();
        $this->postJson('/api/v1/roster/generate', [...$payload, 'students' => array_fill(0, 501, ['name' => 'Murid'])])->assertUnprocessable();
    }

    public function test_student_registration_profile_admin_creation_and_csv_can_omit_email(): void
    {
        $class = Classroom::factory()->create();
        $this->postJson('/api/v1/auth/register', ['name' => 'Murid Baru', 'username' => 'murid.baru', 'password' => 'siswa123', 'password_confirmation' => 'siswa123', 'class_code' => $class->join_code])->assertCreated()->assertJsonPath('data.email', null);
        $this->putJson('/api/v1/account/profile', ['version' => 1, 'name' => 'Murid Baru', 'username' => 'murid.baru', 'current_password' => 'siswa123', 'email' => null])->assertOk()->assertJsonPath('data.email', null);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Guru Baru', 'username' => 'guru.baru', 'password' => 'siswa123', 'role' => 'teacher', 'school_id' => $class->school_id])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/users', ['name' => 'Murid Tanpa Email', 'password' => 'siswa123', 'role' => 'student', 'school_id' => $class->school_id, 'classroom_ids' => [$class->id]])->assertCreated()->assertJsonPath('data.email', null);
        $file = UploadedFile::fake()->createWithContent('siswa.csv', "name,username,email,password\nMurid Satu,murid.satu,,siswa123\nMurid Dua,murid.dua,,siswa123\n");
        $this->post('/api/v1/roster/import', ['classroom_id' => $class->id, 'file' => $file, 'commit' => 1], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.count', 2)->assertJsonPath('data.created.0.email', null);

    }
}
