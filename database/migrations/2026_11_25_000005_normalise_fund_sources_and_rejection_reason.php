<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * 1. Sources of funds: wording that means "given to the library" ("Donation", "Donated by ...") becomes "Donated",
     *    and the sources already in use on copies (Institutional Fund, Government Subsidy...) join the list so their
     *    cards count correctly. Odd one-off values stay on their copies but don't clutter the list.
     * 2. Copies of books added before now were saved with no source of fund or cost (only the book had them): they
     *    take the book's.
     * 3. A rejected book request keeps the reason the librarian gave, so the student can be told.
     */
    public function up(): void
    {
        foreach (['book_copies', 'books'] as $table) {
            DB::table($table)->whereNotNull('sourceOfFund')
                ->where(fn ($q) => $q->where('sourceOfFund', 'like', '%donat%')->orWhere('sourceOfFund', 'like', '%donor%'))
                ->where('sourceOfFund', '!=', 'Donated')
                ->update(['sourceOfFund' => 'Donated']);
        }

        DB::statement('UPDATE book_copies c JOIN books b ON b.bookID = c.bookID
            SET c.sourceOfFund = b.sourceOfFund WHERE c.sourceOfFund IS NULL AND b.sourceOfFund IS NOT NULL');
        DB::statement('UPDATE book_copies c JOIN books b ON b.bookID = c.bookID
            SET c.cost = b.cost WHERE c.cost IS NULL AND b.cost IS NOT NULL');

        $now = now();
        $next = (int) DB::table('fund_sources')->max('sortOrder');
        $existing = DB::table('fund_sources')->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $inUse = DB::table('book_copies')->whereNotNull('sourceOfFund')->select('sourceOfFund', DB::raw('COUNT(*) AS c'))
            ->groupBy('sourceOfFund')->having('c', '>=', 2)->orderByDesc('c')->pluck('sourceOfFund');

        foreach ($inUse as $name) {
            // A real name: at least a few letters (not "2" or "222").
            if (preg_match('/[A-Za-z]{4,}/', $name) !== 1 || in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            DB::table('fund_sources')->insert(['uuid' => (string) Str::uuid(), 'name' => $name, 'sortOrder' => ++$next, 'created_at' => $now, 'updated_at' => $now]);
            $existing[] = mb_strtolower($name);
        }

        Schema::table('book_suggestions', function (Blueprint $table) {
            $table->string('rejectionReason', 500)->nullable()->after('progressStep');
        });
    }

    public function down(): void
    {
        Schema::table('book_suggestions', function (Blueprint $table) {
            $table->dropColumn('rejectionReason');
        });
    }
};
