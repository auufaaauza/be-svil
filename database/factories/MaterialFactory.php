<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Material;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Material> */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'teacher_id' => User::factory()->state(['role' => Role::Teacher]), 'title' => fake()->sentence(3), 'content' => fake()->paragraph(), 'topic' => 0, 'status' => 'draft', 'version' => 1];
    }
}
