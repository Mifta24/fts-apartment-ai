<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real apartments are rented by the month or the year, not only by the night:
 * each unit type gets its own monthly and yearly rent, the apartment sets its
 * deposit and shortest monthly lease, and a booking remembers which kind of
 * rental it is and for how long.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->decimal('monthly_price', 12, 2)->nullable()->after('base_price');
            $table->decimal('yearly_price', 12, 2)->nullable()->after('monthly_price');
        });

        Schema::table('apartments', function (Blueprint $table) {
            $table->unsignedTinyInteger('security_deposit_months')->default(2)->after('monthly_discount_percent');
            $table->unsignedTinyInteger('min_monthly_lease_months')->default(1)->after('security_deposit_months');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('rental_type', 10)->default('daily')->after('reference'); // daily | monthly | yearly
            $table->unsignedSmallInteger('lease_months')->nullable()->after('check_out');
            $table->decimal('deposit_total', 12, 2)->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['rental_type', 'lease_months', 'deposit_total']);
        });

        Schema::table('apartments', function (Blueprint $table) {
            $table->dropColumn(['security_deposit_months', 'min_monthly_lease_months']);
        });

        Schema::table('unit_types', function (Blueprint $table) {
            $table->dropColumn(['monthly_price', 'yearly_price']);
        });
    }
};
