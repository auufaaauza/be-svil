<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\CourseLesson;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'course_lesson_id' => CourseLesson::factory(), 'created_by' => User::factory()->state(['role' => Role::Teacher]), 'title' => 'Karya kelompok', 'instructions' => 'Tuliskan hasil pengamatan.', 'kind' => 'task', 'submission_mode' => 'individual', 'status' => 'published', 'max_score' => 100];
    }
}
