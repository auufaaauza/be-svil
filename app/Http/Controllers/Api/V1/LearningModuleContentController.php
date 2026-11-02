<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateModuleContentRequest;
use App\Http\Resources\LearningModuleResource;
use App\Learning\LearningNotifier;
use App\Learning\LkpdPages;
use App\Learning\QuestionMedia;
use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningModule;
use App\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LearningModuleContentController extends Controller
{
    public function update(UpdateModuleContentRequest $r, LearningModule $module): LearningModuleResource
    {
        $data = $r->validated();
        $class = (int) $data['classroom_id'];
        abort_unless($r->user()->canAccessClassroom($class), 403);
        $uploaded = [];
        try {
            DB::transaction(function () use ($r, $module, $data, $class, &$uploaded): void {
                Classroom::whereKey($class)->lockForUpdate()->firstOrFail();
                $keys = ['learning_module_id' => $module->id, 'classroom_id' => $class];
                $old = DB::table('module_class_media')->where($keys)->first();
                abort_if(($old?->content_version ?? 0) !== (int) $data['version'], 409, 'Isi LKPD sudah diubah. Muat versi terbaru sebelum menyimpan.');
                $fields = [];
                foreach ($data['fields'] as $index => $field) {
                    $field['video'] = QuestionMedia::video($field['video_url'] ?? null, 'fields.'.$index.'.video_url');
                    $field['video_url'] = $field['video']['url'] ?? null;
                    if ($r->hasFile('photos.'.$field['key'])) {
                        $photo = $r->file('photos.'.$field['key']);
                        $extension = match ($photo->getMimeType()) {
                            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
                        };
                        $field['image_id'] = Str::uuid().'.'.$extension;
                        $uploaded[] = $photo->storeAs('lkpd/'.$module->id.'/'.$class, $field['image_id'], 'local');
                    }
                    if (! empty($field['image_id']) && ! Storage::disk('local')->exists('lkpd/'.$module->id.'/'.$class.'/'.$field['image_id'])) {
                        throw ValidationException::withMessages(['fields.'.$index.'.image_id' => 'Foto tidak ditemukan untuk LKPD dan kelas ini. Unggah kembali.']);
                    }
                    $field['image_alt'] = $field['image_alt'] ?? $field['label'];
                    $fields[] = $field;
                }
                $content = ['title' => $data['title'], 'description' => $data['description'], 'instructions' => $data['instructions'], 'fields' => $fields];
                $values = ['content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_version' => ($old?->content_version ?? 0) + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
                if (! $old) {
                    $values += ['version' => 1, 'video_is_overridden' => false, 'created_at' => now()];
                }
                DB::table('module_class_media')->updateOrInsert($keys, $values);
                $saved = DB::table('module_class_media')->where($keys)->first();
                DB::table('module_class_content_revisions')->insert(['module_class_media_id' => $saved->id, 'version' => $saved->content_version, 'actor_id' => $r->user()->id, 'snapshot' => json_encode($content, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                if ($r->boolean('apply_to_drafts')) {
                    $drafts = GroupWork::where('topic', $module->topic)->where('status', 'draft')->whereHas('group', fn ($query) => $query->where('classroom_id', $class))->lockForUpdate()->get();
                    foreach ($drafts as $draft) {
                        if (LkpdPages::entries($draft)) {
                            continue;
                        }
                        $draft->update(['schema_snapshot' => QuestionMedia::fields($fields, $module->id, $class), 'version' => $draft->version + 1]);
                        $draft->recordRevision($r->user());
                    }
                }
                LearningNotifier::audit($r->user()->id, 'lkpd_class_content_updated', 'module', $module->id, ['classroom_id' => $class, 'version' => $saved->content_version]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($uploaded);
            throw $error;
        }

        return new LearningModuleResource($module->fresh());
    }

    public function image(Request $r, LearningModule $module, string $image): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}\.(jpg|png|webp)$/D', $image), 404);
        $data = $r->validate(['classroom_id' => ['required', 'integer', 'exists:classrooms,id']]);
        $class = (int) $data['classroom_id'];
        $historical = $r->user()->role === Role::Student && GroupWork::whereHas('group', fn ($q) => $q->where('classroom_id', $class))->whereJsonContains('member_ids', $r->user()->id)->whereJsonContains('schema_snapshot', ['image_id' => $image])->exists();
        abort_unless($r->user()->canUseSvil() && ($r->user()->canAccessClassroom($class) || $historical), 403);
        $path = 'lkpd/'.$module->id.'/'.$class.'/'.$image;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), ['X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
