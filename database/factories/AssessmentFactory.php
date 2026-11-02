<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Classroom;
use App\Models\CourseLesson;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assessment> */
class AssessmentFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'course_lesson_id' => CourseLesson::factory(), 'created_by' => User::factory()->state(['role' => Role::Teacher]), 'title' => 'Asesmen pemahaman', 'instructions' => 'Jawab sesuai pemahamanmu.', 'status' => 'published', 'purpose' => 'practice', 'max_attempts' => 1, 'max_score' => 10, 'questions' => [['id' => 'q1', 'type' => 'choice', 'prompt' => 'Bagaimana menunjukkan peduli?', 'options' => ['Membantu', 'Mengejek', 'Merusak', 'Membiarkan'], 'correctIndex' => 0, 'points' => 10]]];
    }
}
