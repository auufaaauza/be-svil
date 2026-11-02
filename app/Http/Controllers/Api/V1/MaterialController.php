<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Learning\SlideReader;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['kind' => ['nullable', 'in:material,quiz'], 'classroom_id' => ['nullable', 'integer'], 'lesson_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:draft,published'], 'search' => ['nullable', 'string', 'max:100']]);

        return MaterialResource::collection(Material::query()->with(['teacher:id,name', 'editor:id,name', 'lesson:id,title,course_id'])->visibleTo($request->user())
            ->when($filters['lesson_id'] ?? null, fn ($q, $lesson) => $q->where('course_lesson_id', $lesson))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['classroom_id'] ?? null, fn ($q, $classroom) => $q->where('classroom_id', $classroom))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('title', 'like', '%'.$search.'%'))
            ->orderByDesc('updated_at')->paginate(30));
    }

    public function show(Material $material): MaterialResource
    {
        Gate::authorize('view', $material);

        return new MaterialResource($material);
    }

    public function store(SaveMaterialRequest $request): MaterialResource
    {
        return $this->persist($request);
    }

    public function update(SaveMaterialRequest $request, Material $material): MaterialResource
    {
        return $this->persist($request, $material);
    }

    private function persist(SaveMaterialRequest $request, ?Material $material = null): MaterialResource
    {
        if ($request->hasFile('attachment')) {
            if (strtolower($request->file('attachment')->getClientOriginalExtension()) === 'pptx') {
                (new SlideReader($request->file('attachment')->getRealPath()))->read();
            } else {
                abort_unless($request->file('attachment')->getMimeType() === 'application/pdf', 422, 'Gunakan PDF yang valid atau PowerPoint .pptx.');
            }
        }
        $newPath = $request->hasFile('image') ? $request->file('image')->store('materials', 'local') : null;
        $oldPath = null;
        $newAttachment = $request->hasFile('attachment') ? $request->file('attachment')->store('attachments', 'local') : null;
        $oldAttachment = null;
        try {
            $saved = DB::transaction(function () use ($request, $material, $newPath, &$oldPath, $newAttachment, &$oldAttachment) {
                $target = $material ? Material::whereKey($material->id)->lockForUpdate()->firstOrFail() : new Material;
                if ($material) {
                    abort_if($target->version !== $request->integer('version'), 409, 'Materi telah diperbarui. Muat ulang pustaka sebelum menyunting.');
                    abort_if($target->classroom_id !== $request->integer('classroom_id'), 422, 'Kelas materi tidak dapat dipindahkan. Buat materi baru untuk kelas lain.');
                    $oldPath = $target->image_path;
                    $oldAttachment = $target->attachment_path;
                }
                $values = $request->safe()->except(['image', 'remove_image', 'version', 'attachment', 'remove_attachment']);
                $target->fill($values);
                if (! $material) {
                    $target->teacher_id = $request->user()->id;
                }
                if ($newPath || $request->boolean('remove_image')) {
                    $target->image_path = $newPath;
                }
                if (! $target->image_path) {
                    $target->image_alt = null;
                }
                if ($newAttachment) {
                    $target->fill(['attachment_path' => $newAttachment, 'attachment_name' => basename($request->file('attachment')->getClientOriginalName()), 'attachment_size' => $request->file('attachment')->getSize(), 'attachment_mime' => strtolower($request->file('attachment')->getClientOriginalExtension()) === 'pptx' ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation' : 'application/pdf']);
                } elseif ($request->boolean('remove_attachment')) {
                    $target->fill(['attachment_path' => null, 'attachment_name' => null, 'attachment_size' => null, 'attachment_mime' => null]);
                }
                $target->updated_by = $request->user()->id;
                $target->version = $material ? $target->version + 1 : 1;
                $target->save();

                return $target;
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            if ($newAttachment) {
                Storage::disk('local')->delete($newAttachment);
            }
            throw $exception;
        }
        if ($oldPath && $oldPath !== $saved->image_path) {
            Storage::disk('local')->delete($oldPath);
        }
        if ($oldAttachment && $oldAttachment !== $saved->attachment_path) {
            Storage::disk('local')->delete($oldAttachment);
        }

        return new MaterialResource($saved);
    }

    public function image(Material $material): StreamedResponse
    {
        Gate::authorize('view', $material);
        abort_unless($material->image_path && Storage::disk('local')->exists($material->image_path), 404);

        return Storage::disk('local')->response($material->image_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function attachment(Material $material): StreamedResponse
    {
        Gate::authorize('view', $material);
        abort_unless($material->attachment_path && Storage::disk('local')->exists($material->attachment_path), 404);

        return Storage::disk('local')->download($material->attachment_path, $material->attachment_name, ['Content-Type' => $material->attachment_mime ?? 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preview(Material $material): StreamedResponse
    {
        Gate::authorize('view', $material);
        abort_unless($material->attachment_path && Storage::disk('local')->exists($material->attachment_path), 404);
        abort_unless(($material->attachment_mime ?? 'application/pdf') === 'application/pdf', 422, 'Gunakan pembaca slide untuk PowerPoint.');

        return Storage::disk('local')->response($material->attachment_path, $material->attachment_name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN'], 'inline');
    }

    public function slides(Material $material): JsonResponse
    {
        Gate::authorize('view', $material);
        abort_unless($material->attachment_path && Storage::disk('local')->exists($material->attachment_path), 404);
        abort_unless($material->attachment_mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 422, 'Materi ini bukan PPTX.');
        $path = Storage::disk('local')->path($material->attachment_path);
        $data = Cache::remember('svil-slides:v1:'.hash_file('sha256', $path), 3600, fn () => (new SlideReader($path))->read());
        foreach ($data['slides'] as &$slide) {
            foreach ($slide['elements'] as &$element) {
                if ($element['kind'] === 'image') {
                    $element['url'] = route('materials.slide-asset', [$material->id, $element['asset']], false);
                }
            }unset($element);
        }unset($slide);

        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function slideAsset(Material $material, string $asset): Response
    {
        Gate::authorize('view', $material);
        abort_unless($material->attachment_path && Storage::disk('local')->exists($material->attachment_path), 404);
        $image = (new SlideReader(Storage::disk('local')->path($material->attachment_path)))->asset($asset);

        return response($image['bytes'], 200, ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
