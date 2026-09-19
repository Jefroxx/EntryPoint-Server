<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id('logID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            // dateTime, not timestamp: on MariaDB a bare NOT NULL timestamp silently gets
            // ON UPDATE CURRENT_TIMESTAMP, which would overwrite entryTime at check-out.
            $table->dateTime('entryTime');
            $table->timestamp('exitTime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
