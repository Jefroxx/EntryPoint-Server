<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_suggestions', function (Blueprint $table) {
            $table->id('suggestionID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete(); // Updated
            $table->foreignId('reviewedByLibrarianID')->nullable()->constrained('librarians', 'librarianID')->nullOnDelete(); // Updated
            $table->string('title');
            $table->string('author')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('Pending');
            $table->string('progressStep')->nullable();
            $table->timestamp('submittedAt')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_suggestions');
    }
};
