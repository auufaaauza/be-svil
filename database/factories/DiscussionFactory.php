<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Discussion;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discussion> */
class DiscussionFactory extends Factory
{
    public function definition(): array
    {
        return ['classroom_id' => Classroom::factory(), 'author_id' => User::factory()->state(['role' => Role::Teacher]), 'title' => 'Diskusi kepedulian', 'content' => 'Bagaimana kita saling membantu?', 'status' => 'open', 'version' => 1];
    }
}
