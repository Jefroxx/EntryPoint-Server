<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendanceLogFactory extends Factory
{
    public function definition(): array
    {
        $entry = $this->faker->dateTimeBetween('-60 days', '-1 hour');
        $exit = (clone $entry)->modify('+' . $this->faker->numberBetween(20, 180) . ' minutes');

        return [
            'uuid'      => Str::uuid(),
            'studentID' => null,
            'entryTime' => $entry,
            'exitTime'  => $exit,
        ];
    }

    public function stillCheckedIn(): static
    {
        return $this->state(fn () => ['exitTime' => null]);
    }
}
