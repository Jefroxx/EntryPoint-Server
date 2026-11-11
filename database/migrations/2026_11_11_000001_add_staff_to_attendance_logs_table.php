<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff account scanned a visit in and out. Until now a visit only said when someone came and went, so when several
 * accounts share a desk (a librarian and student assistants) nobody could tell who checked a student in.
 *
 * Both columns are nullable: visits logged before this existed have no one recorded, and a visit closed by the nightly
 * clean-up (or when the scanner ends the day) was closed by the system, not by a person. Like the audit log, a deleted
 * account leaves the visit in place with the name gone, rather than blocking the deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->foreignId('checkedInBy')->nullable()->after('entryTime')->constrained('users', 'userID')->nullOnDelete();
            $table->foreignId('checkedOutBy')->nullable()->after('exitTime')->constrained('users', 'userID')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkedInBy');
            $table->dropConstrainedForeignId('checkedOutBy');
        });
    }
};
