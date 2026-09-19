<?php

namespace Database\Seeders;

use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\Student;
use Illuminate\Database\Seeder;

class BookSuggestionSeeder extends Seeder
{
    public function run(): void
    {
        $students = Student::where('registrationStatus', 'approved')->get();
        $librarianID = Librarian::first()?->librarianID;

        if ($students->isEmpty()) {
            return;
        }

        BookSuggestion::factory()->count(10)->create([
            'studentID'             => fn () => $students->random()->studentID,
            'reviewedByLibrarianID' => fn (array $attrs) => $attrs['status'] === 'Pending' ? null : $librarianID,
        ]);
    }
}
