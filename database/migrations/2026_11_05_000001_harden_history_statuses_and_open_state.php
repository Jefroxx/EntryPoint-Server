<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AREAS = "'circulation','reserved','filipiniana','fiction','thesis','journal','dissertation'";

    /**
     * Makes the database refuse bad states on its own, instead of trusting the application to never create them:
     *
     *  1. Status columns that only ever hold a handful of values become enums (a typo is now an error).
     *  2. Records the library must keep (loans, fines, redemptions, visits) can no longer be deleted by a cascade
     *     from the student or copy they belong to.
     *  3. "Only one open X" rules become unique keys, using a hidden generated column that is 1 while the row is
     *     open and NULL once it is closed (NULLs never collide, so any number of closed rows is fine).
     *  4. Indexes for the lookups the app does on every page, and tidy-ups of leftover names and mixed time types.
     */
    public function up(): void
    {
        // ---- 1. enums ---------------------------------------------------------------------------------------
        $enums = [
            ['loans', 'status', "'Active','Received','Returned'", "NOT NULL DEFAULT 'Active'"],
            ['loans', 'loanType', self::AREAS, 'NOT NULL'],
            ['penalties', 'paymentStatus', "'Unpaid','Paid','Replaced'", "NOT NULL DEFAULT 'Unpaid'"],
            ['self_return_reports', 'verificationStatus', "'Pending','Verified','Rejected'", "NOT NULL DEFAULT 'Pending'"],
            ['book_suggestions', 'status', "'Pending','Approved','Rejected'", "NOT NULL DEFAULT 'Pending'"],
            ['book_suggestions', 'progressStep', "'Submitted','Approved','Rejected'", 'NULL'],
            ['point_redemptions', 'fulfillmentStatus', "'Pending','Fulfilled','Cancelled'", "NOT NULL DEFAULT 'Pending'"],
            ['resources', 'status', "'Available','In Use','Unavailable'", "NOT NULL DEFAULT 'Available'"],
            ['librarians', 'role', "'admin','librarian','assistant'", "NOT NULL DEFAULT 'librarian'"],
            ['loan_periods', 'area', self::AREAS, 'NOT NULL'],
        ];
        foreach ($enums as [$table, $column, $values, $rest]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$values}) {$rest}");
        }

        // A visit's entry was a datetime and its exit a timestamp; the two behave differently across time zones.
        DB::statement('ALTER TABLE `attendance_logs` MODIFY `entryTime` TIMESTAMP NOT NULL');

        // ---- 2. history is never cascaded away --------------------------------------------------------------
        $this->restrict('loans', 'studentID', 'students', 'studentID');
        $this->restrict('loans', 'copyID', 'book_copies', 'copyID');
        $this->restrict('penalties', 'loanID', 'loans', 'loanID');
        $this->restrict('penalties', 'studentID', 'students', 'studentID');
        $this->restrict('penalties', 'copyID', 'book_copies', 'copyID');
        $this->restrict('point_redemptions', 'studentID', 'students', 'studentID');
        $this->restrict('attendance_logs', 'studentID', 'students', 'studentID');
        $this->restrict('resource_usage_logs', 'studentID', 'students', 'studentID');

        // ---- 3. "only one open ..." as unique keys ----------------------------------------------------------
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedTinyInteger('openFlag')->nullable()->invisible()->virtualAs("IF(`status` IN ('Active','Received'), 1, NULL)");
            $table->unique(['copyID', 'openFlag'], 'loans_one_open_per_copy');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedTinyInteger('openFlag')->nullable()->invisible()->virtualAs("IF(`status` IN ('Waiting','Accepted'), 1, NULL)");
            $table->unique(['studentID', 'bookID', 'openFlag'], 'reservations_one_open_per_student_book');
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('openFlag')->nullable()->invisible()->virtualAs('IF(`exitTime` IS NULL, 1, NULL)');
            $table->unique(['studentID', 'openFlag'], 'attendance_one_open_visit_per_student');
        });
        Schema::table('resource_usage_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('openFlag')->nullable()->invisible()->virtualAs('IF(`endTime` IS NULL, 1, NULL)');
            $table->unique(['resID', 'openFlag'], 'resource_usage_one_open_session_per_resource');
        });

        // ---- 4. indexes -------------------------------------------------------------------------------------
        Schema::table('loans', function (Blueprint $table) {
            $table->index(['studentID', 'status'], 'loans_student_status_index');
            $table->index(['status', 'dueDate'], 'loans_status_due_index');
        });
        Schema::table('book_copies', fn (Blueprint $table) => $table->index(['bookID', 'status'], 'book_copies_book_status_index'));
        Schema::table('attendance_logs', fn (Blueprint $table) => $table->index('entryTime', 'attendance_logs_entry_index'));
        Schema::table('notifications', fn (Blueprint $table) => $table->index(['userID', 'isRead'], 'notifications_user_read_index'));
        Schema::table('reservations', fn (Blueprint $table) => $table->index(['bookID', 'status'], 'reservations_book_status_index'));
        Schema::table('penalties', fn (Blueprint $table) => $table->index(['studentID', 'paymentStatus'], 'penalties_student_payment_index'));
        Schema::table('books', function (Blueprint $table) {
            $table->index('title', 'books_title_index');
            $table->index(['isNewArrival', 'newArrivalAt'], 'books_new_arrival_index');
        });

        // ---- leftover names from when achievements were called badges ---------------------------------------
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->dropForeign('student_badge_badgeid_foreign');
            $table->dropForeign('student_badge_studentid_foreign');
        });
        DB::statement('ALTER TABLE `achievements` RENAME INDEX `badges_uuid_unique` TO `achievements_uuid_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_badge_uuid_unique` TO `student_achievement_uuid_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_badge_studentid_badgeid_unique` TO `student_achievement_student_achievement_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_badge_badgeid_foreign` TO `student_achievement_achievementid_foreign`');
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->foreign('achievementID', 'student_achievement_achievementid_foreign')->references('achievementID')->on('achievements')->cascadeOnDelete();
            $table->foreign('studentID', 'student_achievement_studentid_foreign')->references('studentID')->on('students')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->dropForeign('student_achievement_achievementid_foreign');
            $table->dropForeign('student_achievement_studentid_foreign');
        });
        DB::statement('ALTER TABLE `achievements` RENAME INDEX `achievements_uuid_unique` TO `badges_uuid_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_achievement_uuid_unique` TO `student_badge_uuid_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_achievement_student_achievement_unique` TO `student_badge_studentid_badgeid_unique`');
        DB::statement('ALTER TABLE `student_achievement` RENAME INDEX `student_achievement_achievementid_foreign` TO `student_badge_badgeid_foreign`');
        Schema::table('student_achievement', function (Blueprint $table) {
            $table->foreign('achievementID', 'student_badge_badgeid_foreign')->references('achievementID')->on('achievements')->cascadeOnDelete();
            $table->foreign('studentID', 'student_badge_studentid_foreign')->references('studentID')->on('students')->cascadeOnDelete();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex('books_title_index');
            $table->dropIndex('books_new_arrival_index');
        });
        Schema::table('penalties', fn (Blueprint $table) => $table->dropIndex('penalties_student_payment_index'));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropIndex('reservations_book_status_index'));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex('notifications_user_read_index'));
        Schema::table('attendance_logs', fn (Blueprint $table) => $table->dropIndex('attendance_logs_entry_index'));
        Schema::table('book_copies', fn (Blueprint $table) => $table->dropIndex('book_copies_book_status_index'));
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex('loans_student_status_index');
            $table->dropIndex('loans_status_due_index');
        });

        Schema::table('resource_usage_logs', function (Blueprint $table) {
            $table->dropUnique('resource_usage_one_open_session_per_resource');
            $table->dropColumn('openFlag');
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_one_open_visit_per_student');
            $table->dropColumn('openFlag');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_one_open_per_student_book');
            $table->dropColumn('openFlag');
        });
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique('loans_one_open_per_copy');
            $table->dropColumn('openFlag');
        });

        $this->cascade('resource_usage_logs', 'studentID', 'students', 'studentID');
        $this->cascade('attendance_logs', 'studentID', 'students', 'studentID');
        $this->cascade('point_redemptions', 'studentID', 'students', 'studentID');
        $this->cascade('penalties', 'copyID', 'book_copies', 'copyID');
        $this->cascade('penalties', 'studentID', 'students', 'studentID');
        $this->cascade('penalties', 'loanID', 'loans', 'loanID');
        $this->cascade('loans', 'copyID', 'book_copies', 'copyID');
        $this->cascade('loans', 'studentID', 'students', 'studentID');

        DB::statement('ALTER TABLE `attendance_logs` MODIFY `entryTime` DATETIME NOT NULL');

        foreach ([
            ['loans', 'status', "VARCHAR(255) NOT NULL DEFAULT 'Active'"],
            ['loans', 'loanType', "VARCHAR(255) NOT NULL DEFAULT 'standard'"],
            ['penalties', 'paymentStatus', "VARCHAR(255) NOT NULL DEFAULT 'Unpaid'"],
            ['self_return_reports', 'verificationStatus', "VARCHAR(255) NOT NULL DEFAULT 'Pending'"],
            ['book_suggestions', 'status', "VARCHAR(255) NOT NULL DEFAULT 'Pending'"],
            ['book_suggestions', 'progressStep', 'VARCHAR(255) NULL'],
            ['point_redemptions', 'fulfillmentStatus', "VARCHAR(255) NOT NULL DEFAULT 'Pending'"],
            ['resources', 'status', "VARCHAR(255) NOT NULL DEFAULT 'Available'"],
            ['librarians', 'role', "VARCHAR(255) NOT NULL DEFAULT 'librarian'"],
            ['loan_periods', 'area', 'VARCHAR(255) NOT NULL'],
        ] as [$table, $column, $definition]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition}");
        }
    }

    private function restrict(string $table, string $column, string $references, string $referencedColumn): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $referencedColumn) {
            $blueprint->dropForeign([$column]);
            $blueprint->foreign($column)->references($referencedColumn)->on($references)->restrictOnDelete();
        });
    }

    private function cascade(string $table, string $column, string $references, string $referencedColumn): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $referencedColumn) {
            $blueprint->dropForeign([$column]);
            $blueprint->foreign($column)->references($referencedColumn)->on($references)->cascadeOnDelete();
        });
    }
};
