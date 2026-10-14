<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a copy came from. Every copy added to an existing book records its source of fund and cost (and
     * optional remarks). Nullable only because copies that exist already, or that were made with a new book,
     * have no such record; the Add Book Copy form does not let a new one go without.
     */
    public function up(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->string('sourceOfFund')->nullable()->after('status');
            $table->decimal('cost', 10, 2)->nullable()->after('sourceOfFund');
            $table->text('remarks')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropColumn(['sourceOfFund', 'cost', 'remarks']);
        });
    }
};
