<?php

namespace Database\Factories;

use App\Models\ResearchInstrument;
use App\Models\ResearchStudy;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResearchInstrument> */
class ResearchInstrumentFactory extends Factory
{
    public function definition(): array
    {
        return ['research_study_id' => ResearchStudy::factory(), 'title' => 'Instrumen pengujian', 'kind' => 'practicality', 'status' => 'draft', 'items' => [['id' => 'i1', 'label' => 'Mudah dipahami', 'type' => 'scale', 'min' => 1, 'max' => 4, 'dimension' => 'kemudahan', 'reverse' => false]], 'created_by' => User::factory()->state(['role' => Role::Admin])];
    }
}
