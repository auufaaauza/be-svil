<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLearningModuleRequest;
use App\Http\Resources\LearningModuleResource;
use App\Models\LearningModule;
use App\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LearningModuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LearningModuleResource::collection(LearningModule::with(['uploader', 'editor'])->when($request->user()->role === Role::Student, fn ($q) => $q->where('status', 'published')->when(! $request->user()->canUseSvil(), fn ($q) => $q->whereRaw('1=0')))->orderBy('topic')->paginate(20));
    }

    public function show(Request $request, LearningModule $module): LearningModuleResource
    {
        abort_if($request->user()->role === Role::Student && ($module->status !== 'published' || ! $request->user()->canUseSvil()), 403);

        return new LearningModuleResource($module->load(['uploader', 'editor']));
    }

    public function update(UpdateLearningModuleRequest $request, LearningModule $module): LearningModuleResource
    {
        $saved = DB::transaction(function () use ($request, $module) {
            $module = LearningModule::whereKey($module->id)->lockForUpdate()->firstOrFail();
            abort_if($module->version !== $request->integer('version'), 409, 'LKPD sudah diperbarui. Muat versi terbaru sebelum menyunting.');
            $module->update([...$request->safe()->except('version'), 'version' => $module->version + 1, 'updated_by' => $request->user()->id]);
            $module->recordRevision();

            return $module;
        });

        return new LearningModuleResource($saved->load(['uploader', 'editor']));
    }
}
