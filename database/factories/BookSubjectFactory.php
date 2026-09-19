<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookSubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'name' => $this->faker->unique()->randomElement([
                'Computer Science', 'Software Engineering', 'Mathematics', 'Physics',
                'Biology', 'Chemistry', 'Engineering', 'Business Management',
                'Economics', 'Education', 'Philosophy', 'Psychology',
                'Political Science', 'Sociology', 'Language & Linguistics',
                'Literature', 'Philippine History', 'World History', 'Fine Arts',
                'Nursing', 'Agriculture', 'Law', 'Religion & Theology',
            ]),
            'classificationCode' => null,
        ];
    }
}
