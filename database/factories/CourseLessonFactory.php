<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseLesson> */
class CourseLessonFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'course_chapter_id' => CourseChapter::factory(), 'title' => 'Pertemuan belajar', 'objective' => 'Menjelaskan fakta berdasarkan bukti.', 'position' => 1, 'status' => 'open', 'mode' => 'blended'];
    }
}
