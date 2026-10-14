<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The librarian's stock log: one row each time a copy is added to or removed from the catalog.
     * The title, accession number and librarian's name are copied in, so the log still reads correctly
     * after a book is deleted or a librarian account changes.
     */
    public function up(): void
    {
        Schema::create('book_stock_logs', function (Blueprint $table) {
            $table->id('logID');
            $table->unsignedBigInteger('bookID')->nullable()->index();
            $table->string('bookTitle');
            $table->unsignedBigInteger('copyID')->nullable()->index();
            $table->string('accessionNumber')->nullable();
            $table->string('action', 16); // 'added' | 'removed'
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('librarianID')->nullable();
            $table->string('librarianName')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_stock_logs');
    }
};
