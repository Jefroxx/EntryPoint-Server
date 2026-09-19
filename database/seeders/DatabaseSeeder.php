<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LibrarianSeeder::class,
            StudentSeeder::class,
            CatalogSeeder::class,
            PenaltySeeder::class,
            LoanSeeder::class,
            ReservationSeeder::class,
            SelfReturnReportSeeder::class,
            ResourceSeeder::class,
            AchievementSeeder::class,
            AttendanceLogSeeder::class,
            MarketSeeder::class,
            BookSuggestionSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
