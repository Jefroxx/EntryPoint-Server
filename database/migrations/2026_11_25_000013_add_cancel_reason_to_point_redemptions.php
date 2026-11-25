<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why a librarian cancelled a redemption. The student is told it along with the refund. */
    public function up(): void
    {
        Schema::table('point_redemptions', function (Blueprint $table) {
            $table->string('cancelReason', 500)->nullable()->after('fulfillmentStatus');
        });
    }

    public function down(): void
    {
        Schema::table('point_redemptions', function (Blueprint $table) {
            $table->dropColumn('cancelReason');
        });
    }
};
