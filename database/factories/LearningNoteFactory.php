<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\LearningNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearningNote> */
class LearningNoteFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'student_id' => User::factory(), 'topic' => 0, 'phase' => 0, 'content' => fake()->paragraph()];
    }
}
