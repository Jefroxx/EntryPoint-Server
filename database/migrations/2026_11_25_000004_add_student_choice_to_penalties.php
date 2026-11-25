<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * For a lost or damaged book the student pays the amount or replaces the book. They say which in the app
     * ("pay" or "replace"), so the desk knows what to expect; the librarian still records the payment or the
     * replacement when it actually happens. Null until they choose.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->string('studentChoice', 16)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn('studentChoice');
        });
    }
};
