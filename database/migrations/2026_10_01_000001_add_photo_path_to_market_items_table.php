<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An optional photo of a rewards market item, on the public disk (see MarketplaceService::setItemPhoto). */
    public function up(): void
    {
        Schema::table('market_items', function (Blueprint $table) {
            $table->string('photoPath')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('market_items', function (Blueprint $table) {
            $table->dropColumn('photoPath');
        });
    }
};
