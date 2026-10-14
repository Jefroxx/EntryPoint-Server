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
            MemberSeeder::class,
            CatalogSeeder::class,
            PenaltySeeder::class,
            LoanSeeder::class,
            ReservationSeeder::class,
            SelfReturnReportSeeder::class,
            ResourceSeeder::class,
            AttendanceLogSeeder::class,
            MarketSeeder::class,
            BookSuggestionSeeder::class,
            // After loans, visits, suggestions and redemptions exist: achievements are measured from them.
            AchievementSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
