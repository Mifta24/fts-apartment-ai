<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the apartment team that a guest asked to rent a unit.
 */
class NewBookingRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['apartment', 'unitType']);
        $contact = $booking->guest_phone ?? $booking->guest_email;

        return (new MailMessage)
            ->subject("New stay request {$booking->reference} from {$booking->guest_name}")
            ->greeting("New stay request for {$booking->apartment->name}")
            ->line("{$booking->guest_name} asked for {$booking->unit_count} × {$booking->unitType->name}.")
            ->line("Move-in {$booking->check_in->toFormattedDateString()}, move-out {$booking->check_out->toFormattedDateString()} (".($booking->isLease() ? "{$booking->rental_type} rental, {$booking->lease_months} months" : "{$booking->nights()} nights").').')
            ->line("Residents: {$booking->adults} adults".($booking->children ? ", {$booking->children} children" : '').'.')
            ->line("Total: {$booking->apartment->currency} ".number_format((float) $booking->total_price, 0, ',', '.').'.')
            ->when($booking->deposit_total, fn (MailMessage $mail) => $mail->line("Security deposit: {$booking->apartment->currency} ".number_format((float) $booking->deposit_total, 0, ',', '.').'.'))
            ->line("Contact ({$booking->contact_type}): {$contact}")
            ->when($booking->notes, fn (MailMessage $mail) => $mail->line("Note: {$booking->notes}"))
            ->action('Review the request', route('admin.bookings.index', ['status' => Booking::STATUS_PENDING]));
    }
}
