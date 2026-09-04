<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_return_reports', function (Blueprint $table) {
            $table->id('reportID'); // Updated
            $table->uuid('uuid')->unique();
            $table->foreignId('loanID')->unique()->constrained('loans', 'loanID')->cascadeOnDelete(); // Updated
            $table->foreignId('verifiedByLibrarianID')->nullable()->constrained('librarians', 'librarianID')->nullOnDelete(); // Updated
            $table->timestamp('reportedAt')->useCurrent();
            $table->string('verificationStatus')->default('Pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_return_reports');
    }
};
