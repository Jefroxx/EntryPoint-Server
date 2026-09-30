<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Photos of a book's reference pages (table of contents, appendix, bibliography, index, about the author),
// taken by the librarian so students can look inside before borrowing. Files live on the public disk.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_pages', function (Blueprint $table) {
            $table->id('pageID');
            $table->uuid('uuid')->unique();
            $table->foreignId('bookID')->constrained('books', 'bookID')->cascadeOnDelete();
            $table->enum('section', ['table_of_contents', 'appendix', 'bibliography', 'index', 'about_the_author']);
            $table->unsignedSmallInteger('position');
            $table->string('path');
            $table->timestamps();

            $table->index(['bookID', 'section', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_pages');
    }
};
