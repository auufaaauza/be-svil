<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->string('subject', 80);
            $t->string('title', 180);
            $t->string('semester', 10);
            $t->string('method', 20)->default('standard');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['classroom_id', 'status']);
        });
        Schema::create('course_chapters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->unsignedInteger('position')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('course_lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('course_chapter_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->text('objective');
            $t->unsignedInteger('position')->default(1);
            $t->date('scheduled_on')->nullable();
            $t->string('mode', 15)->default('blended');
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('materials', function (Blueprint $t) {
            $t->foreignId('course_lesson_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('course_lesson_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('instructions');
            $t->string('kind', 20)->default('task');
            $t->string('submission_mode', 15)->default('individual');
            $t->string('status', 20)->default('draft');
            $t->timestamp('due_at')->nullable();
            $t->decimal('max_score', 8, 2)->default(100);
            $t->json('rubric')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $t->string('owner_key', 60);
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('learning_group_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('member_ids');
            $t->longText('content');
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('file_mime')->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->string('source', 15)->default('online');
            $t->text('offline_evidence')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('submitted');
            $t->unsignedInteger('version')->default(1);
            $t->json('assignment_snapshot');
            $t->timestamp('submitted_at');
            $t->boolean('is_late')->default(false);
            $t->boolean('share_with_class')->default(false);
            $t->boolean('gallery_published')->default(false);
            $t->decimal('score', 8, 2)->nullable();
            $t->json('rubric_scores')->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['assignment_id', 'owner_key']);
        });
        Schema::create('submission_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_submission_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_id')->nullable();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['assignment_submission_id', 'version']);
            $t->unique(['actor_id', 'request_id']);
        });
        Schema::create('project_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_submission_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('appreciation');
            $t->text('question');
            $t->boolean('is_hidden')->default(false);
            $t->foreignId('moderated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['assignment_submission_id', 'author_id']);
        });
        Schema::create('question_bank_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('subject', 80);
            $t->string('category', 100)->nullable();
            $t->string('cognitive_level', 10)->nullable();
            $t->json('question');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('course_lesson_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('instructions');
            $t->string('purpose', 20)->default('practice');
            $t->string('status', 20)->default('draft');
            $t->json('questions');
            $t->decimal('max_score', 10, 2);
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->unsignedTinyInteger('max_attempts')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('assessment_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('attempt_number');
            $t->json('assessment_snapshot');
            $t->json('answers')->nullable();
            $t->json('item_scores')->nullable();
            $t->string('source', 15)->default('online');
            $t->text('offline_evidence')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('submit_request_id')->nullable();
            $t->string('status', 20)->default('in_progress');
            $t->decimal('raw_score', 10, 2)->nullable();
            $t->decimal('max_score', 10, 2);
            $t->text('feedback')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['assessment_id', 'student_id', 'attempt_number'], 'assessment_student_number_unique');
            $t->unique(['student_id', 'submit_request_id']);
        });
        Schema::create('assessment_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_attempt_id')->constrained()->restrictOnDelete();
            $t->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['assessment_attempt_id', 'version']);
        });
        Schema::create('lesson_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_lesson_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 15);
            $t->text('evidence');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['course_lesson_id', 'student_id']);
        });
        Schema::create('lesson_phase_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_lesson_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('phase');
            $t->string('status', 15);
            $t->text('evidence');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['course_lesson_id', 'phase']);
        });
        Schema::create('school_announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('content');
            $t->timestamps();
        });
        Schema::create('research_studies', function (Blueprint $t) {
            $t->id();
            $t->string('title', 180);
            $t->string('stage', 25);
            $t->string('status', 15)->default('draft');
            $t->text('protocol_reference');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('research_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->string('arm', 20);
            $t->timestamps();
            $t->unique(['research_study_id', 'student_id']);
        });
        Schema::create('research_instruments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->string('kind', 25);
            $t->string('status', 15)->default('draft');
            $t->text('approval_reference')->nullable();
            $t->json('items');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('previous_instrument_id')->nullable()->constrained('research_instruments')->restrictOnDelete();
            $t->string('target_role', 15)->default('both');
            $t->string('period', 15)->default('feedback');
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->timestamps();
        });
        Schema::create('research_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('research_instrument_id')->constrained()->restrictOnDelete();
            $t->foreignId('respondent_id')->constrained('users')->restrictOnDelete();
            $t->string('period', 15);
            $t->json('instrument_snapshot');
            $t->json('answers');
            $t->json('scores')->nullable();
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['research_instrument_id', 'respondent_id', 'period'], 'research_responses_period_unique');
            $t->unique(['respondent_id', 'request_id']);
        });
        Schema::create('research_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $t->string('kind', 25);
            $t->string('participant_code', 100)->nullable();
            $t->string('external_role', 100)->nullable();
            $t->text('source_reference');
            $t->longText('content');
            $t->json('data')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('research_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->string('period', 15);
            $t->text('approval_reference');
            $t->string('assessment_hash', 64);
            $t->timestamps();
            $t->unique(['research_study_id', 'assessment_id']);
        });
        Schema::create('research_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('file_path');
            $t->string('sha256', 64);
            $t->json('counts');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['research_exports', 'research_assessments', 'research_records', 'research_responses', 'research_instruments', 'research_enrollments', 'research_studies', 'school_announcements', 'lesson_phase_records', 'lesson_attendances', 'assessment_reviews', 'assessment_attempts', 'assessments', 'question_bank_items', 'project_comments', 'submission_revisions', 'assignment_submissions', 'assignments'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('materials', function (Blueprint $t) {
            $t->dropConstrainedForeignId('course_lesson_id');
        });
        Schema::dropIfExists('course_lessons');
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('courses');
    }
};
