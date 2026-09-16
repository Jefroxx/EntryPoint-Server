<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->index('status');
            $table->index('checkoutDate');
            $table->index('returnDate');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->index('registrationStatus');
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->index('paymentStatus');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['checkoutDate']);
            $table->dropIndex(['returnDate']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['registrationStatus']);
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropIndex(['paymentStatus']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
        });
    }
};
