<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Fixes a real bug: LoanController::store() sets a reservation's status
    // to 'Fulfilled' when converting it into a loan, but that value was
    // never in the enum — every such conversion threw a truncation error.

    public function up(): void
    {
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected', 'Fulfilled') NOT NULL DEFAULT 'Waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('Waiting', 'Accepted', 'Rejected') NOT NULL DEFAULT 'Waiting'");
    }
};
