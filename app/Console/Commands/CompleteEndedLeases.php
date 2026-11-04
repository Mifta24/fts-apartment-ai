<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Reservation\ReservationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:complete-ended-leases')]
#[Description('Mark active leases as completed once their end date has passed.')]
class CompleteEndedLeases extends Command
{
    public function handle(ReservationService $reservations): int
    {
        $completed = 0;

        Booking::with('apartment')
            ->where('status', Booking::STATUS_ACTIVE)
            ->get()
            ->each(function (Booking $booking) use ($reservations, &$completed) {
                $today = now($booking->apartment->timezone)->toDateString();

                if ($booking->check_out->toDateString() <= $today && $reservations->changeStatus($booking, Booking::STATUS_COMPLETED)) {
                    $completed++;
                }
            });

        $this->info("Completed {$completed} ended lease(s).");

        return self::SUCCESS;
    }
}
