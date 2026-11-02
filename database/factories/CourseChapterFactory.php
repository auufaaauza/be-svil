<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseChapter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseChapter> */
class CourseChapterFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'title' => 'Bab pembelajaran', 'position' => 1];
    }
}
