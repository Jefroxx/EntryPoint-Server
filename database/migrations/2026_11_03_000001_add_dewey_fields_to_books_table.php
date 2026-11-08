<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Dewey number gets its own column, with where it came from and whether a person has checked it.
     * Until now it only existed as the first half of `classNumber` ("254.52 Z##"), and many of those were
     * guessed from the category name, so every book that exists today starts out as "needs_review".
     *
     * deweySource: library_of_congress | open_library | manual | guessed
     * deweyStatus: ok | needs_review
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('deweyNumber', 32)->nullable()->after('classNumber');
            $table->string('deweySource', 24)->nullable()->after('deweyNumber');
            $table->string('deweyStatus', 16)->default('needs_review')->after('deweySource')->index();
        });

        // Show what is on the label today (the number part of "254.52 Z##"), but don't trust it.
        DB::table('books')->orderBy('bookID')->each(function ($book) {
            if (preg_match('/^(\d{3}(?:\.\d+)?)/', (string) $book->classNumber, $m)) {
                DB::table('books')->where('bookID', $book->bookID)->update(['deweyNumber' => $m[1], 'deweySource' => 'guessed']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['deweyStatus']);
            $table->dropColumn(['deweyNumber', 'deweySource', 'deweyStatus']);
        });
    }
};
