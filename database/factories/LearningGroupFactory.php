<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\LearningGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearningGroup> */
class LearningGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'name' => fake()->unique()->word()];
    }
}
