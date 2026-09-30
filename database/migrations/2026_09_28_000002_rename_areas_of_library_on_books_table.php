<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A book sits in one area of the library, so the column is singular: areasOfLibrary -> areaOfLibrary.
//
// Raw SQL rather than renameColumn(): on MariaDB the Blueprint path re-reads the enum's existing
// default, re-quotes it, and emits `default '''circulation'''`, which fails with
// "1067 Invalid default value for 'areaOfLibrary'".
return new class extends Migration
{
    private const DEFINITION = "ENUM('circulation','reserved','filipiniana','fiction','thesis','journal','dissertation') "
        ."COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'circulation'";

    public function up(): void
    {
        DB::statement('ALTER TABLE `books` CHANGE `areasOfLibrary` `areaOfLibrary` '.self::DEFINITION);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `books` CHANGE `areaOfLibrary` `areasOfLibrary` '.self::DEFINITION);
    }
};
