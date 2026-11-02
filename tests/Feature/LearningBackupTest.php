<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class LearningBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_verification_detects_tampering_and_never_executes_sql_without_apply(): void
    {
        Storage::fake('local');
        $sql = 'DROP TABLE users;';
        $path = Storage::disk('local')->path('valid.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('database.sql', $sql);
        $zip->addFromString('manifest.json', json_encode(['format' => 'svil-backup-v1', 'entries' => ['database.sql' => hash('sha256', $sql)]]));
        $zip->close();
        $count = DB::table('users')->count();
        $this->artisan('svil:restore-backup', ['archive' => $path])->assertSuccessful();
        $this->assertSame($count, DB::table('users')->count());
        $zip->open($path);
        $zip->addFromString('database.sql', 'Changed SQL');
        $zip->close();
        $this->artisan('svil:restore-backup', ['archive' => $path])->assertFailed();
        $this->assertSame($count, DB::table('users')->count());
    }

    public function test_restore_requires_explicit_target_database_and_rejects_traversal_paths(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('unsafe.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'SELECT 1;');
        $zip->addFromString('files/../../.env', 'changed');
        $zip->addFromString('manifest.json', json_encode(['format' => 'svil-backup-v1', 'entries' => ['database.sql' => hash('sha256', 'SELECT 1;'), 'files/../../.env' => hash('sha256', 'changed')]]));
        $zip->close();
        $this->artisan('svil:restore-backup', ['archive' => $path, '--apply' => true, '--confirm-database' => 'svil_app_testing'])->assertFailed();
        $zip->open($path);
        $zip->addFromString('manifest.json', json_encode(['format' => 'svil-backup-v1', 'entries' => ['database.sql' => hash('sha256', 'SELECT 1;')]]));
        $zip->close();
        $this->artisan('svil:restore-backup', ['archive' => $path, '--apply' => true, '--confirm-database' => 'other_database'])->assertFailed();
    }
}
