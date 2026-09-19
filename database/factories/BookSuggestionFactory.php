<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookSuggestionFactory extends Factory
{
    public function definition(): array
    {
        $status = $this->faker->randomElement(['Pending', 'Pending', 'Approved', 'Rejected']);

        return [
            'uuid'                  => Str::uuid(),
            'studentID'             => null,
            'reviewedByLibrarianID' => $status === 'Pending' ? null : null,
            'title'                 => 'Suggested: ' . ucfirst($this->faker->words(3, true)),
            'author'                => $this->faker->boolean(70) ? $this->faker->name() : null,
            'reason'                => $this->faker->boolean(60) ? $this->faker->sentence(10) : null,
            'status'                => $status,
            'progressStep'          => match ($status) {
                'Approved' => 'Approved',
                'Rejected' => 'Rejected',
                default    => 'Submitted',
            },
            'submittedAt'           => $this->faker->dateTimeBetween('-3 months', 'now'),
        ];
    }
}
