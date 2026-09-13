<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('badges', 'achievements');
        Schema::table('achievements', function (Blueprint $table) {
            $table->renameColumn('badgeID', 'achievementID');
        });

        Schema::rename('student_badge', 'student_achievement');
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->renameColumn('studentBadgeID', 'studentAchievementID');
            $table->renameColumn('badgeID', 'achievementID');
        });
    }

    public function down(): void
    {
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->renameColumn('achievementID', 'badgeID');
            $table->renameColumn('studentAchievementID', 'studentBadgeID');
        });
        Schema::rename('student_achievement', 'student_badge');

        Schema::table('achievements', function (Blueprint $table) {
            $table->renameColumn('achievementID', 'badgeID');
        });
        Schema::rename('achievements', 'badges');
    }
};
