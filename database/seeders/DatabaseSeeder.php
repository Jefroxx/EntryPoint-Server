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
            // Loans, fines, reservations and self-return reports, each state once (LoanSeeder explains who has what).
            LoanSeeder::class,
            ResourceSeeder::class,
            AttendanceLogSeeder::class,
            MarketSeeder::class,
            BookSuggestionSeeder::class,
            // After loans, visits, suggestions and redemptions exist: achievements are measured from them.
            AchievementSeeder::class,
            NotificationSeeder::class,
            AnnouncementSeeder::class,
        ]);
    }
}
