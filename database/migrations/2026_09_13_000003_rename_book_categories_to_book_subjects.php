<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Raw SQL for the column renames — Schema::renameColumn() requires
    // doctrine/dbal, which isn't installed in this project.

    public function up(): void
    {
        Schema::rename('book_categories', 'book_subjects');
        DB::statement('ALTER TABLE book_subjects CHANGE categoryID subjectID BIGINT UNSIGNED AUTO_INCREMENT');
        DB::statement('ALTER TABLE books CHANGE categoryID subjectID BIGINT UNSIGNED');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE books CHANGE subjectID categoryID BIGINT UNSIGNED');
        DB::statement('ALTER TABLE book_subjects CHANGE subjectID categoryID BIGINT UNSIGNED AUTO_INCREMENT');
        Schema::rename('book_subjects', 'book_categories');
    }
};
