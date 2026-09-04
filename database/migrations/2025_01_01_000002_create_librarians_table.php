<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('librarians', function (Blueprint $table) {
            $table->foreignId('librarianID')->primary()->constrained('users', 'userID')->cascadeOnDelete(); // Updated
            $table->uuid('uuid')->unique();
            $table->string('role')->default('librarian');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('librarians');
    }
};
