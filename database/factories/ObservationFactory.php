<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Observation;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Observation> */
class ObservationFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'student_id' => User::factory(), 'teacher_id' => User::factory()->state(['role' => Role::Teacher]), 'topic' => 0, 'occasion' => 1, 'observed_on' => today(), 'scores' => ['participation' => 3, 'empathy' => 3, 'honesty' => 3, 'responsibility' => 3], 'evidence' => 'Bergantian saat berdiskusi.', 'feedback' => 'Lanjutkan kebiasaan baikmu.', 'version' => 1];
    }
}
