<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_badge', function (Blueprint $table) {
            $table->id('studentBadgeID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            $table->foreignId('badgeID')->constrained('badges', 'badgeID')->cascadeOnDelete(); // Updated
            $table->string('triggerEvent')->nullable();
            $table->timestamp('earnedAt')->useCurrent();
            $table->timestamps();

            $table->unique(['studentID', 'badgeID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_badge');
    }
};
