<?php

namespace Database\Factories;

use App\Models\GroupWork;
use App\Models\LearningGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupWork> */
class GroupWorkFactory extends Factory
{
    public function definition(): array
    {
        return ['learning_group_id' => LearningGroup::factory(), 'topic' => 0, 'answers' => [], 'status' => 'draft', 'version' => 1];
    }
}
