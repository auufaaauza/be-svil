<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceDataController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'profile' => (new UserResource($request->user()->load('school')))->resolve($request),
            'materials' => app(MaterialController::class)->index($request)->response()->getData(true),
            'classrooms' => app(ClassroomController::class)->index($request)->response()->getData(true),
            'notes' => app(LearningNoteController::class)->index($request)->getData(true),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function classroom(Request $request): JsonResponse
    {
        $request->validate(['classroom_id' => ['required', 'integer']]);
        abort_unless($request->user()->canAccessClassroom($request->integer('classroom_id')), 403);

        return response()->json(['data' => [
            'announcements' => app(CourseController::class)->announcements($request)->getData(true),
            'assignments' => app(AssignmentController::class)->index($request)->getData(true),
            'assessments' => app(AssessmentController::class)->index($request)->getData(true),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function reflection(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === Role::Student && $request->user()->canUseSvil(), 403);

        return response()->json(['data' => [
            'schemas' => app(ReflectionController::class)->schema()->getData(true),
            'reflections' => app(ReflectionController::class)->index($request)->getData(true),
            'attempts' => app(MaterialAttemptController::class)->index($request)->getData(true),
            'observations' => app(ObservationController::class)->index($request)->getData(true),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
