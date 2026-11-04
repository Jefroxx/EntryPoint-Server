<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A call number is the Dewey number plus the author's Cutter ("005.13 C67"), so storing it as well as the Dewey
     * number kept the same digits in two places that had to be changed together. Now only the Cutter part is stored
     * (`cutter`, with a/b/c on the end when two books would otherwise share a number) and the call number is worked
     * out from the two columns. The pair is unique, so two books can never share a shelf number.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('cutter', 32)->nullable()->after('deweyNumber');
        });

        // "<dewey> <cutter>" -> "<cutter>". A call number that doesn't start with its Dewey number (a hand-typed
        // one) can't be split safely, so its Cutter part is left empty rather than guessed.
        DB::statement("UPDATE `books` SET `cutter` = NULLIF(TRIM(SUBSTRING(`classNumber`, CHAR_LENGTH(`deweyNumber`) + 1)), '')
            WHERE `deweyNumber` IS NOT NULL AND `classNumber` IS NOT NULL AND LOCATE(`deweyNumber`, `classNumber`) = 1");

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('classNumber');
            $table->unique(['deweyNumber', 'cutter'], 'books_call_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropUnique('books_call_number_unique');
            $table->string('classNumber')->default('')->after('title');
        });

        DB::statement("UPDATE `books` SET `classNumber` = TRIM(CONCAT_WS(' ', `deweyNumber`, `cutter`))");

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('cutter');
        });
    }
};
