<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id('wishlistID');
            $table->uuid('uuid')->unique();
            $table->foreignId('studentID')->constrained('students', 'studentID')->cascadeOnDelete();
            $table->foreignId('bookID')->constrained('books', 'bookID')->cascadeOnDelete();
            $table->boolean('inCart')->default(false);
            $table->timestamp('addedAt')->useCurrent();
            $table->timestamps();

            $table->unique(['studentID', 'bookID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
