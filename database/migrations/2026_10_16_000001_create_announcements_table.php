<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices from the administrator. `audience` says who sees one: everyone, the student side (students plus faculty and
 * staff members), or the staff side. A notice with an `expiresAt` disappears after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id('announcementID');
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->enum('audience', ['everyone', 'students', 'staff'])->default('everyone');
            $table->timestamp('expiresAt')->nullable();
            $table->foreignId('createdByID')->nullable()->constrained('users', 'userID')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
