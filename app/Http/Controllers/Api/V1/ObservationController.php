<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveObservationRequest;
use App\Models\Observation;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Observation::whereIn('classroom_id', $request->user()->accessibleClassrooms()->select('classrooms.id'))
            ->when($request->user()->role === Role::Student, fn ($query) => $query->where('student_id', $request->user()->id))->latest()->paginate(50));
    }

    public function store(SaveObservationRequest $request): JsonResponse
    {
        $observation = DB::transaction(function () use ($request) {
            User::whereKey($request->integer('student_id'))->lockForUpdate()->firstOrFail();
            $keys = $request->safe()->only(['classroom_id', 'student_id', 'topic', 'occasion']);
            $observation = Observation::where($keys)->first();
            abort_if(($observation?->version ?? 0) !== $request->integer('version'), 409, 'Observasi sudah berubah. Muat ulang sebelum memperbaiki catatan.');
            $version = ($observation?->version ?? 0) + 1;
            $observation ??= new Observation($keys);
            $observation->fill([...$request->safe()->except('version'), 'teacher_id' => $request->user()->id, 'version' => $version]);
            $observation->save();
            DB::table('observation_revisions')->insert(['observation_id' => $observation->id, 'version' => $version, 'snapshot' => json_encode($observation->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $observation;
        });

        return response()->json(['data' => $observation]);
    }
}
