<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_author', function (Blueprint $table) {
            $table->uuid('uuid')->unique();
            $table->foreignId('bookID')->constrained('books', 'bookID')->cascadeOnDelete(); // Updated
            $table->foreignId('authorID')->constrained('authors', 'authorID')->cascadeOnDelete(); // Updated
            $table->string('role')->nullable();
            $table->primary(['bookID', 'authorID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_author');
    }
};
