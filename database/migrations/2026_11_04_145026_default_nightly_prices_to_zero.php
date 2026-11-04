<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly rates are no longer set anywhere: units are rented monthly or
 * yearly. The old columns stay for existing rows but may now be left at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->decimal('base_price', 12, 2)->default(0)->change();
        });

        Schema::table('unit_inventory', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->decimal('base_price', 12, 2)->change();
        });

        Schema::table('unit_inventory', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->change();
        });
    }
};
