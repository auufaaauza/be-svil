<?php

namespace App\Console\Commands;

use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLkpd extends Command
{
    protected $signature = 'svil:import-lkpd {--by= : Email admin pengimpor} {--upgrade : Alias --updated} {--updated : Terapkan empat LKPD UPDATE sebagai versi baru}';

    protected $description = 'Import LKPD asli dengan snapshot jawaban dan riwayat versi tetap dipertahankan';

    public function handle(): int
    {
        $admin = User::where('email', $this->option('by'))->where('role', Role::Admin)->first();
        if (! $admin) {
            $this->error('Tentukan email admin yang ada melalui --by.');

            return self::FAILURE;
        }
        $documents = json_decode(file_get_contents(database_path('data/lkpd-update.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($documents, $admin): void {
            foreach ($documents as $topic => $document) {
                $attributes = collect($document)->only(['title', 'description', 'instructions', 'source_text', 'source_document'])->all();
                $attributes += ['fields' => $document['fields'], 'page_size' => 1, 'source_hash' => hash('sha256', $document['source_text']), 'status' => $document['status'], 'updated_by' => $admin->id];
                $existing = LearningModule::where('topic', $topic)->lockForUpdate()->first();
                if ($existing) {
                    if (($this->option('updated') || $this->option('upgrade')) && collect($attributes)->except('updated_by')->contains(fn ($value, $key) => $existing->getAttribute($key) != $value)) {
                        $existing->update([...$attributes, 'version' => $existing->version + 1]);
                        $existing->recordRevision();
                        $this->info('LKPD '.($topic + 1).' diperbarui dari LKPD UPDATE; snapshot jawaban dan penyesuaian kelas dipertahankan.');
                    } else {
                        $this->line('LKPD '.($topic + 1).' sudah ada; tidak ditimpa.');
                    }

                    continue;
                }
                $module = LearningModule::create([...$attributes, 'topic' => $topic, 'version' => 1, 'uploaded_by' => $admin->id]);
                $module->recordRevision();
                $this->info('LKPD '.($topic + 1).' diimpor: '.$module->title);
            }
        });

        return self::SUCCESS;
    }
}
