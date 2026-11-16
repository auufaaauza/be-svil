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
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        if (! Schema::hasTable('student_account_batches')) {
            Schema::create('student_account_batches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('classroom_id')->constrained()->restrictOnDelete();
                $table->char('payload_hash', 64);
                $table->json('accounts');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Email siswa masih kosong. Lengkapi email sebelum membatalkan migrasi ini.');
        }
        Schema::dropIfExists('student_account_batches');
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable(false)->change());
    }
};
