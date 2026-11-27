<?php

namespace Database\Seeders;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The demo students. Few, and each one shows something (the later seeders give them their loans, fines, visits...):
 *   Bea      the top reader: many visits, books returned on time, points, rewards redeemed
 *   Carlo    an overdue book (a fine growing) and an unpaid late fee from before
 *   Diane    a book due today, and a reservation accepted and waiting at the desk (pickup slip)
 *   Enzo     a book he reported returned while the desk was unattended, and first in a reservation queue
 *   Faith    a lost book, charged and unpaid; in the library right now
 *   Gab      a book handed in that still needs its damage check; second in the same queue; in the library now
 *   Hannah   approved, nothing yet: what a brand-new member sees
 *   Ivan     registered and confirmed his email: waiting for a librarian's approval
 *   Joy      registered, email not confirmed yet
 *   Kyle     registration rejected, with the reason
 * All sign in with the demo password (password123).
 */
class StudentSeeder extends Seeder
{
    public const PEOPLE = [
        // key, first, middle, last, ID number, program, status, email confirmed, points, streak
        ['bea', 'Bea', 'M', 'Santos', '02-2023-0145', 'BS Information Technology', 'approved', true, 320, 6],
        ['carlo', 'Carlo', 'J', 'Reyes', '02-2022-0311', 'BS Computer Science', 'approved', true, 45, 0],
        ['diane', 'Diane', 'L', 'Cruz', '02-2024-0087', 'BS Accountancy', 'approved', true, 120, 2],
        ['enzo', 'Enzo', 'R', 'Garcia', '02-2023-0402', 'BS Hospitality Management', 'approved', true, 60, 1],
        ['faith', 'Faith', 'A', 'Villanueva', '02-2022-0156', 'BS Education', 'approved', true, 90, 3],
        ['gab', 'Gab', 'T', 'Mendoza', '02-2024-0219', 'BS Civil Engineering', 'approved', true, 75, 1],
        ['hannah', 'Hannah', 'C', 'Lim', '02-2025-0033', 'BS Business Administration', 'approved', true, 0, 0],
        ['ivan', 'Ivan', 'P', 'Torres', '02-2025-0118', 'BS Information Technology', 'pending', true, 0, 0],
        ['joy', 'Joy', 'S', 'Navarro', '02-2025-0124', 'BS Psychology', 'pending', false, 0, 0],
        ['kyle', 'Kyle', 'D', 'Ramos', '02-2025-0091', 'BS Criminology', 'rejected', true, 0, 0],
    ];

    /** The email of a demo student by key ("bea" -> bea.santos@davao.sti.edu.ph). */
    public static function email(string $key): string
    {
        foreach (self::PEOPLE as $p) {
            if ($p[0] === $key) {
                return Str::lower($p[1].'.'.$p[3]).'@davao.sti.edu.ph';
            }
        }
        throw new \InvalidArgumentException("No demo student {$key}");
    }

    /** The demo student with that key. */
    public static function find(string $key): Student
    {
        return User::where('email', self::email($key))->firstOrFail()->student;
    }

    public function run(): void
    {
        $librarianID = Librarian::where('role', 'librarian')->value('librarianID');

        foreach (self::PEOPLE as $i => [$key, $first, $middle, $last, $idNumber, $program, $status, $confirmed, $points, $streak]) {
            $user = User::create([
                'uuid'            => Str::uuid(),
                'firstName'       => $first,
                'middleInitial'   => $middle,
                'lastName'        => $last,
                'email'           => self::email($key),
                'password'        => Hash::make('password123'),
                'phoneNumber'     => '0917'.substr(preg_replace('/\D/', '', $idNumber), -7),
                'birthDate'       => sprintf('2004-%02d-%02d', 1 + $i, 10 + $i),
                'address'         => 'Davao City',
                'userType'        => 'student',
                'emailVerifiedAt' => $confirmed ? now()->subDays(30) : null,
            ]);

            Student::create([
                'studentID'             => $user->userID,
                'uuid'                  => Str::uuid(),
                'memberIDNumber'        => $idNumber,
                'memberType'            => 'student',
                'academicProgram'       => $program,
                // Only an approved member has a Library ID to scan.
                'barcodeValue'          => $status === 'approved' ? 'STI-'.strtoupper(substr(md5($idNumber), 0, 10)) : null,
                'knowledgeScore'        => $points,
                'visitStreak'           => $streak,
                'registrationStatus'    => $status,
                'reviewedByLibrarianID' => $status === 'pending' ? null : $librarianID,
                'reviewedAt'            => $status === 'pending' ? null : now()->subDays(28),
                'rejectionReason'       => $status === 'rejected' ? 'The student ID number does not match the school ID shown at the desk.' : null,
            ]);
        }
    }
}
