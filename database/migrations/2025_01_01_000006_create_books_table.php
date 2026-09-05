<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id('bookID');
            $table->uuid('uuid')->unique();
            $table->foreignId('categoryID')->constrained('book_categories', 'categoryID')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('title');
            $table->string('callNumber');
            $table->string('coverImageURL')->nullable();
            $table->string('shelfLocation')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
