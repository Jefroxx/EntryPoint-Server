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
        $this->createLibrarian('Juan', 'D', 'Dela Cruz', 'librarian@davao.sti.edu.ph', '09123456789', 'Davao City');
        $this->createLibrarian('Maria', 'S', 'Santos', 'maria.santos@davao.sti.edu.ph', '09187654321', 'Davao City');

        // A student assistant at the desk (circulation and attendance only).
        $this->createLibrarian('Paolo', 'M', 'Lim', 'assistant@davao.sti.edu.ph', '09181234567', 'Davao City', 'assistant');

        // The administrator is never a demo account on a hosted copy: there it comes from ADMIN_EMAIL / ADMIN_PASSWORD
        // (see SetupHosting) and must change its password at the first sign-in, because the demo password is known.
        // On a developer's own computer one is made here, so the admin side can be tried.
        if (app()->environment('local', 'testing')) {
            $this->createLibrarian('Ana', 'R', 'Reyes', 'admin@davao.sti.edu.ph', '09171234567', 'Davao City', 'admin');
        }
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
                // Sample accounts: staff must confirm their email to sign in, and nobody is mailing these.
                'emailVerifiedAt' => now(),
            ]
        );

        Librarian::firstOrCreate(
            ['librarianID' => $user->userID],
            ['uuid' => Str::uuid(), 'role' => $role]
        );
    }
}
