<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The copy number ("c.1") was a free-text label on the book record that nothing used: every physical copy
     * already has its own accession number and barcode. Nothing was stored in it when it was removed.
     */
    public function up(): void
    {
        if (Schema::hasColumn('books', 'copyNumber')) {
            Schema::table('books', function (Blueprint $table) {
                $table->dropColumn('copyNumber');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('books', 'copyNumber')) {
            Schema::table('books', function (Blueprint $table) {
                $table->string('copyNumber')->nullable()->after('cost');
            });
        }
    }
};
