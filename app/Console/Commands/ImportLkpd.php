<?php

namespace App\Console\Commands;

use App\Learning\LkpdSchema;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLkpd extends Command
{
    protected $signature = 'svil:import-lkpd {--by= : Email admin yang mengimpor konten resmi} {--upgrade : Perbarui hanya skema ringkas bawaan yang belum disunting}';

    protected $description = 'Import four real LKPD documents without creating demo users, classes, or scores';

    public function handle(): int
    {
        $admin = User::where('email', $this->option('by'))->where('role', Role::Admin)->first();
        if (! $admin) {
            $this->error('Tentukan email admin yang ada melalui --by.');

            return self::FAILURE;
        }
        $documents = json_decode(file_get_contents(database_path('data/lkpd.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($documents, $admin) {
            foreach ($documents as $topic => $document) {
                if ($existing = LearningModule::where('topic', $topic)->lockForUpdate()->first()) {
                    if ($this->option('upgrade') && $existing->fields == LkpdSchema::legacyFields($topic)) {
                        $existing->update(['fields' => LkpdSchema::fields($topic), 'version' => $existing->version + 1, 'updated_by' => $admin->id]);
                        $existing->recordRevision();
                        $this->info('LKPD '.($topic + 1).' diperluas; jawaban lama mempertahankan snapshot.');

                        continue;
                    }
                    $this->line('LKPD '.($topic + 1).' sudah ada; tidak ditimpa.');

                    continue;
                }
                $module = LearningModule::create([...$document, 'topic' => $topic, 'fields' => LkpdSchema::fields($topic), 'source_hash' => hash('sha256', $document['source_text']), 'status' => 'published', 'version' => 1, 'uploaded_by' => $admin->id, 'updated_by' => $admin->id]);
                $module->recordRevision();
                $this->info('LKPD '.($topic + 1).' diimpor: '.$module->title);
            }
        });

        return self::SUCCESS;
    }
}
