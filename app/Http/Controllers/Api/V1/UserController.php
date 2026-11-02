<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->with('school')->orderBy('name')->paginate(50));
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->except('classroom_ids'));
            $user->classrooms()->sync($request->validated('classroom_ids', []));

            return $user;
        });

        return new UserResource($user->load('school'));
    }

    public function active(UpdateUserStatusRequest $request, User $user): UserResource
    {
        abort_if($user->id === $request->user()->id, 422, 'Tidak dapat menonaktifkan akun sendiri.');
        $user->update($request->validated());

        return new UserResource($user->load('school'));
    }
}
