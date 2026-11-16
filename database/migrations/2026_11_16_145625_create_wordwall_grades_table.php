<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wordwall_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('game_id', 40);
            $table->string('game_title', 180);
            $table->unsignedInteger('topic');
            $table->decimal('score', 5, 2);
            $table->text('feedback')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['classroom_id', 'student_id', 'game_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordwall_grades');
    }
};
