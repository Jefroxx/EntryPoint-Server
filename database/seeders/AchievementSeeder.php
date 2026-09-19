<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['First Visit', 'visitStreak', 1, 10],
            ['Regular Reader', 'visitStreak', 7, 20],
            ['Dedicated Scholar', 'visitStreak', 30, 50],
            ['Point Collector', 'knowledgeScore', 50, 15],
            ['Point Master', 'knowledgeScore', 200, 30],
            ['Knowledge Seeker', 'knowledgeScore', 500, 75],
        ];

        $achievements = collect($definitions)->map(fn ($d) => Achievement::create([
            'uuid'         => Str::uuid(),
            'name'         => $d[0],
            'criteriaJSON' => ['metric' => $d[1], 'threshold' => $d[2]],
            'pointsReward' => $d[3],
        ]));

        $students = Student::where('registrationStatus', 'approved')->get();

        foreach ($students as $student) {
            foreach ($achievements as $achievement) {
                $metric = $achievement->criteriaJSON['metric'];
                $threshold = $achievement->criteriaJSON['threshold'];

                if ($student->{$metric} >= $threshold) {
                    $redeemed = random_int(1, 100) <= 50;

                    $student->achievements()->attach($achievement->achievementID, [
                        'uuid'         => Str::uuid(),
                        'triggerEvent' => $metric,
                        'earnedAt'     => now()->subDays(random_int(1, 20)),
                        'redeemedAt'   => $redeemed ? now()->subDays(random_int(0, 10)) : null,
                    ]);
                }
            }
        }
    }
}
