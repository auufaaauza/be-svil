<?php

namespace Tests\Feature;

use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reset_revokes_sessions_and_requires_the_owner_to_change_password(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'password' => 'AdminKnown123']);
        $student = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $student->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->putJson('/api/v1/users/'.$student->id.'/password', ['current_password' => 'bad', 'password' => 'Temporary123', 'password_confirmation' => 'Temporary123'])->assertUnprocessable();
        $this->putJson('/api/v1/users/'.$student->id.'/password', ['current_password' => 'AdminKnown123', 'password' => 'Temporary123', 'password_confirmation' => 'Temporary123'])->assertOk();
        $this->assertTrue(Hash::check('Temporary123', $student->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->actingAs($student->fresh())->postJson('/api/v1/materials')->assertStatus(423);
        $this->actingAs($student->fresh())->putJson('/api/v1/account/password', ['current_password' => 'Temporary123', 'password' => 'Personal1234', 'password_confirmation' => 'Personal1234'])->assertOk();
        $this->assertFalse($student->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Personal1234', $student->fresh()->password));
        $audit = DB::table('learning_audits')->get()->toJson();
        $this->assertStringNotContainsString('Temporary123', $audit);
        $this->assertStringNotContainsString('Personal1234', $audit);
    }

    public function test_profiles_are_versioned_and_role_changes_cannot_be_injected(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $student = User::factory()->create();
        $fields = ['version' => 1, 'name' => 'Nama diperbaiki', 'username' => 'nama.baru', 'email' => 'nama.baru@svil.test', 'role' => 'admin', 'school_id' => null];
        $this->actingAs($admin)->putJson('/api/v1/users/'.$student->id.'/profile', $fields)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.role', 'student');
        $this->putJson('/api/v1/users/'.$student->id.'/profile', $fields)->assertConflict();
        $this->actingAs($student)->putJson('/api/v1/users/'.$admin->id.'/profile', $fields)->assertForbidden();
        $owner = User::factory()->create(['password' => 'Current1234']);
        $self = ['version' => 1, 'name' => 'Nama pribadi', 'username' => 'nama.pribadi', 'email' => 'pribadi@svil.test', 'current_password' => 'wrong'];
        $this->actingAs($owner)->putJson('/api/v1/account/profile', $self)->assertUnprocessable();
        $this->putJson('/api/v1/account/profile', [...$self, 'current_password' => 'Current1234'])->assertOk()->assertJsonPath('data.name','Nama pribadi');
    }
}
