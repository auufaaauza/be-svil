<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRequest;
use App\Models\School;
use Illuminate\Http\JsonResponse;

class SchoolController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(School::query()->withCount('classrooms')->orderBy('name')->paginate(30));
    }

    public function store(StoreSchoolRequest $request): JsonResponse
    {
        return response()->json(['data' => School::create($request->validated())], 201);
    }
}
