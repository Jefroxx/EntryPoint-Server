<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Accession numbers become a plain running number (1, 2, 3...) instead of ACC-YYYY-XXXXXX.
// Existing copies are renumbered in the order they were added (copyID); new copies take the next one.
return new class extends Migration
{
    public function up(): void
    {
        // Still a string column here, so '1', '2'... can't collide with the old ACC-... values mid-update.
        DB::statement('SET @n := 0');
        DB::statement('UPDATE book_copies SET accessionNumber = (@n := @n + 1) ORDER BY copyID');

        Schema::table('book_copies', function (Blueprint $table) {
            $table->unsignedInteger('accessionNumber')->change();
        });
    }

    public function down(): void
    {
        // The old random codes are gone; the numbers stay, as text.
        Schema::table('book_copies', function (Blueprint $table) {
            $table->string('accessionNumber')->change();
        });
    }
};
