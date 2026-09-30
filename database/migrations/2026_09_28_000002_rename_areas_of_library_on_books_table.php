<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A book sits in one area of the library, so the column is singular: areasOfLibrary -> areaOfLibrary.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->renameColumn('areasOfLibrary', 'areaOfLibrary');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->renameColumn('areaOfLibrary', 'areasOfLibrary');
        });
    }
};
