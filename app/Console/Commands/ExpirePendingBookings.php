<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Reservation\ReservationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:expire-pending {--hours= : Cancel requests pending longer than this many hours (default: reservation.pending_expiry_hours)}')]
#[Description('Cancel stay requests nobody answered in time, so they stop holding units.')]
class ExpirePendingBookings extends Command
{
    public function handle(ReservationService $reservations): int
    {
        $hours = $this->option('hours') ?? config('reservation.pending_expiry_hours');

        if (! filled($hours) || (int) $hours < 1) {
            $this->info('Pending requests do not expire (BOOKING_PENDING_EXPIRY_HOURS is not set).');

            return self::SUCCESS;
        }

        $expired = 0;

        Booking::where('status', Booking::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours((int) $hours))
            ->each(function (Booking $booking) use ($reservations, &$expired) {
                if ($reservations->changeStatus($booking, Booking::STATUS_CANCELLED)) {
                    $expired++;
                }
            });

        $this->info("Cancelled {$expired} pending request(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
