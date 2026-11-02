<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_and_email_both_authenticate_for_every_role(): void
    {
        foreach ([Role::Admin, Role::Teacher, Role::Student] as $role) {
            $user = User::factory()->create(['role' => $role, 'username' => $role->value.'-user']);
            $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])->postJson('/api/v1/auth/login', ['login' => strtoupper($user->username), 'password' => 'password'])
                ->assertOk()->assertJsonPath('data.id', $user->id);
            $this->postJson('/api/v1/auth/logout')->assertOk();
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])
                ->assertOk()->assertJsonPath('data.id', $user->id);
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
    }

    public function test_registration_joins_only_the_invited_class_and_cannot_grant_admin(): void
    {
        $classroom = Classroom::factory()->create();
        $payload = ['name' => 'Murid Baru', 'username' => 'murid.baru', 'email' => 'murid@svil.test', 'password' => 'Belajar123', 'password_confirmation' => 'Belajar123', 'class_code' => strtolower($classroom->join_code), 'role' => 'admin', 'school_id' => 999];
        $response = $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()->assertJsonPath('data.role', 'student')->assertJsonPath('data.schoolId', $classroom->school_id);
        $student = User::findOrFail($response->json('data.id'));
        $this->assertTrue($student->classrooms()->whereKey($classroom->id)->exists());
        $this->getJson('/api/v1/classrooms')->assertOk()->assertJsonMissingPath('data.0.joinCode');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.username', 'murid.baru');
    }

    public function test_bad_invitation_duplicate_username_and_unconfirmed_password_are_rejected(): void
    {
        $classroom = Classroom::factory()->create();
        User::factory()->create(['username' => 'already-used']);
        $payload = ['name' => 'Murid', 'username' => 'already-used', 'email' => 'new@svil.test', 'password' => 'Belajar123', 'password_confirmation' => 'Belajar123', 'class_code' => $classroom->join_code];
        $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/v1/auth/register', [...$payload, 'username' => 'new-student', 'class_code' => 'BADCODE12345'])->assertUnprocessable()->assertJsonValidationErrors('class_code');
        $this->postJson('/api/v1/auth/register', [...$payload, 'username' => 'new-student', 'password_confirmation' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_malformed_login_is_rejected_without_server_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => ['invalid'], 'password' => 'password'])->assertUnprocessable();
    }
}
