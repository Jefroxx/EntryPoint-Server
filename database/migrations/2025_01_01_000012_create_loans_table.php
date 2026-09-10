<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id('loanID');
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete();
            $table->foreignId('copyID')->constrained('book_copies', 'copyID')->cascadeOnDelete();
            $table->string('loanType')->default('standard');
            $table->timestamp('checkoutDate')->useCurrent();
            $table->timestamp('dueDate');
            $table->timestamp('returnDate')->nullable();
            $table->string('status')->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
