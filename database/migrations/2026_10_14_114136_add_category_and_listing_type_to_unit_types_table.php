<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit is no longer always a home to rent: it can also be an office or a
 * commercial space, and it can be for sale instead of (or as well as) for rent.
 * Existing units stay residential rentals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->string('category', 20)->default('residential')->after('slug');
            $table->string('listing_type', 20)->default('rent')->after('category');
            $table->decimal('sale_price', 15, 2)->nullable()->after('yearly_price');
        });
    }

    public function down(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->dropColumn(['category', 'listing_type', 'sale_price']);
        });
    }
};
