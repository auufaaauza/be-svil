<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\QuestionBankItem;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuestionBankItem> */
class QuestionBankItemFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'created_by' => User::factory()->state(['role' => Role::Teacher]), 'subject' => 'IPAS', 'question' => ['id' => 'q1', 'type' => 'text', 'prompt' => 'Jelaskan dengan bukti.', 'options' => [], 'points' => 10]];
    }
}
