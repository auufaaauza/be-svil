<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_accepts_six_and_twelve_characters_and_rejects_outside_the_range(): void
    {
        $class = Classroom::factory()->create();
        foreach (['short' => 'abc12', 'long' => 'abcdefgh12345', 'six' => 'abc123', 'twelve' => 'abcdefgh1234'] as $name => $password) {
            $response = $this->withHeaders(['Origin' => 'http://127.0.0.1:3000'])->postJson('/api/v1/auth/register', ['name' => 'Murid '.$name, 'username' => 'murid.'.$name, 'email' => $name.'@svil.test', 'password' => $password, 'password_confirmation' => $password, 'class_code' => $class->join_code]);
            if (in_array($name, ['short', 'long'], true)) {
                $response->assertUnprocessable()->assertJsonValidationErrors('password');
            } else {
                $response->assertCreated();
                $this->assertTrue(Hash::check($password, User::where('username', 'murid.'.$name)->firstOrFail()->password));
                $this->postJson('/api/v1/auth/logout')->assertOk();
                $this->app['auth']->forgetGuards();
            }
        }
    }

    public function test_admin_creation_reset_and_self_change_follow_the_same_password_range(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $student = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Admin Baru', 'username' => 'admin.baru', 'email' => 'new-admin@svil.test', 'role' => 'admin', 'password' => 'abc123', 'classroom_ids' => []])->assertCreated();
        $this->putJson('/api/v1/users/'.$student->id.'/password', ['current_password' => 'password', 'password' => 'abcdefgh1234', 'password_confirmation' => 'abcdefgh1234'])->assertOk();
        $this->assertTrue(Hash::check('abcdefgh1234', $student->fresh()->password));
        $this->putJson('/api/v1/account/password', ['current_password' => 'password', 'password' => 'abc123', 'password_confirmation' => 'abc123'])->assertOk();
        $this->assertTrue(Hash::check('abc123', $admin->fresh()->password));
        $this->putJson('/api/v1/account/password', ['current_password' => 'abc123', 'password' => 'abcdefgh12345', 'password_confirmation' => 'abcdefgh12345'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_roster_accepts_a_six_character_password_and_generates_twelve_character_passwords(): void
    {
        $class = Classroom::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $file = UploadedFile::fake()->createWithContent('murid.csv', "name,username,email,password\nMurid Satu,murid.satu,satu@svil.test,abc123\nMurid Dua,murid.dua,dua@svil.test,\n");
        $rows = $this->actingAs($admin)->post('/api/v1/roster/import', ['classroom_id' => $class->id, 'file' => $file, 'commit' => 1], ['Accept' => 'application/json'])->assertCreated()->json('data.created');
        $this->assertSame('abc123', $rows[0]['initial_password']);
        $this->assertSame(12, strlen($rows[1]['initial_password']));
        $this->assertTrue(Hash::check($rows[1]['initial_password'], User::findOrFail($rows[1]['id'])->password));
    }
}
