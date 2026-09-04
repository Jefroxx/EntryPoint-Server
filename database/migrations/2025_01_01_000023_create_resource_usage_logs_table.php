<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_usage_logs', function (Blueprint $table) {
            $table->id('usageID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('resID')->constrained('resources', 'resID')->cascadeOnDelete(); // Updated
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            $table->foreignId('staffLibrarianID')->nullable()->constrained('librarians', 'librarianID')->nullOnDelete(); // Updated
            $table->timestamp('startTime')->useCurrent();
            $table->timestamp('endTime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_usage_logs');
    }
};
