<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('topic');
            $table->unsignedTinyInteger('occasion');
            $table->date('observed_on');
            $table->json('scores');
            $table->text('evidence');
            $table->text('feedback');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['classroom_id', 'student_id', 'topic', 'occasion'], 'observation_activity_unique');
        });
        Schema::create('observation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['observation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_revisions');
        Schema::dropIfExists('observations');
    }
};
