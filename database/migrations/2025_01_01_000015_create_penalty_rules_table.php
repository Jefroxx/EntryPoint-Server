<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table) {
            $table->id('ruleID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('penaltyTypeID')->constrained('penalty_types', 'penaltyTypeID')->cascadeOnDelete(); // Updated
            $table->decimal('rate', 10, 2);
            $table->unsignedInteger('gracePeriodDays')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_rules');
    }
};
