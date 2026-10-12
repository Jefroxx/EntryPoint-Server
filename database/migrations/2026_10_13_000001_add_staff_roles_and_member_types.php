<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff roles and member types.
 *
 *  - users.disabledAt: an account the administrator has switched off. It can't sign in and its tokens stop working.
 *  - students.memberType / department: faculty and staff borrow like students (loans, attendance and reservations all
 *    point at this table) but take no part in the Hall of Fame, achievements or points shop.
 *  - role_permissions: which parts of the librarian side a staff role may use. The administrator edits it.
 *    A role with no row uses the defaults in config/permissions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabledAt')->nullable()->after('emailVerifiedAt');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->enum('memberType', ['student', 'faculty', 'staff'])->default('student')->after('studentIDNumber')->index();
            $table->string('department', 80)->nullable()->after('academicProgram');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role', 30)->primary();
            $table->json('permissions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['memberType']);
            $table->dropColumn(['memberType', 'department']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabledAt');
        });
    }
};
