<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a student's on-time self-return report is rejected, the book was never really handed in, so its late fee
     * only starts counting from that rejection (or from the due time, if that is later). Null = counted from the due time.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dateTime('fineStartsAt')->nullable()->after('overdueNotifiedAt');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('fineStartsAt');
        });
    }
};
