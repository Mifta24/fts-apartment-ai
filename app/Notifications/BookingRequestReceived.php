<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant their rental application arrived. It is not an approval yet.
 */
class BookingRequestReceived extends Notification implements ShouldQueue
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
        $locale = $booking->locale ?? $booking->apartment->default_locale;
        $copy = $this->copy($locale);
        $summary = GuestBookingSummary::lines($booking, $locale);

        return (new MailMessage)
            ->subject(sprintf($copy['subject'], $booking->reference))
            ->greeting(sprintf($copy['greeting'], $booking->guest_name))
            ->line(sprintf($copy['intro'], $booking->apartment->name))
            ->lines($summary)
            ->line($copy['next'])
            ->salutation($booking->apartment->name);
    }

    /**
     * @return array{subject: string, greeting: string, intro: string, next: string}
     */
    private function copy(string $locale): array
    {
        return [
            'en' => [
                'subject' => 'We received your rental application %s',
                'greeting' => 'Hello %s,',
                'intro' => 'Thank you for applying at %s. Our team will review your application and reply shortly. This is not an approval yet.',
                'next' => 'Keep your reference handy if you contact us.',
            ],
            'ja' => [
                'subject' => '入居申込を受け付けました %s',
                'greeting' => '%s 様',
                'intro' => '%s へのお申込みありがとうございます。スタッフが審査のうえ、まもなくご連絡します。まだ承認ではありません。',
                'next' => 'お問い合わせの際は申込番号をお知らせください。',
            ],
            'id' => [
                'subject' => 'Pengajuan sewa %s kami terima',
                'greeting' => 'Halo %s,',
                'intro' => 'Terima kasih telah mengajukan sewa di %s. Tim kami akan memeriksa pengajuan Anda dan segera membalas. Ini belum merupakan persetujuan.',
                'next' => 'Simpan nomor referensi ini bila Anda menghubungi kami.',
            ],
        ][$locale] ?? $this->copy('en');
    }
}
