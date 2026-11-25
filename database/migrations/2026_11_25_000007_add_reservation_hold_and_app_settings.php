<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An accepted reservation holds a copy for a limited time (a day to begin with; the librarian can change it in
     * Settings). It needs to remember when it was accepted, and it needs a new final state, Expired, for the ones
     * that were not collected in time. The small `app_settings` table keeps settings like that hold period.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('acceptedAt')->nullable()->after('reservedAt');
        });

        // The ones accepted already count from when they were accepted (the last time the row changed).
        DB::table('reservations')->where('status', 'Accepted')->update(['acceptedAt' => DB::raw('updated_at')]);

        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected', 'Fulfilled', 'Expired') NOT NULL DEFAULT 'Waiting'");
    }

    public function down(): void
    {
        DB::table('reservations')->where('status', 'Expired')->update(['status' => 'Rejected']);
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected', 'Fulfilled') NOT NULL DEFAULT 'Waiting'");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('acceptedAt');
        });

        Schema::dropIfExists('app_settings');
    }
};
