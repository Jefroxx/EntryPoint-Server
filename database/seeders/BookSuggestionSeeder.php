<?php

namespace Database\Seeders;

use App\Models\BookSuggestion;
use App\Models\Librarian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Book requests from students, one in every state: waiting, approved, arrived in the library, and turned down. */
class BookSuggestionSeeder extends Seeder
{
    public function run(): void
    {
        $librarianID = Librarian::where('role', 'librarian')->value('librarianID');

        $requests = [
            ['hannah', 'The Pragmatic Programmer', 'David Thomas and Andrew Hunt', 'Recommended in our Programming 2 class.', 'Pending', null, 2],
            ['enzo', 'Food and Beverage Service', 'Dennis R. Lillicrap', 'For our F&B laboratory subject.', 'Pending', null, 5],
            ['bea', 'Designing Data-Intensive Applications', 'Martin Kleppmann', 'For our capstone project.', 'Approved', null, 12],
            ['diane', 'Philippine Taxation', 'Rex Banggawan', 'Needed for Income Taxation.', 'Arrived', null, 30],
            ['carlo', 'Harry Potter Box Set', 'J. K. Rowling', null, 'Rejected', 'The library already has these titles in Fiction.', 18],
        ];

        foreach ($requests as [$key, $title, $author, $reason, $status, $rejection, $daysAgo]) {
            BookSuggestion::create([
                'uuid'                  => Str::uuid(),
                'studentID'             => StudentSeeder::find($key)->studentID,
                'reviewedByLibrarianID' => $status === 'Pending' ? null : $librarianID,
                'title'                 => $title,
                'author'                => $author,
                'reason'                => $reason,
                'status'                => $status,
                'progressStep'          => $status === 'Pending' ? 'Submitted' : $status,
                'rejectionReason'       => $rejection,
                'submittedAt'           => now()->subDays($daysAgo),
            ]);
        }
    }
}
