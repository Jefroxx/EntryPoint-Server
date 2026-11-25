<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 1. A notice's text was limited to 255 characters. With a long book title the penalty notice ran past it: the
     *    penalty was saved, then sending the notice failed, so the librarian saw "Server Error" for something that had
     *    worked. The text can be as long as it needs now.
     * 2. A fine can end a new way, "Waived": the librarian forgives it, with a reason.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY message TEXT NOT NULL');
        DB::statement("ALTER TABLE penalties MODIFY paymentStatus ENUM('Unpaid','Paid','Replaced','Waived') NOT NULL DEFAULT 'Unpaid'");
    }

    public function down(): void
    {
        DB::table('penalties')->where('paymentStatus', 'Waived')->update(['paymentStatus' => 'Paid']);
        DB::statement("ALTER TABLE penalties MODIFY paymentStatus ENUM('Unpaid','Paid','Replaced') NOT NULL DEFAULT 'Unpaid'");
        DB::statement('ALTER TABLE notifications MODIFY message VARCHAR(255) NOT NULL');
    }
};
