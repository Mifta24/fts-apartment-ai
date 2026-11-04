<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A rental application is approved by the team before the deposit is paid
 * and the lease starts, so the old "confirmed" status becomes "approved".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')->where('status', 'confirmed')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        DB::table('bookings')->whereIn('status', ['approved', 'deposit_paid', 'active', 'completed'])->update(['status' => 'confirmed']);
        DB::table('bookings')->where('status', 'rejected')->update(['status' => 'cancelled']);
    }
};
