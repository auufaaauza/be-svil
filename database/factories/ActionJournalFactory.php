<?php

namespace Database\Factories;

use App\Models\ActionJournal;
use App\Models\ActionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActionJournal> */
class ActionJournalFactory extends Factory
{
    public function definition(): array
    {
        return ['action_plan_id' => ActionPlan::factory(), 'student_id' => User::factory(), 'request_id' => fake()->uuid(), 'occurred_on' => today(), 'activity' => fake()->sentence(), 'evidence' => fake()->sentence(), 'plan_version' => 1, 'plan_snapshot' => []];
    }
}
