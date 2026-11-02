<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Models\LearningGroup;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearningGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(LearningGroup::query()->whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))
            ->when($request->user()->role === Role::Student, fn ($query) => $query->whereHas('users', fn ($members) => $members->where('users.id', $request->user()->id)))
            ->with('users:id,name')->orderBy('name')->paginate(50));
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = DB::transaction(function () use ($request) {
            $classroom = $request->user()->accessibleClassrooms()->lockForUpdate()->findOrFail($request->integer('classroom_id'));
            $exists = LearningGroup::where('classroom_id', $classroom->id)->whereHas('users', fn ($query) => $query->whereIn('users.id', $request->validated('student_ids')))->exists();
            if ($exists) {
                throw ValidationException::withMessages(['student_ids' => ['Siswa sudah memiliki kelompok pada kelas ini.']]);
            }
            $group = LearningGroup::create($request->safe()->except('student_ids'));
            $group->users()->sync($request->validated('student_ids'));

            return $group;
        });

        return response()->json(['data' => $group->load('users:id,name')], 201);
    }
}
