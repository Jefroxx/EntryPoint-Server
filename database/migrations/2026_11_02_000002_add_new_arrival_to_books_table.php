<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A book is only a "new arrival" when the librarian ticks it while adding the book. The library also
     * catalogues old books, so adding a book alone must not count as an arrival.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('isNewArrival')->default(false)->after('shelfLocation');
            $table->timestamp('newArrivalAt')->nullable()->after('isNewArrival');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['isNewArrival', 'newArrivalAt']);
        });
    }
};
