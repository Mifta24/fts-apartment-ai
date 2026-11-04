<?php

namespace App\Notifications;

use App\Models\ViewingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the apartment team that a visitor wants to see a unit in person.
 */
class NewViewingRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ViewingRequest $viewing)
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
        $viewing = $this->viewing->loadMissing(['apartment', 'unitType']);
        $contact = $viewing->visitor_phone ?? $viewing->visitor_email;

        return (new MailMessage)
            ->subject("New viewing request {$viewing->reference} from {$viewing->visitor_name}")
            ->greeting("New viewing request for {$viewing->apartment->name}")
            ->line("{$viewing->visitor_name} would like to see {$viewing->unitType->name}.")
            ->line("Preferred time: {$viewing->preferred_date->toFormattedDateString()}, {$viewing->preferred_time}.")
            ->line("Contact ({$viewing->contact_type}): {$contact}")
            ->when($viewing->notes, fn (MailMessage $mail) => $mail->line("Note: {$viewing->notes}"))
            ->action('Review the request', route('admin.viewings.index', ['status' => ViewingRequest::STATUS_REQUESTED]));
    }
}
