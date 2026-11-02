<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 20)->default('student')->index();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('academic_year', 20);
            $table->string('join_code', 12)->unique();
            $table->timestamps();
            $table->unique(['school_id', 'name', 'academic_year']);
        });
        Schema::create('classroom_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['classroom_id', 'user_id']);
        });
        Schema::create('learning_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['classroom_id', 'name']);
        });
        Schema::create('learning_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['learning_group_id', 'user_id']);
        });
        Schema::create('materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 100);
            $table->text('content');
            $table->unsignedTinyInteger('topic')->nullable();
            $table->string('topic_label', 180)->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();
            $table->json('question')->nullable();
            $table->string('kind', 20)->default('material')->index();
            $table->string('subject', 80)->default('IPAS');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['classroom_id', 'status', 'topic']);
        });
        Schema::create('learning_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('topic');
            $table->unsignedTinyInteger('phase');
            $table->text('content');
            $table->text('feedback')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['classroom_id', 'student_id', 'topic', 'phase'], 'notes_student_activity_unique');
        });
        Schema::create('material_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->unsignedInteger('material_version');
            $table->json('answer');
            $table->json('question_snapshot');
            $table->boolean('correct')->nullable();
            $table->text('explanation')->nullable();
            $table->text('teacher_feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('review_version')->default(0);
            $table->timestamps();
            $table->unique(['student_id', 'request_id']);
        });
        Schema::create('attempt_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('correct')->nullable();
            $table->text('feedback');
            $table->timestamp('created_at');
            $table->unique(['material_attempt_id', 'version']);
        });
        Schema::create('individual_reflections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('topic');
            $t->json('answers');
            $t->json('schema_snapshot');
            $t->unsignedInteger('version')->default(1);
            $t->text('feedback')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['classroom_id', 'student_id', 'topic'], 'reflection_student_topic_unique');
        });
        Schema::create('reflection_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('individual_reflection_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['individual_reflection_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach (['reflection_revisions', 'individual_reflections', 'attempt_reviews', 'material_attempts', 'learning_notes', 'materials', 'learning_group_user', 'learning_groups', 'classroom_user', 'classrooms'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn(['role', 'is_active']);
        });
        Schema::dropIfExists('schools');
    }
};
