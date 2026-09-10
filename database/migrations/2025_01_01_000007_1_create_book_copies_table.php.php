<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id('copyID');
            $table->uuid('uuid')->unique();
            $table->foreignId('bookID')->constrained('books', 'bookID')->cascadeOnDelete();
            $table->string('accessionNumber')->unique();
            $table->string('barcodeValue')->nullable()->unique();
            $table->enum('status', ['available', 'borrowed', 'lost', 'damaged', 'retired'])->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};
