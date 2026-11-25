<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why a librarian rejected a registration. The student is sent it, and it stays on the record. */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('rejectionReason', 500)->nullable()->after('reviewedAt');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('rejectionReason');
        });
    }
};
