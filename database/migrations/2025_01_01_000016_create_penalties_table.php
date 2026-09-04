<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table) {
            $table->id('penaltyID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('loanID')->constrained('loans', 'loanID')->cascadeOnDelete(); // Updated
            $table->foreignId('penaltyTypeID')->constrained('penalty_types', 'penaltyTypeID')->restrictOnDelete(); // Updated
            $table->decimal('amount', 10, 2);
            $table->timestamp('computedAt')->useCurrent();
            $table->timestamp('settledAt')->nullable();
            $table->string('paymentStatus')->default('Unpaid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
