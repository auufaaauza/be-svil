<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('group_works', 'page_submissions')) {
            Schema::table('group_works', fn (Blueprint $t) => $t->json('page_submissions')->nullable());
        }
        foreach (['content', 'content_version', 'video_is_overridden'] as $column) {
            if (Schema::hasColumn('module_class_media', $column)) {
                continue;
            }
            Schema::table('module_class_media', function (Blueprint $t) use ($column): void {
                match ($column) {
                    'content' => $t->json('content')->nullable(),
                    'content_version' => $t->unsignedInteger('content_version')->default(0),
                    default => $t->boolean('video_is_overridden')->default(true),
                };
            });
        }
        if (! Schema::hasTable('module_class_content_revisions')) {
            Schema::create('module_class_content_revisions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('module_class_media_id')->constrained('module_class_media')->restrictOnDelete();
                $t->unsignedInteger('version');
                $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $t->json('snapshot');
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['module_class_media_id', 'version'], 'class_lkpd_content_version_unique');
            });
        }
        DB::table('group_works')->whereNull('page_submissions')->orderBy('id')->chunkById(100, function ($works): void {
            foreach ($works as $work) {
                $pages = [];
                if ($work->status !== 'draft') {
                    $fields = json_decode($work->schema_snapshot ?? 'null', true) ?? json_decode(DB::table('learning_modules')->where('topic', $work->topic)->value('fields') ?? '[]', true);
                    foreach (array_chunk($fields, 4) as $index => $unused) {
                        $pages[$index] = ['submitted_at' => $work->submitted_at, 'submitted_by' => null, 'review_status' => in_array($work->status, ['reviewed', 'needs_revision'], true) ? $work->status : 'pending', 'feedback' => $work->feedback, 'reviewed_at' => $work->reviewed_at, 'reviewed_by' => $work->reviewed_by];
                    }
                }
                DB::table('group_works')->where('id', $work->id)->whereNull('page_submissions')->update(['page_submissions' => json_encode($pages, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_class_content_revisions');
        Schema::table('module_class_media', fn (Blueprint $t) => $t->dropColumn(['content', 'content_version', 'video_is_overridden']));
        Schema::table('group_works', fn (Blueprint $t) => $t->dropColumn('page_submissions'));
    }
};
