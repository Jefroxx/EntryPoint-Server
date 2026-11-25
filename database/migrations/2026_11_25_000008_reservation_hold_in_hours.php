<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The reservation hold is kept in hours now (so it can be less than a day); a value saved in days is converted. */
    public function up(): void
    {
        $days = DB::table('app_settings')->where('key', 'reservation_hold_days')->value('value');

        if ($days !== null) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'reservation_hold_hours'],
                ['value' => (string) ((int) $days * 24), 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('app_settings')->where('key', 'reservation_hold_days')->delete();
        }
    }

    public function down(): void
    {
        $hours = DB::table('app_settings')->where('key', 'reservation_hold_hours')->value('value');

        if ($hours !== null) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'reservation_hold_days'],
                ['value' => (string) max(1, intdiv((int) $hours, 24)), 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('app_settings')->where('key', 'reservation_hold_hours')->delete();
        }
    }
};
