<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialAttempt> */
class MaterialAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['material_id' => Material::factory(), 'student_id' => User::factory(), 'request_id' => fake()->uuid(), 'material_version' => 1, 'answer' => ['choice' => 0], 'question_snapshot' => ['type' => 'choice', 'correctIndex' => 0], 'correct' => true];
    }
}
