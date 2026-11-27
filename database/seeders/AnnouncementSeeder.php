<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Librarian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Two current notices, one for each side, so the banner shows on student Home and on the librarian Dashboard. */
class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $by = Librarian::whereIn('role', ['admin', 'librarian'])->orderByRaw("role = 'admin' desc")->value('librarianID');

        Announcement::create([
            'uuid'        => Str::uuid(),
            'title'       => 'Library hours during the semestral break',
            'body'        => 'The library is open 8:00 AM to 5:00 PM from Monday to Friday during the break. Books borrowed now are due after the break.',
            'audience'    => 'students',
            'expiresAt'   => now()->addWeeks(3),
            'createdByID' => $by,
        ]);

        Announcement::create([
            'uuid'        => Str::uuid(),
            'title'       => 'Barcode labels for the imported books',
            'body'        => 'The books brought in from the accession record need their new barcode labels. Print them from the Book Catalog and label one shelf at a time.',
            'audience'    => 'staff',
            'expiresAt'   => now()->addWeeks(2),
            'createdByID' => $by,
        ]);
    }
}
