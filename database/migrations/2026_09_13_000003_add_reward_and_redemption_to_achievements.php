<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->unsignedInteger('pointsReward')->default(0)->after('criteriaJSON');
        });

        Schema::table('student_achievement', function (Blueprint $table) {
            $table->timestamp('redeemedAt')->nullable()->after('earnedAt');
        });
    }

    public function down(): void
    {
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->dropColumn('redeemedAt');
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn('pointsReward');
        });
    }
};
