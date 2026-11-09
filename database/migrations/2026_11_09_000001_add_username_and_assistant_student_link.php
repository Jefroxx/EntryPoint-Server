<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student assistants are picked from the registered students and get their own staff login: a generated username and
 * a temporary password, no email.
 *
 *  - users.username: an alternative to the email for signing in. Unique, empty for everyone who signs in by email.
 *  - users.email: may now be empty, because a username-only account has none. (Still unique when present.)
 *  - librarians.linkedStudentID: which student record the assistant was picked from. One assistant account per
 *    student, and a student record that has one can't be deleted from under it.
 *
 * Staff accounts now have to confirm their email before they can sign in, so every staff account that exists today
 * is marked confirmed here (they were all made before this rule, or seeded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->unique()->after('email');
        });

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');

        Schema::table('librarians', function (Blueprint $table) {
            $table->unsignedBigInteger('linkedStudentID')->nullable()->unique()->after('role');
            $table->foreign('linkedStudentID')->references('studentID')->on('students')->restrictOnDelete();
        });

        DB::table('users')->where('userType', 'librarian')->whereNull('emailVerifiedAt')->update(['emailVerifiedAt' => now()]);
    }

    public function down(): void
    {
        Schema::table('librarians', function (Blueprint $table) {
            $table->dropForeign(['linkedStudentID']);
            $table->dropColumn('linkedStudentID');
        });

        // Accounts without an email can't go back to a required one; give them a placeholder first.
        DB::table('users')->whereNull('email')->update(['email' => DB::raw("CONCAT(username, '@no-email.invalid')")]);
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
