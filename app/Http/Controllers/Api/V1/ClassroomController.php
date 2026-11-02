<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignClassroomRequest;
use App\Http\Requests\StoreClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Http\Resources\UserResource;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClassroomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ClassroomResource::collection($request->user()->accessibleClassrooms()->with('school')->orderBy('name')->paginate(50));
    }

    public function store(StoreClassroomRequest $request): ClassroomResource
    {
        return new ClassroomResource(Classroom::create($request->validated())->load('school'));
    }

    public function members(Request $request, Classroom $classroom): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAccessClassroom($classroom->id), 403);

        return UserResource::collection(($request->boolean('include_inactive') ? $classroom->belongsToMany(User::class)->withPivot('is_active')->withTimestamps() : $classroom->users())->with('school')->orderBy('name')->paginate(100));
    }

    public function assign(AssignClassroomRequest $request, Classroom $classroom): JsonResponse
    {
        $classroom->belongsToMany(User::class)->withTimestamps()->syncWithoutDetaching(array_fill_keys($request->validated('user_ids'), ['is_active' => true]));

        return response()->json(['message' => 'Anggota kelas ditambahkan.']);
    }
}
