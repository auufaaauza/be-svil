<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupLearningData extends Command
{
    protected $signature = 'svil:backup {--dump-binary=mysqldump : Lokasi executable mysqldump}';

    protected $description = 'Back up MySQL and private learning files into a private checksum-verified archive';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $id = now()->format('Ymd-His').'-'.Str::uuid();
        $temporary = 'backups/.tmp-'.$id;
        $sql = $disk->path($temporary.'/database.sql');
        $config = $disk->path($temporary.'/client.cnf');
        $archive = $disk->path('backups/svil-'.$id.'.zip');
        $wasDown = $this->laravel->isDownForMaintenance();
        try {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('Ekstensi ZIP PHP belum tersedia.');
            }if (config('database.default') !== 'mysql') {
                throw new RuntimeException('Backup ini memerlukan koneksi MySQL.');
            }
            if (! $wasDown) {
                $this->callSilent('down');
            }
            $disk->put($temporary.'/client.cnf', self::clientConfig());
            $process = new Process([$this->option('dump-binary'), '--defaults-extra-file='.$config, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', '--result-file='.$sql, DB::connection()->getDatabaseName()]);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($sql) || filesize($sql) === 0) {
                throw new RuntimeException('Dump database belum berhasil. Periksa lokasi mysqldump dan akses database.');
            }
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Arsip backup belum dapat dibuat.');
            }$manifest = ['format' => 'svil-backup-v1', 'created_at' => now()->toIso8601String(), 'database' => DB::connection()->getDatabaseName(), 'app_key_fingerprint' => hash('sha256', (string) config('app.key')), 'entries' => []];
            $zip->addFile($sql, 'database.sql');
            $manifest['entries']['database.sql'] = hash_file('sha256', $sql);
            foreach ($disk->allFiles() as $file) {
                if (str_starts_with($file, 'backups/') || str_contains($file, '/.tmp')) {
                    continue;
                }$path = $disk->path($file);
                if (! is_file($path) || is_link($path)) {
                    continue;
                }$entry = 'files/'.$file;
                $zip->addFile($path, $entry);
                $manifest['entries'][$entry] = hash_file('sha256', $path);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('Arsip backup belum selesai ditulis.');
            }$this->info('Backup privat tersimpan: '.$archive);
            $this->line('SHA-256: '.hash_file('sha256', $archive));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if (is_file($archive)) {
                unlink($archive);
            }$this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            foreach ([$config, $sql] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }if (is_dir($disk->path($temporary))) {
                rmdir($disk->path($temporary));
            }if (! $wasDown) {
                $this->callSilent('up');
            }
        }
    }

    public static function clientConfig(): string
    {
        $db = config('database.connections.mysql');
        $quote = fn ($v) => '"'.str_replace(['\\', "\n", "\r", '"'], ['\\\\', '\\n', '\\r', '\\"'], (string) $v).'"';

        return "[client]\nhost=".$quote($db['host'])."\nport=".(int) $db['port']."\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\n";
    }
}
