<?php

namespace Database\Seeders;

use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BookSuggestionSeeder extends Seeder
{
    public function run(): void
    {
        if (BookSuggestion::count() > 0) {
            return;
        }

        $librarian = Librarian::first();

        $students = collect([
            ['firstName' => 'Matt Oliver', 'lastName' => 'Pojadas', 'program' => 'BSIT4A'],
            ['firstName' => 'Anna Marie', 'lastName' => 'Cruz', 'program' => 'BSCS3B'],
            ['firstName' => 'John Paul', 'lastName' => 'Ibarra', 'program' => 'BSIT2A'],
            ['firstName' => 'Kristine Mae', 'lastName' => 'Villanueva', 'program' => 'BSBA1C'],
            ['firstName' => 'Mark Anthony', 'lastName' => 'Reyes', 'program' => 'BSIT3A'],
        ])->map(function (array $data) {
            $email = Str::slug($data['firstName'] . ' ' . $data['lastName']) . '@stidavao.edu.ph';

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'uuid'          => Str::uuid(),
                    'firstName'     => $data['firstName'],
                    'middleInitial' => null,
                    'lastName'      => $data['lastName'],
                    'password'      => Hash::make('password123'),
                    'phoneNumber'   => '09' . random_int(100000000, 999999999),
                    'birthDate'     => '2003-01-01',
                    'address'       => 'Davao City',
                    'userType'      => 'student',
                ]
            );

            return Student::firstOrCreate(
                ['studentID' => $user->userID],
                [
                    'uuid'               => Str::uuid(),
                    'studentIDNumber'    => 'STI-' . strtoupper(Str::random(6)),
                    'barcodeValue'       => Student::generateUniqueBarcode(),
                    'academicProgram'    => $data['program'],
                    'registrationStatus' => 'approved',
                ]
            );
        });

        $requests = [
            ['title' => 'The Origin of Species', 'author' => 'Charles Darwin', 'reason' => 'Requested for a thesis literature review on evolutionary theory.', 'status' => 'Pending'],
            ['title' => 'Clean Code', 'author' => 'Robert C. Martin', 'reason' => 'Reference material for the Software Engineering capstone project.', 'status' => 'Pending'],
            ['title' => 'The Design of Everyday Things', 'author' => 'Don Norman', 'reason' => 'Would help with our UI/UX elective coursework.', 'status' => 'Approved'],
            ['title' => 'Sapiens: A Brief History of Humankind', 'author' => 'Yuval Noah Harari', 'reason' => 'Personal interest reading for a book club discussion.', 'status' => 'Rejected'],
            ['title' => 'Introduction to Algorithms', 'author' => 'Cormen, Leiserson, Rivest, Stein', 'reason' => 'Needed for the Algorithms & Complexity midterm review.', 'status' => 'Pending'],
        ];

        foreach ($requests as $i => $data) {
            $student = $students[$i % $students->count()];
            $isReviewed = $data['status'] !== 'Pending';

            BookSuggestion::create([
                'uuid'                  => Str::uuid(),
                'studentID'             => $student->studentID,
                'reviewedByLibrarianID' => $isReviewed ? $librarian?->librarianID : null,
                'title'                 => $data['title'],
                'author'                => $data['author'],
                'reason'                => $data['reason'],
                'status'                => $data['status'],
                'progressStep'          => $data['status'],
                'submittedAt'           => now()->subDays(10 - $i),
            ]);
        }
    }
}
