<?php

namespace Database\Factories;

use App\Models\Discussion;
use App\Models\DiscussionReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscussionReply> */
class DiscussionReplyFactory extends Factory
{
    public function definition(): array
    {
        return ['discussion_id' => Discussion::factory(), 'author_id' => User::factory(), 'request_id' => fake()->uuid(), 'content' => 'Mendengarkan teman dengan sabar.'];
    }
}
