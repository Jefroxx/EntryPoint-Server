<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A wishlists row used to mean "hearted" even when it only existed because the book was put in
     * the cart. inWishlist separates the two, so a row can be in the cart, hearted, or both.
     * Existing rows default to true so nothing already hearted loses its heart.
     */
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->boolean('inWishlist')->default(true)->after('inCart');
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('inWishlist');
        });
    }
};
