<?php

namespace Database\Seeders;

use App\Models\MarketCartItem;
use App\Models\MarketItem;
use App\Models\PointRedemption;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MarketSeeder extends Seeder
{
    public function run(): void
    {
        $items = MarketItem::factory()->count(8)->create();
        $students = Student::where('registrationStatus', 'approved')->where('knowledgeScore', '>', 0)->get();

        if ($students->isEmpty()) {
            return;
        }

        // A couple of active cart lines.
        foreach ($students->random(min(3, $students->count()))->all() as $student) {
            MarketCartItem::create([
                'uuid'      => Str::uuid(),
                'studentID' => $student->studentID,
                'itemID'    => $items->random()->itemID,
                'quantity'  => random_int(1, 2),
            ]);
        }

        // A handful of redemption history rows in varied states.
        $statuses = ['Pending', 'Fulfilled', 'Fulfilled', 'Cancelled'];

        foreach ($students->random(min(6, $students->count()))->all() as $index => $student) {
            $item = $items->random();
            $quantity = random_int(1, 2);

            PointRedemption::create([
                'uuid'              => Str::uuid(),
                'studentID'         => $student->studentID,
                'itemID'            => $item->itemID,
                'quantity'          => $quantity,
                'pointsSpent'       => $item->pointCost * $quantity,
                'fulfillmentStatus' => $statuses[$index % count($statuses)],
                'redeemedAt'        => now()->subDays(random_int(0, 15)),
            ]);
        }
    }
}
