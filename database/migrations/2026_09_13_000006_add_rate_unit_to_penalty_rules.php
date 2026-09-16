<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Needed for Penalty::accrueForLoan()'s elapsedUnits() calculation,
    // which bills per-hour for some areas (e.g. Reserved) and per-day for
    // others. gracePeriodDays is left in place (still used for the free
    // grace window before a penalty starts accruing at all).
    public function up(): void
    {
        Schema::table('penalty_rules', function (Blueprint $table) {
            $table->string('rateUnit')->default('day')->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('penalty_rules', function (Blueprint $table) {
            $table->dropColumn('rateUnit');
        });
    }
};
