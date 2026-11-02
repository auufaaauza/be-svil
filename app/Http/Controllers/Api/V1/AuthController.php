<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Classroom;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        $login = Str::lower(trim($request->validated('login')));
        $field = str_contains($login, '@') ? 'email' : 'username';
        if (! Auth::guard('web')->attempt([$field => $login, 'password' => $request->validated('password'), 'is_active' => true])) {
            throw ValidationException::withMessages(['login' => ['Username, email, atau kata sandi tidak cocok.']]);
        }
        $request->session()->regenerate();

        return new UserResource($request->user('web')->load('school'));
    }

    public function register(RegisterRequest $request): UserResource
    {
        $user = DB::transaction(function () use ($request) {
            $classroom = Classroom::whereNull('archived_at')->where('join_code', $request->validated('class_code'))->lockForUpdate()->firstOrFail();
            $user = User::create([
                ...$request->safe()->only(['name', 'username', 'email', 'password']),
                'role' => Role::Student, 'school_id' => $classroom->school_id, 'is_active' => true,
            ]);
            $classroom->users()->attach($user);

            return $user;
        });
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new UserResource($user->load('school'));
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()->load('school')))->response()->setStatusCode(200);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
