<?php

namespace Database\Seeders;

use App\Models\Librarian;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $librarianID = Librarian::first()?->librarianID;

        // Mostly approved (so there's plenty of data to exercise loans,
        // reservations, achievements, etc. against), plus a handful pending
        // and rejected so the Students page filters have something to show.
        Student::factory()
            ->count(35)
            ->approved()
            ->create([
                'reviewedByLibrarianID' => $librarianID,
                'knowledgeScore'        => fn () => random_int(0, 300),
                'visitStreak'           => fn () => random_int(0, 14),
            ]);

        Student::factory()->count(6)->create(); // pending
        Student::factory()->count(4)->rejected()->create(['reviewedByLibrarianID' => $librarianID]);
    }
}
