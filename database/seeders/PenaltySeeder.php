<?php

namespace Database\Seeders;

use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PenaltySeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            'circulation' => ['rate' => 5.00, 'rateUnit' => 'day', 'gracePeriodDays' => 1],
            'reserved'    => ['rate' => 10.00, 'rateUnit' => 'hour', 'gracePeriodDays' => 0],
            'filipiniana' => ['rate' => 5.00, 'rateUnit' => 'day', 'gracePeriodDays' => 2],
        ];

        foreach ($rules as $category => $rule) {
            $type = PenaltyType::create(['uuid' => Str::uuid(), 'category' => $category]);

            PenaltyRule::create([
                'uuid'            => Str::uuid(),
                'penaltyTypeID'   => $type->penaltyTypeID,
                'rate'            => $rule['rate'],
                'rateUnit'        => $rule['rateUnit'],
                'gracePeriodDays' => $rule['gracePeriodDays'],
            ]);
        }
    }
}
