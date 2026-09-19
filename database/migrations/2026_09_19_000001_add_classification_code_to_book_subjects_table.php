<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_subjects', function (Blueprint $table) {
            // Dewey-style classification code, e.g. "100". Auto-assigned the
            // first time a subject is used on a book (see
            // BookController::classificationCode()), editable afterwards.
            $table->string('classificationCode', 20)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('book_subjects', function (Blueprint $table) {
            $table->dropUnique(['classificationCode']);
            $table->dropColumn('classificationCode');
        });
    }
};
