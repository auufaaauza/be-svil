<?php

namespace Database\Factories;

use App\Models\ResearchStudy;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResearchStudy> */
class ResearchStudyFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'Studi terjadwal', 'stage' => 'limited', 'status' => 'draft', 'protocol_reference' => 'Dokumen protokol pengujian.', 'created_by' => User::factory()->state(['role' => Role::Admin])];
    }
}
