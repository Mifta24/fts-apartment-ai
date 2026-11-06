<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant rents a particular apartment, not "a room of some type": physical
 * units get a number, a floor and a facing, and say when they become free.
 * Applications may name the unit the applicant prefers. The hotel-only
 * breakfast flag on unit types goes away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apartment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_type_id')->constrained()->cascadeOnDelete();
            $table->string('unit_number', 12);
            $table->unsignedSmallInteger('floor');
            $table->string('facing', 20)->nullable(); // north | east | south | west
            $table->string('status', 20)->default('available'); // available | reserved | occupied | maintenance
            $table->date('available_from')->nullable();
            $table->timestamps();

            $table->unique(['apartment_id', 'unit_number']);
            $table->index(['unit_type_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('preferred_unit_id')->nullable()->after('unit_type_id')->constrained('units')->nullOnDelete();
        });

        Schema::table('unit_types', function (Blueprint $table) {
            $table->dropColumn('breakfast_included');
        });
    }

    public function down(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->boolean('breakfast_included')->default(false);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_unit_id');
        });

        Schema::dropIfExists('units');
    }
};
