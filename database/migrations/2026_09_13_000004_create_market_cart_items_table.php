<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_cart_items', function (Blueprint $table) {
            $table->id('cartItemID');
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete();
            $table->foreignId('itemID')->constrained('market_items', 'itemID')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['studentID', 'itemID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_cart_items');
    }
};
