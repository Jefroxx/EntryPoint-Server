<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuthorFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->name();
        $surname = Str::of($name)->afterLast(' ')->upper();

        return [
            'uuid'         => Str::uuid(),
            'name'         => $name,
            'cutterNumber' => $surname[0] . $this->faker->unique()->numberBetween(100, 999),
        ];
    }
}
