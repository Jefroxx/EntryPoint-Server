<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id('bookID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('categoryID')->constrained('book_categories', 'categoryID')->cascadeOnUpdate()->restrictOnDelete(); // Updated
            $table->string('title');
            $table->string('callNumber');
            $table->string('accessionNumber')->unique();
            $table->string('coverImageURL')->nullable();
            $table->string('shelfLocation')->nullable();
            $table->unsignedInteger('totalCopies')->default(1);
            $table->unsignedInteger('availableCopies')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
