<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Faculty and staff members, as the administrator would create them: approved from the start, an employee ID in place
 * of a student ID, and a department in place of a program. They borrow and visit, but have no points or achievements.
 */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            ['Roberto', 'M', 'Alcantara', 'roberto.alcantara@davao.sti.edu.ph', 'faculty', 'Information Technology', 'EMP-2019-014'],
            ['Liza', 'P', 'Mendoza', 'liza.mendoza@davao.sti.edu.ph', 'faculty', 'Business and Accountancy', 'EMP-2017-032'],
            ['Gerald', 'T', 'Navarro', 'gerald.navarro@davao.sti.edu.ph', 'staff', "Registrar's Office", 'EMP-2021-006'],
        ];

        foreach ($members as [$first, $middle, $last, $email, $type, $department, $employeeID]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'uuid'            => Str::uuid(),
                    'firstName'       => $first,
                    'middleInitial'   => $middle,
                    'lastName'        => $last,
                    'password'        => Hash::make('password123'),
                    'phoneNumber'     => '09' . fake()->numerify('#########'),
                    'birthDate'       => '1985-05-05',
                    'address'         => 'Davao City',
                    'userType'        => 'student',
                    'emailVerifiedAt' => now(),
                ]
            );

            Student::firstOrCreate(
                ['studentID' => $user->userID],
                [
                    'uuid'               => Str::uuid(),
                    'studentIDNumber'    => $employeeID,
                    'memberType'         => $type,
                    'department'         => $department,
                    'barcodeValue'       => 'STI-' . strtoupper(Str::random(10)),
                    'registrationStatus' => 'approved',
                    'reviewedAt'         => now(),
                ]
            );
        }
    }
}
