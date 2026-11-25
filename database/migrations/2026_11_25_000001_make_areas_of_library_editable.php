<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const OLD_AREAS = "'circulation','reserved','filipiniana','fiction','thesis','journal','dissertation'";

    /**
     * Areas of the library stop being a fixed list in the code and become records the librarian manages
     * (Library page, Categories tab): a name, an acronym for the spine label, and the lending rule. The
     * `loan_periods` table already held one row per area with its lending rule, so it grows the rest
     * instead of a second table that would have to be kept in step with it.
     *
     * The four columns that held the area as an enum become plain text for the area's key, so a new area
     * can be used the moment it is added. Sources of funds get their own small table for the same reason.
     */
    public function up(): void
    {
        // ---- the areas ---------------------------------------------------------------------------------
        Schema::table('loan_periods', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('area');
            $table->string('name')->nullable()->after('uuid');
            $table->string('acronym', 12)->nullable()->after('name');
            $table->unsignedSmallInteger('sortOrder')->default(0)->after('periodUnit');
        });

        foreach (['books' => 'areaOfLibrary', 'loans' => 'loanType', 'penalty_types' => 'areaOfLibrary', 'loan_periods' => 'area'] as $table => $column) {
            $null = $table === 'penalty_types' ? 'NULL' : 'NOT NULL';
            // An enum with no default silently used its first value; keep that so a row made without one still lands in Circulation.
            $default = in_array($table, ['books', 'loans'], true) ? " DEFAULT 'circulation'" : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(60) {$null}{$default}");
        }

        // "Thesis" and "Dissertation" are one area now ("Thesis and Dissertation").
        foreach ([['books', 'areaOfLibrary'], ['loans', 'loanType'], ['penalty_types', 'areaOfLibrary']] as [$table, $column]) {
            DB::table($table)->where($column, 'dissertation')->update([$column => 'thesis']);
        }
        DB::table('loan_periods')->where('area', 'dissertation')->delete();

        $known = [
            'circulation' => ['Circulation', 'CIR'],
            'filipiniana' => ['Filipiniana', 'FIL'],
            'reserved'    => ['Reserve', 'REF'],
            'journal'     => ['Journal', 'JOURNAL'],
            'thesis'      => ['Thesis and Dissertation', 'T'],
            'fiction'     => ['Fiction', 'FIC'],
        ];
        $order = 0;
        foreach ($known as $key => [$name, $acronym]) {
            DB::table('loan_periods')->where('area', $key)->update(['uuid' => (string) Str::uuid(), 'name' => $name, 'acronym' => $acronym, 'sortOrder' => ++$order]);
        }

        // New areas start as library use only; the librarian sets a lending period on them if they are lent out.
        $now = now();
        foreach ([['general-reference', 'General Reference', 'GEN REF'], ['dictionary', 'Dictionary', 'DIC'], ['materials', 'Materials', 'MAT']] as [$key, $name, $acronym]) {
            DB::table('loan_periods')->insert([
                'area' => $key, 'uuid' => (string) Str::uuid(), 'name' => $name, 'acronym' => $acronym, 'loanable' => false,
                'periodValue' => null, 'periodUnit' => null, 'sortOrder' => ++$order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('loan_periods', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('acronym', 12)->nullable(false)->change();
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });

        // ---- the sources of funds ----------------------------------------------------------------------
        Schema::create('fund_sources', function (Blueprint $table) {
            $table->id('fundSourceID');
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('sortOrder')->default(0);
            $table->timestamps();
        });
        foreach (['Donated', 'Library budget', 'Student project'] as $i => $name) {
            DB::table('fund_sources')->insert(['uuid' => (string) Str::uuid(), 'name' => $name, 'sortOrder' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_sources');

        DB::table('loan_periods')->whereIn('area', ['general-reference', 'dictionary', 'materials'])->delete();
        DB::table('loan_periods')->insert(['area' => 'dissertation', 'loanable' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('loan_periods', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'name', 'acronym', 'sortOrder']);
        });

        foreach (['books' => 'areaOfLibrary', 'loans' => 'loanType', 'penalty_types' => 'areaOfLibrary', 'loan_periods' => 'area'] as $table => $column) {
            $null = $table === 'penalty_types' ? 'NULL' : 'NOT NULL';
            // An enum with no default silently used its first value; keep that so a row made without one still lands in Circulation.
            $default = in_array($table, ['books', 'loans'], true) ? " DEFAULT 'circulation'" : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM(".self::OLD_AREAS.") {$null}{$default}");
        }
    }
};
