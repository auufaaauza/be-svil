<?php

namespace Database\Factories;

use App\Learning\LkpdSchema;
use App\Models\LearningModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearningModule> */
class LearningModuleFactory extends Factory
{
    public function definition(): array
    {
        return ['topic' => 0, 'title' => 'Peta dan Laut sebagai Penghubung', 'description' => 'Kenali wilayah Indonesia dan rawat lautnya.', 'instructions' => 'Diskusikan dan catat sumber.', 'fields' => LkpdSchema::legacyFields(0), 'source_text' => 'Dokumen LKPD pengujian.', 'source_document' => 'LKPD-1.docx', 'source_hash' => hash('sha256', 'Dokumen LKPD pengujian.'), 'status' => 'published', 'version' => 1, 'uploaded_by' => User::factory(), 'updated_by' => User::factory()];
    }
}
