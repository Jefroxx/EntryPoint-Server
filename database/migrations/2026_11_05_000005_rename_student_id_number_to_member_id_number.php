<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `students` table holds every borrower: students, faculty and staff. Faculty and staff members have an
     * Employee ID, not a student ID, and used to be stored in a column named `studentIDNumber`. It is now the
     * member's ID number, whichever kind of member they are.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->renameColumn('studentIDNumber', 'memberIDNumber');
            $table->renameIndex('students_studentidnumber_unique', 'students_memberidnumber_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->renameIndex('students_memberidnumber_unique', 'students_studentidnumber_unique');
            $table->renameColumn('memberIDNumber', 'studentIDNumber');
        });
    }
};
