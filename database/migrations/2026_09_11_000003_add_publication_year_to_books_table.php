<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Feeds the auto-generated call number ("{class} .{cutter} {year}").
            // Defaults to the year the book is catalogued when not supplied.
            $table->unsignedSmallInteger('publicationYear')->nullable()->after('callNumber');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('publicationYear');
        });
    }
};
