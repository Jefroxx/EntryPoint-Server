<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ResourceFactory extends Factory
{
    public function definition(): array
    {
        $type = $this->faker->randomElement(['computer', 'study room', 'projector', 'tablet']);
        $number = $this->faker->unique()->numberBetween(1, 30);

        return [
            'uuid'         => Str::uuid(),
            'resourceType' => $type,
            'name'         => ucfirst($type) . ' ' . $number,
            'status'       => 'Available',
        ];
    }
}
