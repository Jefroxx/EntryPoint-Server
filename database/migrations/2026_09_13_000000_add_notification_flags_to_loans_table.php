<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('dueSoonNotifiedAt')->nullable()->after('dueDate');
            $table->timestamp('overdueNotifiedAt')->nullable()->after('dueSoonNotifiedAt');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['dueSoonNotifiedAt', 'overdueNotifiedAt']);
        });
    }
};
