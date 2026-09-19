<?php

namespace Database\Seeders;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NotificationSeeder extends Seeder
{
    private const MESSAGES = [
        ['message' => 'Your registration has been approved! You can now log in.', 'type' => 'registration_approved'],
        ['message' => 'Your reservation is ready for pickup!', 'type' => 'reservation_accepted'],
        ['message' => 'A book you checked out is due soon.', 'type' => 'loan_due_soon'],
        ['message' => 'You\'ve unlocked a new achievement!', 'type' => 'achievement_unlocked'],
        ['message' => 'A new student registration is pending approval.', 'type' => 'new_registration'],
        ['message' => 'A new book suggestion was submitted.', 'type' => 'new_book_suggestion'],
    ];

    public function run(): void
    {
        $librarians = Librarian::all();
        $students = Student::where('registrationStatus', 'approved')->inRandomOrder()->limit(15)->get();

        foreach ($librarians as $librarian) {
            foreach (self::MESSAGES as $i => $entry) {
                SystemNotification::create([
                    'uuid'    => Str::uuid(),
                    'userID'  => $librarian->librarianID,
                    'message' => $entry['message'],
                    'type'    => $entry['type'],
                    'sentAt'  => now()->subHours(random_int(1, 200)),
                    'isRead'  => $i % 2 === 0,
                ]);
            }
        }

        foreach ($students as $student) {
            $entry = self::MESSAGES[array_rand(self::MESSAGES)];

            SystemNotification::create([
                'uuid'    => Str::uuid(),
                'userID'  => $student->studentID,
                'message' => $entry['message'],
                'type'    => $entry['type'],
                'sentAt'  => now()->subHours(random_int(1, 200)),
                'isRead'  => (bool) random_int(0, 1),
            ]);
        }
    }
}
