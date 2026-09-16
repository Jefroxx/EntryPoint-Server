<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('student_badge', 'student_achievement');

        DB::statement('ALTER TABLE student_achievement CHANGE badgeID achievementID BIGINT UNSIGNED');

        Schema::table('student_achievement', function (Blueprint $table) {
            $table->timestamp('redeemedAt')->nullable()->after('earnedAt');
        });
    }

    public function down(): void
    {
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->dropColumn('redeemedAt');
        });

        DB::statement('ALTER TABLE student_achievement CHANGE achievementID badgeID BIGINT UNSIGNED');

        Schema::rename('student_achievement', 'student_badge');
    }
};
