<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->foreignId('studentID')->primary()->constrained('users', 'userID')->cascadeOnDelete(); // Updated
            $table->uuid('uuid')->unique();
            $table->string('studentIDNumber')->unique();
            $table->string('barcodeValue')->nullable()->unique();
            $table->string('academicProgram', 50)->nullable();
            $table->unsignedInteger('knowledgeScore')->default(0);
            $table->unsignedInteger('visitStreak')->default(0);
            $table->enum('registrationStatus', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewedByLibrarianID')->nullable()->constrained('librarians', 'librarianID')->nullOnDelete(); // Updated
            $table->timestamp('reviewedAt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
