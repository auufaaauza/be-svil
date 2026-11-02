<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Learning\LearningNotifier;
use App\Models\User;
use App\PasswordPolicy;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function password(Request $r): JsonResponse
    {
        $data = $r->validate(['current_password' => ['required', 'string', 'max:255'], 'password' => [...PasswordPolicy::rules(), 'confirmed', 'different:current_password']]);
        DB::transaction(function () use ($r, $data): void {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($data['current_password'], $u->password), 422, 'Kata sandi saat ini sudah berubah.');
            $u->forceFill(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->when($r->hasSession(), fn ($q) => $q->where('id', '!=', $r->session()->getId()))->delete();
            $u->tokens()->delete();
            LearningNotifier::audit($u->id, 'password_changed', 'user', $u->id);
        });
        if ($r->hasSession()) {
            $r->session()->regenerate();
        }

        return response()->json(['message' => 'Kata sandi berhasil diganti. Sesi perangkat lain sudah diakhiri.']);
    }

    public function reset(Request $r, User $user): JsonResponse
    {
        abort_unless($r->user()->role === Role::Admin && $r->user()->id !== $user->id, 403);
        $data = $r->validate(['password' => [...PasswordPolicy::rules(), 'confirmed'], 'current_password' => ['required', 'string', 'max:255']]);
        abort_unless(Hash::check($data['current_password'], User::findOrFail($r->user()->id)->password), 422, 'Kata sandi admin tidak cocok.');
        DB::transaction(function () use ($r, $user, $data): void {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $u->forceFill(['password' => $data['password'], 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            $u->tokens()->delete();
            LearningNotifier::audit($r->user()->id, 'password_reset_by_admin', 'user', $u->id);
        });

        return response()->json(['message' => 'Kata sandi awal diperbarui. Pengguna diminta menggantinya saat masuk kembali.']);
    }

    public function profile(Request $r, ?User $user = null): UserResource
    {
        $user ??= $r->user();
        $self = $user->id === $r->user()->id;
        abort_unless($self || $r->user()->role === Role::Admin, 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users')->ignore($user->id)], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'current_password' => [$self ? 'required' : 'nullable', 'string', 'max:255']]);
        $saved = DB::transaction(function () use ($r, $user, $data) {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($u->id === $r->user()->id) {
                abort_unless(Hash::check($data['current_password'], $u->password), 422, 'Kata sandi tidak cocok.');
            }abort_if($u->profile_version !== $data['version'], 409, 'Profil sudah diperbarui. Muat ulang.');
            $u->forceFill(['name' => $data['name'], 'username' => $data['username'], 'email' => Str::lower($data['email']), 'profile_version' => $u->profile_version + 1, 'email_verified_at' => $u->email !== $data['email'] ? null : $u->email_verified_at])->save();
            LearningNotifier::audit($r->user()->id, 'profile_updated', 'user', $u->id);

            return $u;
        });

        return new UserResource($saved->load('school'));
    }
}
