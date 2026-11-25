<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What happened to a lost or damaged book, in the librarian's words ("Spilled water on it in the reading room"). */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->after('receiptNumber');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
