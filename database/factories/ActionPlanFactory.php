<?php

namespace Database\Factories;

use App\Models\ActionPlan;
use App\Models\LearningGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActionPlan> */
class ActionPlanFactory extends Factory
{
    public function definition(): array
    {
        return ['learning_group_id' => LearningGroup::factory(), 'title' => fake()->sentence(3), 'start_date' => today(), 'location' => fake()->city(), 'indicator' => 'Bukti tercatat', 'steps' => 'Lakukan bersama', 'responsibilities' => 'Tugas dibagi', 'evidence_method' => 'Jurnal', 'version' => 1];
    }
}
