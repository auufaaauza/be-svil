<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssessmentAttempt> */
class AssessmentAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['assessment_id' => Assessment::factory(), 'student_id' => User::factory(), 'attempt_number' => 1, 'assessment_snapshot' => ['title' => 'Asesmen', 'version' => 1, 'questions' => []], 'recorded_by' => User::factory(), 'started_at' => now(), 'max_score' => 10];
    }
}
