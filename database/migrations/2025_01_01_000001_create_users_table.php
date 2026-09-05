<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('firstName');
            $table->string('middleInitial', 5)->nullable();
            $table->string('lastName');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phoneNumber')->nullable();
            $table->date('birthDate')->nullable();
            $table->string('address')->nullable();
            $table->enum('userType', ['student', 'librarian']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
