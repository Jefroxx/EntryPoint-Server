<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('emailVerifiedAt')->nullable()->after('email');
        });

        // Accounts made before verification existed (librarians, already-approved students) count as
        // confirmed; only students who register from now on have to click the link.
        DB::table('users')->update(['emailVerifiedAt' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('emailVerifiedAt');
        });
    }
};
