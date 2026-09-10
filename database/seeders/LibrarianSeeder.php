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
        $user = User::create([
            'uuid'           => Str::uuid(),
            'firstName'      => 'Juan',
            'middleInitial'  => 'D',
            'lastName'       => 'Dela Cruz',
            'email'          => 'librarian@stidavao.edu.ph',
            'password'       => Hash::make('password123'),
            'phoneNumber'    => '09123456789',
            'birthDate'      => '1990-01-01',
            'address'        => 'Davao City',
            'userType'       => 'librarian',
        ]);

        Librarian::create([
            'librarianID' => $user->userID,
            'uuid'        => Str::uuid(),
            'role'        => 'librarian',
        ]);
    }
}
