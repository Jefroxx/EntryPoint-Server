<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_return_reports', function (Blueprint $table) {
            // Why the librarian rejected the report; the student is told.
            $table->text('rejectionReason')->nullable()->after('verificationStatus');
        });
    }

    public function down(): void
    {
        Schema::table('self_return_reports', function (Blueprint $table) {
            $table->dropColumn('rejectionReason');
        });
    }
};
