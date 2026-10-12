<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account an administrator created, or whose password they reset, starts with a temporary password the
 * administrator knows. Until the person sets their own, that is all the account can do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mustChangePassword')->default(false)->after('disabledAt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mustChangePassword');
        });
    }
};
