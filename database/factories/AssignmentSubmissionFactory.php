<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssignmentSubmission> */
class AssignmentSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_id' => Assignment::factory(), 'owner_key' => 'student:'.fake()->unique()->numberBetween(1000, 999999), 'student_id' => User::factory(), 'member_ids' => [], 'content' => 'Hasil pengamatan nyata.', 'recorded_by' => User::factory(), 'assignment_snapshot' => ['title' => 'Tugas', 'max_score' => 100, 'version' => 1, 'kind' => 'task'], 'submitted_at' => now()];
    }
}
