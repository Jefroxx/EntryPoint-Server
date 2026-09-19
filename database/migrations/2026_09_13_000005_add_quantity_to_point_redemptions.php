<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_redemptions', function (Blueprint $table) {
            // A single redemption row now represents one cart line, which may
            // cover more than one unit; pointsSpent is the line total (pointCost * quantity).
            $table->unsignedInteger('quantity')->default(1)->after('itemID');
        });
    }

    public function down(): void
    {
        Schema::table('point_redemptions', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
