<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            // Dewey-style classification code, e.g. "100". Auto-assigned the
            // first time a category is created (see BookCategory::booted()),
            // editable afterwards by a librarian.
            $table->string('classificationCode', 20)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            $table->dropUnique(['classificationCode']);
            $table->dropColumn('classificationCode');
        });
    }
};
