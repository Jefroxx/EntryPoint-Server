<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The kinds of reward (Merchandise, Voucher...) become a list the librarians manage, so a type is picked, not typed
     * ("supply", "Supply" and "supplies" can no longer be three types). The types already in use are kept, one per
     * spelling ignoring capitals, and the rewards are pointed at them.
     */
    public function up(): void
    {
        Schema::create('market_item_types', function (Blueprint $table) {
            $table->id('typeID');
            $table->uuid('uuid')->unique();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $seen = [];
        foreach (DB::table('market_items')->orderBy('itemID')->pluck('type') as $existing) {
            $existing = trim((string) $existing);
            $key = mb_strtolower($existing);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $name = $existing === mb_strtolower($existing) ? Str::title($existing) : $existing;
            $seen[$key] = $name;

            DB::table('market_item_types')->insert(['uuid' => (string) Str::uuid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (DB::table('market_items')->whereNotNull('type')->get(['itemID', 'type']) as $item) {
            $name = $seen[mb_strtolower(trim($item->type))] ?? null;
            if ($name !== null && $name !== $item->type) {
                DB::table('market_items')->where('itemID', $item->itemID)->update(['type' => $name]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_item_types');
    }
};
