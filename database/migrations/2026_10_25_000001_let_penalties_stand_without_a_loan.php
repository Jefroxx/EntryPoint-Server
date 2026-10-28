<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A penalty now knows its student and its copy itself, so a book that is lost or damaged inside the library (never
     * lent) can be charged with no loan behind it. Late fees keep their loan; the columns are filled in for them too.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropForeign(['loanID']);
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->unsignedBigInteger('loanID')->nullable()->change();
            $table->unsignedBigInteger('studentID')->nullable()->after('loanID');
            $table->unsignedBigInteger('copyID')->nullable()->after('studentID');

            $table->foreign('loanID')->references('loanID')->on('loans')->cascadeOnDelete();
            $table->foreign('studentID')->references('studentID')->on('students')->cascadeOnDelete();
            $table->foreign('copyID')->references('copyID')->on('book_copies')->cascadeOnDelete();
        });

        DB::statement('update penalties p join loans l on l.loanID = p.loanID set p.studentID = l.studentID, p.copyID = l.copyID');
    }

    public function down(): void
    {
        // A penalty with no loan cannot exist without the column being optional.
        DB::table('penalties')->whereNull('loanID')->delete();

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropForeign(['loanID']);
            $table->dropForeign(['studentID']);
            $table->dropForeign(['copyID']);
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn(['studentID', 'copyID']);
            $table->unsignedBigInteger('loanID')->nullable(false)->change();
            $table->foreign('loanID')->references('loanID')->on('loans')->cascadeOnDelete();
        });
    }
};
