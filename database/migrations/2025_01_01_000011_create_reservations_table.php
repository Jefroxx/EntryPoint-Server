<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id('reservationID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            $table->foreignId('bookID')->constrained('books', 'bookID')->cascadeOnDelete(); // Updated
            $table->enum('status', ['Waiting', 'Accepted', 'Rejected'])->default('Waiting');
            $table->timestamp('reservedAt')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
