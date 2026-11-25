<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A request now has a step after "Approved": the book has arrived in the library. The student is told, so
     * the librarian marks it on the Book Requests tab. Both columns are enums, so the new value is added to each.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `book_suggestions` MODIFY `status` ENUM('Pending','Approved','Arrived','Rejected') NOT NULL DEFAULT 'Pending'");
        DB::statement("ALTER TABLE `book_suggestions` MODIFY `progressStep` ENUM('Submitted','Approved','Arrived','Rejected') NULL");
    }

    public function down(): void
    {
        DB::table('book_suggestions')->where('status', 'Arrived')->update(['status' => 'Approved', 'progressStep' => 'Approved']);
        DB::statement("ALTER TABLE `book_suggestions` MODIFY `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending'");
        DB::statement("ALTER TABLE `book_suggestions` MODIFY `progressStep` ENUM('Submitted','Approved','Rejected') NULL");
    }
};
