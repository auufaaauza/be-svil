<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_group_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('topic');
            $table->json('answers');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->text('feedback')->nullable();
            $table->json('product_scores')->nullable();
            $table->json('presentation_scores')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('schema_snapshot')->nullable();
            $table->unsignedInteger('module_version')->nullable();
            $table->timestamps();
            $table->unique(['learning_group_id', 'topic']);
        });
        Schema::create('group_work_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_work_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['group_work_id', 'version']);
        });
        Schema::create('action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_group_id')->unique()->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->date('start_date');
            $table->string('location', 200);
            $table->text('indicator');
            $table->text('steps');
            $table->text('responsibilities');
            $table->text('evidence_method');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('teacher_feedback')->nullable();
            $table->timestamps();
        });
        Schema::create('action_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->date('occurred_on');
            $table->text('activity');
            $table->unsignedInteger('plan_version');
            $table->json('plan_snapshot');
            $table->text('evidence');
            $table->text('obstacle')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'request_id']);
        });
        Schema::create('action_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('action_plan_id')->constrained()->restrictOnDelete();
            $t->string('kind', 25);
            $t->date('occurred_on');
            $t->text('content');
            $t->text('source_reference');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['action_followups', 'action_journals', 'action_plans', 'group_work_revisions', 'group_works'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
