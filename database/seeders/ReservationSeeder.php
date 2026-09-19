<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Reservation;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        $students = Student::where('registrationStatus', 'approved')->get();
        $books = Book::inRandomOrder()->limit(20)->get();

        if ($students->isEmpty() || $books->isEmpty()) {
            return;
        }

        $statuses = ['Waiting', 'Waiting', 'Accepted', 'Rejected', 'Fulfilled'];

        foreach ($books as $book) {
            if (random_int(1, 100) > 60) {
                continue; // not every book has a reservation
            }

            Reservation::create([
                'uuid'       => Str::uuid(),
                'studentID'  => $students->random()->studentID,
                'bookID'     => $book->bookID,
                'status'     => $statuses[array_rand($statuses)],
                'reservedAt' => Carbon::now()->subDays(random_int(0, 20)),
            ]);
        }
    }
}
