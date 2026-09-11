<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authors', function (Blueprint $table) {
            // Simplified Cutter-style author number, e.g. "C887". Auto-assigned
            // the first time an author is created (see Author::booted()).
            $table->string('authorNumber', 20)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('authors', function (Blueprint $table) {
            $table->dropUnique(['authorNumber']);
            $table->dropColumn('authorNumber');
        });
    }
};
