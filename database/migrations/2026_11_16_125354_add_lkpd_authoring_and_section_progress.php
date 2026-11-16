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
        Schema::table('learning_modules', function (Blueprint $table) {
            $table->unsignedInteger('topic')->change();
            $table->foreignId('classroom_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('create_request_id')->nullable()->unique();
            $table->unsignedTinyInteger('page_size')->default(4);
        });
        Schema::table('group_works', function (Blueprint $table) {
            $table->unsignedInteger('topic')->change();
            $table->unsignedTinyInteger('page_size')->default(4);
            $table->decimal('score', 5, 2)->nullable();
        });
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('active_lkpd_id')->nullable()->constrained('learning_modules')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_lkpd_id');
        });
        Schema::table('group_works', function (Blueprint $table) {
            $table->dropColumn(['page_size', 'score']);
        });
        Schema::table('learning_modules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropColumn(['create_request_id', 'page_size']);
        });
    }
};
