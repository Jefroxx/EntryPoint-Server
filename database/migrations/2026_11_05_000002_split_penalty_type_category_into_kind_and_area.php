<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AREAS = ['circulation', 'reserved', 'filipiniana', 'fiction', 'thesis', 'journal', 'dissertation'];

    /**
     * `category` held two different things in one column: a library area ("circulation", for a late fee, whose rate
     * depends on the area) and a kind of fault ("lost", "damaged"). The code then had to guess which one it was
     * looking at. They are now two columns: `kind` says what the charge is for, and `areaOfLibrary` says which area's
     * fine rule applies (only for an overdue fee).
     */
    public function up(): void
    {
        Schema::table('penalty_types', function (Blueprint $table) {
            $table->enum('kind', ['overdue', 'lost', 'damaged'])->default('overdue')->after('uuid');
            $table->enum('areaOfLibrary', self::AREAS)->nullable()->after('kind');
        });

        DB::table('penalty_types')->whereIn('category', ['lost', 'damaged'])->update(['kind' => DB::raw('`category`')]);
        DB::table('penalty_types')->whereNotIn('category', ['lost', 'damaged'])->update(['kind' => 'overdue', 'areaOfLibrary' => DB::raw('`category`')]);

        Schema::table('penalty_types', function (Blueprint $table) {
            $table->dropColumn('category');
            $table->unique(['kind', 'areaOfLibrary'], 'penalty_types_kind_area_unique');
        });
    }

    public function down(): void
    {
        Schema::table('penalty_types', function (Blueprint $table) {
            $table->dropUnique('penalty_types_kind_area_unique');
            $table->string('category')->default('')->after('uuid');
        });

        DB::table('penalty_types')->update(['category' => DB::raw('COALESCE(`areaOfLibrary`, `kind`)')]);

        Schema::table('penalty_types', function (Blueprint $table) {
            $table->dropColumn(['kind', 'areaOfLibrary']);
        });
    }
};
