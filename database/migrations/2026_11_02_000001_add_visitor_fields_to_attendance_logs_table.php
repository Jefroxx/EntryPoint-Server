<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Walk-in visitors have no account. Their visit is a log with no student, plus the name and purpose
     * the librarian typed in.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['studentID']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('studentID')->nullable()->change();
            $table->string('visitorName', 120)->nullable()->after('studentID');
            $table->string('visitorPurpose', 255)->nullable()->after('visitorName');
            $table->foreign('studentID')->references('studentID')->on('students')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['studentID']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['visitorName', 'visitorPurpose']);
        });
    }
};
