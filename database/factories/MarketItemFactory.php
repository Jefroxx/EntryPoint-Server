<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MarketItemFactory extends Factory
{
    public function definition(): array
    {
        [$name, $type, $cost] = $this->faker->randomElement([
            ['Ballpen Pack', 'supply', 5],
            ['Notebook', 'supply', 8],
            ['Highlighter Set', 'supply', 12],
            ['USB Flash Drive (16GB)', 'gadget', 40],
            ['Library Tote Bag', 'merchandise', 25],
            ['Library T-Shirt', 'merchandise', 60],
            ['Sticky Notes', 'supply', 6],
            ['Bookmark Set', 'merchandise', 10],
            ['Earphones', 'gadget', 35],
            ['Coffee Mug', 'merchandise', 20],
        ]);

        return [
            'uuid'      => Str::uuid(),
            'name'      => $name,
            'type'      => $type,
            'pointCost' => $cost,
            'stock'     => $this->faker->numberBetween(5, 40),
        ];
    }
}
