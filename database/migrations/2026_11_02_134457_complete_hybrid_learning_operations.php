<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false);
            $t->unsignedInteger('profile_version')->default(1);
        });
        Schema::table('classrooms', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
        });
        Schema::table('classroom_user', fn (Blueprint $t) => $t->boolean('is_active')->default(true)->index());
        Schema::table('learning_groups', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
        });
        Schema::table('learning_group_user', fn (Blueprint $t) => $t->boolean('is_active')->default(true)->index());
        Schema::table('learning_modules', function (Blueprint $t) {
            $t->string('youtube_video_id', 11)->nullable();
            $t->text('video_caption')->nullable();
        });
        Schema::create('module_class_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('learning_module_id')->constrained()->restrictOnDelete();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->string('youtube_video_id', 11)->nullable();
            $t->text('video_caption')->nullable();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['learning_module_id', 'classroom_id']);
        });
        Schema::table('materials', function (Blueprint $t) {
            $t->string('youtube_video_id', 11)->nullable();
            $t->text('video_caption')->nullable();
            $t->string('audio_url', 2048)->nullable();
        });
        Schema::table('course_lessons', function (Blueprint $t) {
            $t->string('completion_rule', 20)->default('manual');
            $t->decimal('minimum_score', 5, 2)->nullable();
        });
        Schema::create('learning_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->string('target_type', 15);
            $t->string('target_key', 50);
            $t->string('status', 15)->default('started');
            $t->unsignedInteger('content_version');
            $t->json('criteria_snapshot')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('first_opened_at')->useCurrent();
            $t->timestamp('last_opened_at')->useCurrent();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'target_type', 'target_key'], 'progress_student_target_unique');
        });
        Schema::create('learning_progress_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('learning_progress_id')->constrained('learning_progress')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
        Schema::table('assignments', function (Blueprint $t) {
            $t->boolean('allow_late')->default(true);
            $t->boolean('allow_revisions')->default(true);
            $t->boolean('lock_after_review')->default(false);
            $t->unsignedTinyInteger('max_submissions')->nullable();
        });
        Schema::table('assignment_submissions', fn (Blueprint $t) => $t->unsignedInteger('submission_count')->default(0));
        DB::table('assignment_submissions')->where('status', '!=', 'draft')->update(['submission_count' => 1]);
        Schema::create('assignment_reopenings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $t->string('owner_key', 60);
            $t->timestamp('valid_until');
            $t->unsignedInteger('allowed_total');
            $t->text('reason');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['assignment_id', 'owner_key']);
        });
        Schema::table('group_works', fn (Blueprint $t) => $t->json('member_ids')->nullable());
        DB::table('group_works')->orderBy('id')->chunkById(100, function ($works): void {
            foreach ($works as $work) {
                $last = DB::table('group_work_revisions')->where('group_work_id', $work->id)->orderByDesc('version')->value('snapshot');
                $snapshot = $last ? json_decode($last, true) : [];
                $ids = $snapshot['member_ids'] ?? DB::table('learning_group_user')->where('learning_group_id', $work->learning_group_id)->pluck('user_id')->all();
                DB::table('group_works')->where('id', $work->id)->update(['member_ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);
            }
        });
        Schema::create('learning_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $t->string('kind', 50);
            $t->string('title', 180);
            $t->text('body');
            $t->string('target_path', 300);
            $t->string('dedupe_key', 180);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['recipient_id', 'dedupe_key'], 'notification_recipient_event_unique');
        });
        Schema::create('learning_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);
            $t->string('subject_type', 60);
            $t->string('subject_id', 60);
            $t->json('data');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['learning_audits', 'learning_notifications', 'assignment_reopenings', 'learning_progress_events', 'learning_progress', 'module_class_media'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('group_works', fn (Blueprint $t) => $t->dropColumn('member_ids'));
        Schema::table('assignment_submissions', fn (Blueprint $t) => $t->dropColumn('submission_count'));
        Schema::table('assignments', fn (Blueprint $t) => $t->dropColumn(['allow_late', 'allow_revisions', 'lock_after_review', 'max_submissions']));
        Schema::table('course_lessons', fn (Blueprint $t) => $t->dropColumn(['completion_rule', 'minimum_score']));
        Schema::table('materials', fn (Blueprint $t) => $t->dropColumn(['youtube_video_id', 'video_caption', 'audio_url']));
        Schema::table('learning_modules', fn (Blueprint $t) => $t->dropColumn(['youtube_video_id', 'video_caption']));
        Schema::table('learning_group_user', fn (Blueprint $t) => $t->dropColumn('is_active'));
        Schema::table('learning_groups', fn (Blueprint $t) => $t->dropColumn(['version', 'archived_at']));
        Schema::table('classroom_user', fn (Blueprint $t) => $t->dropColumn('is_active'));
        Schema::table('classrooms', fn (Blueprint $t) => $t->dropColumn(['version', 'archived_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['must_change_password', 'profile_version']));
    }
};
