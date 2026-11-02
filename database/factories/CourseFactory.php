<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'subject' => 'IPAS', 'title' => 'Pembelajaran IPAS', 'semester' => 'genap', 'method' => 'standard', 'status' => 'published', 'created_by' => User::factory()->state(['role' => Role::Teacher]), 'updated_by' => User::factory()->state(['role' => Role::Teacher])];
    }
}
