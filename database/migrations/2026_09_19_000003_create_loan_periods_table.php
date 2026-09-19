<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves loan periods out of config/loans.php into the database so the
     * Settings page can actually persist changes — a config file can't be
     * safely rewritten by a web request. Seeded from that file's current
     * values so behavior is unchanged until a librarian edits a period.
     */
    public function up(): void
    {
        Schema::create('loan_periods', function (Blueprint $table) {
            $table->string('area')->primary();
            $table->boolean('loanable')->default(true);
            $table->unsignedInteger('periodValue')->nullable();
            $table->string('periodUnit')->nullable();
            $table->timestamps();
        });

        $defaults = config('loans', []);
        $now = now();

        DB::table('loan_periods')->insert(array_map(
            fn (string $area, array $rule) => [
                'area'        => $area,
                'loanable'    => $rule['loanable'],
                'periodValue' => $rule['period_value'],
                'periodUnit'  => $rule['period_unit'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            array_keys($defaults),
            array_values($defaults)
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_periods');
    }
};
