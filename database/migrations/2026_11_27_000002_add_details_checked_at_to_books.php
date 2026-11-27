<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a librarian last went through this book with Check Details (the online catalogues asked for what its record
 * lacks). A checked book is not offered again, whether or not anything was found, so the list only ever shrinks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->timestamp('detailsCheckedAt')->nullable()->after('deweyStatus');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('detailsCheckedAt');
        });
    }
};
