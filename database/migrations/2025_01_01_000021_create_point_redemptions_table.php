<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_redemptions', function (Blueprint $table) {
            $table->id('redemptionID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            $table->foreignId('itemID')->constrained('market_items', 'itemID')->restrictOnDelete(); // Updated
            $table->unsignedInteger('pointsSpent');
            $table->string('fulfillmentStatus')->default('Pending');
            $table->timestamp('redeemedAt')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_redemptions');
    }
};
