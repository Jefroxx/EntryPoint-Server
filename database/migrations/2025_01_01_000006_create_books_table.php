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
            $table->foreignId('subjectID')->constrained('book_subjects', 'subjectID')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('areasOfLibrary', ['circulation', 'reserved', 'filipiniana', 'fiction', 'thesis', 'journal', 'dissertation'])->default('circulation');
            $table->string('title');
            $table->string('classNumber');
            $table->string('isbn')->nullable();
            $table->string('volume')->nullable();
            $table->string('edition')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->string('publisher')->nullable();
            $table->string('sourceOfFund')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('copyNumber')->nullable();
            $table->text('remarks')->nullable();
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
