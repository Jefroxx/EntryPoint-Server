<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A lost or damaged book is charged as its own kind of penalty. They are penalty types without fine rules: the
     * amount is the book's price (or what the librarian sets), not a rate times the days late.
     */
    public function up(): void
    {
        foreach (['lost', 'damaged'] as $category) {
            if (! DB::table('penalty_types')->where('category', $category)->exists()) {
                DB::table('penalty_types')->insert([
                    'uuid'       => (string) Str::uuid(),
                    'category'   => $category,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only the types nothing refers to can go.
        DB::table('penalty_types')
            ->whereIn('category', ['lost', 'damaged'])
            ->whereNotIn('penaltyTypeID', DB::table('penalties')->select('penaltyTypeID'))
            ->delete();
    }
};
