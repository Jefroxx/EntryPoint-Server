<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected', 'Fulfilled') NOT NULL DEFAULT 'Waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected') NOT NULL DEFAULT 'Waiting'");
    }
};
