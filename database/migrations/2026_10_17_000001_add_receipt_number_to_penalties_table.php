<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            // The cashier's official receipt number, written down when the fine is marked paid.
            $table->string('receiptNumber', 50)->nullable()->after('paymentStatus');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn('receiptNumber');
        });
    }
};
