<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notificationID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('userID')->constrained('users', 'userID')->cascadeOnDelete(); // Updated
            $table->string('message');
            $table->string('type')->nullable();
            $table->timestamp('sentAt')->useCurrent();
            $table->boolean('isRead')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
