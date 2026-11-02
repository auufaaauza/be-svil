<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class RestoreLearningBackup extends Command
{
    protected $signature = 'svil:restore-backup {archive : Path ZIP backup privat} {--apply : Jalankan pemulihan setelah verifikasi} {--confirm-database= : Nama database tujuan harus persis sama} {--database-only : Pulihkan SQL tanpa menulis storage} {--mysql-binary=mysql : Lokasi executable mysql}';

    protected $description = 'Verify a learning backup; restore only with an explicit database name and maintenance mode';

    public function handle(): int
    {
        $zip = new ZipArchive;
        $opened = false;
        $disk = Storage::disk('local');
        $temporary = 'backups/.restore-'.Str::uuid();
        $config = $disk->path($temporary.'/client.cnf');
        $sql = $disk->path($temporary.'/database.sql');
        try {
            if ($zip->open($this->argument('archive')) !== true) {
                throw new RuntimeException('Arsip backup tidak dapat dibaca.');
            }
            $opened = true;
            if ($zip->numFiles > 20000) {
                throw new RuntimeException('Arsip berisi terlalu banyak berkas.');
            }$meta = $zip->statName('manifest.json');
            if (! $meta || $meta['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Manifest backup tidak valid.');
            }
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'svil-backup-v1' || ! isset($manifest['entries']['database.sql'])) {
                throw new RuntimeException('Format backup tidak didukung.');
            }
            foreach ($manifest['entries'] as $name => $hash) {
                if (! is_string($name) || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0") || str_contains($name, ':') || ($name !== 'database.sql' && ! str_starts_with($name, 'files/')) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new RuntimeException('Nama berkas backup tidak aman.');
                }
                if (str_starts_with($name, 'files/')) {
                    $relative = substr($name, 6);
                    if ($relative === '' || str_starts_with($relative, '/') || str_starts_with($relative, 'backups/')) {
                        throw new RuntimeException('Path storage pada backup tidak valid.');
                    }
                }
                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException('Berkas backup tidak lengkap.');
                }$ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                if (! hash_equals($hash, hash_final($ctx))) {
                    throw new RuntimeException('Checksum berkas backup tidak cocok: '.$name);
                }
            }
            if (! $this->option('apply')) {
                $this->info('Backup valid. '.count($manifest['entries']).' berkas lolos verifikasi checksum. Tidak ada data yang diubah.');

                return self::SUCCESS;
            }
            if ($this->option('confirm-database') !== DB::connection()->getDatabaseName()) {
                throw new RuntimeException('Isi --confirm-database dengan nama database tujuan yang tepat.');
            }if (! $this->laravel->isDownForMaintenance() && ! $this->laravel->environment('testing')) {
                throw new RuntimeException('Aktifkan maintenance mode sebelum restore.');
            }
            $disk->put($temporary.'/client.cnf', BackupLearningData::clientConfig());
            $input = $zip->getStream('database.sql');
            $out = fopen($sql, 'wb');
            stream_copy_to_stream($input, $out);
            fclose($input);
            fclose($out);
            $process = new Process([$this->option('mysql-binary'), '--defaults-extra-file='.$config, '--default-character-set=utf8mb4', DB::connection()->getDatabaseName()]);
            $sqlInput = fopen($sql, 'rb');
            $process->setInput($sqlInput);
            $process->setTimeout(300);
            $process->run();
            if (is_resource($sqlInput)) {
                fclose($sqlInput);
            }if (! $process->isSuccessful()) {
                throw new RuntimeException('Pemulihan SQL gagal. Tetap dalam maintenance mode dan periksa akses MySQL.');
            }
            if (! $this->option('database-only')) {
                foreach ($manifest['entries'] as $name => $hash) {
                    if (! str_starts_with($name, 'files/')) {
                        continue;
                    }$relative = substr($name, 6);
                    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':') || str_starts_with($relative, 'backups/')) {
                        throw new RuntimeException('Path storage pada backup tidak valid.');
                    }$stream = $zip->getStream($name);
                    $disk->put($relative, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            $this->info('Pemulihan selesai. Periksa sesi, data dan berkas sebelum menonaktifkan maintenance mode.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Verifikasi/pemulihan gagal: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($opened) {
                $zip->close();
            }foreach ([$config, $sql] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }if (is_dir($disk->path($temporary))) {
                rmdir($disk->path($temporary));
            }
        }
    }
}
