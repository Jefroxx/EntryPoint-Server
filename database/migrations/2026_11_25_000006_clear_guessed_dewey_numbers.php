<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A Dewey number is typed in by the librarian or read from a catalogue; it is never guessed. Numbers that were
     * only a guess from a book's category (the old keyword map) are cleared, so the book waits, blank, on the
     * "Accept Dewey" list for a real number. They can always be rebuilt from the category, so nothing real is lost.
     */
    public function up(): void
    {
        DB::table('books')->where('deweySource', 'guessed')->update([
            'deweyNumber' => null,
            'deweySource' => null,
            'deweyStatus' => 'needs_review',
        ]);
    }

    public function down(): void
    {
        // The guesses are not restored: they were never real numbers.
    }
};
