<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Raw SQL for the column rename — Schema::renameColumn() requires
    // doctrine/dbal, which isn't installed in this project.

    public function up(): void
    {
        Schema::rename('badges', 'achievements');

        DB::statement('ALTER TABLE achievements CHANGE badgeID achievementID BIGINT UNSIGNED AUTO_INCREMENT');

        Schema::table('achievements', function (Blueprint $table) {
            $table->unsignedInteger('pointsReward')->default(0)->after('criteriaJSON');
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn('pointsReward');
        });

        DB::statement('ALTER TABLE achievements CHANGE achievementID badgeID BIGINT UNSIGNED AUTO_INCREMENT');

        Schema::rename('achievements', 'badges');
    }
};
