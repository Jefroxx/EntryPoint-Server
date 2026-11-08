<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `books.pages` was the NUMBER of pages, while the `book_pages` table holds the PHOTOS of a book's contents
     * pages, and `$book->pages` / `$book->pages()` meant different things in code. The number is now `pageCount`.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->renameColumn('pages', 'pageCount');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->renameColumn('pageCount', 'pages');
        });
    }
};
