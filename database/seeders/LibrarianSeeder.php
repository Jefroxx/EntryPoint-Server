<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Librarian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LibrarianSeeder extends Seeder
{
    public function run(): void
    {
        $this->createLibrarian('Juan', 'D', 'Dela Cruz', 'librarian@stidavao.edu.ph', '09123456789', 'Davao City');
        $this->createLibrarian('Maria', 'S', 'Santos', 'maria.santos@stidavao.edu.ph', '09187654321', 'Davao City');

        // The administrator (the admin side), and a student assistant at the desk (circulation and attendance only).
        $this->createLibrarian('Ana', 'R', 'Reyes', 'admin@stidavao.edu.ph', '09171234567', 'Davao City', 'admin');
        $this->createLibrarian('Paolo', 'M', 'Lim', 'assistant@stidavao.edu.ph', '09181234567', 'Davao City', 'assistant');
    }

    private function createLibrarian(string $first, string $middle, string $last, string $email, string $phone, string $address, string $role = 'librarian'): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'uuid'          => Str::uuid(),
                'firstName'     => $first,
                'middleInitial' => $middle,
                'lastName'      => $last,
                'password'      => Hash::make('password123'),
                'phoneNumber'   => $phone,
                'birthDate'     => '1990-01-01',
                'address'       => $address,
                'userType'      => 'librarian',
            ]
        );

        Librarian::firstOrCreate(
            ['librarianID' => $user->userID],
            ['uuid' => Str::uuid(), 'role' => $role]
        );
    }
}
