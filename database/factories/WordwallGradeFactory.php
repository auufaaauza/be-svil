<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\User;
use App\Models\WordwallGrade;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordwallGrade>
 */
class WordwallGradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'student_id' => User::factory(), 'teacher_id' => User::factory()->state(['role' => Role::Teacher]), 'game_id' => 'wordwall-123874298', 'game_title' => 'LKPD Zamrud Khatulistiwa – IPAS Kelas V', 'topic' => 1, 'score' => 80, 'feedback' => 'Nilai berdasarkan hasil Wordwall yang diperiksa guru.', 'version' => 1];
    }
}
