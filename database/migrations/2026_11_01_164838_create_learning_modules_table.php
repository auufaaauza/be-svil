<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('topic')->unique();
            $table->string('title');
            $table->text('description');
            $table->text('instructions');
            $table->json('fields');
            $table->longText('source_text');
            $table->string('source_document');
            $table->string('source_hash', 64);
            $table->string('status')->default('published');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('learning_module_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_module_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['learning_module_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_module_revisions');
        Schema::dropIfExists('learning_modules');
    }
};
