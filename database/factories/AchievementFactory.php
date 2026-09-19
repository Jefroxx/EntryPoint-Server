<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AchievementFactory extends Factory
{
    public function definition(): array
    {
        [$name, $metric, $threshold] = $this->faker->randomElement([
            ['First Visit', 'visitStreak', 1],
            ['Regular Reader', 'visitStreak', 7],
            ['Dedicated Scholar', 'visitStreak', 30],
            ['Point Collector', 'knowledgeScore', 50],
            ['Point Master', 'knowledgeScore', 200],
            ['Knowledge Seeker', 'knowledgeScore', 500],
        ]);

        return [
            'uuid'         => Str::uuid(),
            'name'         => $name,
            'criteriaJSON' => ['metric' => $metric, 'threshold' => $threshold],
            'pointsReward' => $this->faker->randomElement([10, 15, 20, 25, 50]),
        ];
    }
}
