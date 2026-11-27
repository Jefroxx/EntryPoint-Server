<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a copy reached the library, as the accession record has it ("Date Received"). Kept apart from created_at,
 * which is when the copy was typed into EntryPoint: for a copy brought in from the old record, the two are years apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->date('dateReceived')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropColumn('dateReceived');
        });
    }
};
