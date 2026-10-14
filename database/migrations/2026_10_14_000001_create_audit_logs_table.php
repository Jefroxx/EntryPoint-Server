<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what: every change staff make to records (approvals, fines, the catalog, settings) and every account or role
 * change an administrator makes. The actor's name and role are copied in, so an entry stays readable after the account
 * is renamed or removed. Entries are only ever added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id('logID');
            $table->foreignId('actorID')->nullable()->constrained('users', 'userID')->nullOnDelete();
            $table->string('actorName', 120);
            $table->string('actorRole', 20);
            // "student.approved": the area, then what happened. The area drives the filter on the admin screen.
            $table->string('action', 60)->index();
            $table->string('summary', 255);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
