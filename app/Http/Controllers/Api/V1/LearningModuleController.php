<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLearningModuleRequest;
use App\Http\Requests\UpdateLearningModuleRequest;
use App\Http\Resources\LearningModuleResource;
use App\Learning\QuestionMedia;
use App\Learning\TikTokVideoResolver;
use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LearningModuleController extends Controller
{
    public function games(Request $request): JsonResponse
    {
        $request->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id']]);
        abort_unless($request->user()->canAccessClassroom($request->integer('classroom_id')) && $request->user()->canUseSvil(), 403);
        $games = json_decode(file_get_contents(database_path('data/lkpd-games.json')), true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['data' => $games])->header('Cache-Control', 'private, no-store');
    }

    public function resolveVideo(Request $request, TikTokVideoResolver $resolver): JsonResponse
    {
        abort_unless($request->user()->canUseSvil(), 403);
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);
        $video = QuestionMedia::video($data['url']);
        if (($video['provider'] ?? '') === 'tiktok' && empty($video['id'])) {
            $video = $resolver->resolve($video['url']);
        }
        abort_unless($video && ($video['provider'] === 'file' || ! empty($video['id'])), 422, 'Video belum bisa diputar di sini. Coba lagi nanti atau minta guru memeriksa tautannya.');

        return response()->json(['data' => $video])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $class = $request->integer('classroom_id');
        if ($class) {
            abort_unless($request->user()->canAccessClassroom($class), 403);
        }

        return LearningModuleResource::collection(LearningModule::with(['uploader', 'editor'])
            ->visibleTo($request->user())
            ->when($class, fn ($q) => $q->where(fn ($q) => $q->whereNull('classroom_id')->orWhere('classroom_id', $class)))
            ->when($request->user()->role === Role::Student, fn ($q) => $q->where('status', 'published')->when(! $request->user()->canUseSvil(), fn ($q) => $q->whereRaw('1=0')))
            ->orderBy('topic')->paginate(20));
    }

    public function show(Request $request, LearningModule $module): LearningModuleResource
    {
        abort_unless($module->canView($request->user()), 403);
        if ($request->filled('classroom_id')) {
            abort_unless($request->user()->canAccessClassroom($request->integer('classroom_id')) && $module->availableIn($request->integer('classroom_id')), 403);
        }

        return new LearningModuleResource($module->load(['uploader', 'editor']));
    }

    public function progress(Request $request): JsonResponse
    {
        $request->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id']]);
        $class = $request->integer('classroom_id');
        abort_unless($request->user()->canAccessClassroom($class), 403);
        $student = $request->user()->role === Role::Student;
        $modules = LearningModule::visibleTo($request->user())->where(fn ($q) => $q->whereNull('classroom_id')->orWhere('classroom_id', $class))->when($student, fn ($q) => $q->where('status', 'published')->when(! $request->user()->canUseSvil(), fn ($q) => $q->whereRaw('1=0')))->orderBy('topic')->get();
        $works = GroupWork::whereHas('group', fn ($q) => $q->where('classroom_id', $class))->when($student, fn ($q) => $q->whereJsonContains('member_ids', $request->user()->id))->with(['group:id,name', 'reviewer:id,name'])->get();
        $rows = $modules->map(fn ($module) => ['id' => $module->id, 'topic' => $module->topic, 'title' => $module->contentFor($class)['title'], 'works' => $works->where('topic', $module->topic)->map(fn ($work) => ['id' => $work->id, 'group' => $work->group->name, 'status' => $work->status, 'score' => $work->summary()['score'], 'pages_submitted' => $work->summary()['pages_submitted'], 'pages_total' => $work->summary()['pages_total'], 'reviewed_at' => $work->reviewed_at, 'feedback' => $work->feedback, 'reviewedBy' => $work->reviewer?->name])->values()]);

        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreLearningModuleRequest $request): LearningModuleResource
    {
        $class = $request->integer('classroom_id') ?: null;
        if ($class) {
            abort_unless($request->user()->canAccessClassroom($class), 403);
        }
        $module = DB::transaction(function () use ($request, $class) {
            User::orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = LearningModule::where('create_request_id', $request->input('request_id'))->first();
            if ($existing) {
                abort_unless($existing->uploaded_by === $request->user()->id && $existing->classroom_id === $class, 409);

                return $existing;
            }
            $fields = array_map(function (array $field): array {
                $field['hint'] = $field['hint'] ?? '';
                $field['video'] = QuestionMedia::video($field['video_url'] ?? null);
                $field['video_url'] = $field['video']['url'] ?? null;

                return $field;
            }, $request->validated('fields'));
            $module = LearningModule::create([
                ...$request->safe()->only(['title', 'description', 'instructions']),
                'topic' => max(4, (int) LearningModule::max('topic') + 1),
                'classroom_id' => $class, 'create_request_id' => $request->input('request_id'),
                'fields' => $fields, 'page_size' => 1, 'source_text' => '',
                'source_document' => 'LKPD disusun melalui editor', 'source_hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
                'status' => 'draft', 'version' => 1, 'uploaded_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $module->recordRevision();

            return $module;
        });

        return new LearningModuleResource($module->load(['uploader', 'editor']));
    }

    public function activate(Request $request, LearningModule $module): JsonResponse
    {
        $request->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id']]);
        $class = $request->integer('classroom_id');
        abort_unless($request->user()->canAccessClassroom($class) && $module->availableIn($class), 403);
        abort_unless($module->status === 'published', 422, 'Terbitkan LKPD sebelum menjadikannya kegiatan aktif.');
        Classroom::whereKey($class)->update(['active_lkpd_id' => $module->id]);

        return response()->json(['message' => 'LKPD aktif kelas sudah diperbarui.', 'data' => ['module_id' => $module->id]]);
    }

    public function videoInfo(Request $r, int $topic): JsonResponse
    {
        $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())]]);
        $module = LearningModule::where('topic', $topic)->firstOrFail();
        abort_unless($r->user()->canUseSvil() && $module->canView($r->user()) && $module->availableIn($r->integer('classroom_id')), 403);

        return response()->json(['data' => ['title' => $module->title, ...$module->mediaFor($r->integer('classroom_id'))]]);
    }

    public function update(UpdateLearningModuleRequest $request, LearningModule $module): LearningModuleResource
    {
        $saved = DB::transaction(function () use ($request, $module) {
            $module = LearningModule::whereKey($module->id)->lockForUpdate()->firstOrFail();
            abort_if($module->version !== $request->integer('version'), 409, 'LKPD sudah diperbarui. Muat versi terbaru sebelum menyunting.');
            $module->update([...$request->safe()->except(['version', 'youtube_url']), 'version' => $module->version + 1, 'updated_by' => $request->user()->id]);
            $module->recordRevision();

            return $module;
        });

        return new LearningModuleResource($saved->load(['uploader', 'editor']));
    }
}
