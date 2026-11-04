<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant where their rental application stands: approved, deposit received, rejected or cancelled.
 */
class BookingStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> The statuses the applicant is emailed about; starting and ending a lease are not. */
    public const NOTIFIED_STATUSES = [
        Booking::STATUS_APPROVED,
        Booking::STATUS_DEPOSIT_PAID,
        Booking::STATUS_REJECTED,
        Booking::STATUS_CANCELLED,
    ];

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
        $copy = $this->copy($locale)[$booking->status];

        return (new MailMessage)
            ->subject(sprintf($copy['subject'], $booking->reference))
            ->greeting(sprintf($copy['greeting'], $booking->guest_name))
            ->line(sprintf($copy['intro'], $booking->apartment->name))
            ->lines(GuestBookingSummary::lines($booking, $locale))
            ->line($copy['next'])
            ->salutation($booking->apartment->name);
    }

    /**
     * @return array<string, array<string, array{subject: string, greeting: string, intro: string, next: string}>>
     */
    private function copy(string $locale): array
    {
        return [
            'en' => [
                Booking::STATUS_APPROVED => [
                    'subject' => 'Your rental application %s is approved',
                    'greeting' => 'Hello %s,',
                    'intro' => 'Good news: %s approved your rental application.',
                    'next' => 'Our team will contact you about the security deposit and the lease agreement.',
                ],
                Booking::STATUS_DEPOSIT_PAID => [
                    'subject' => 'Deposit received for %s',
                    'greeting' => 'Hello %s,',
                    'intro' => 'Thank you: %s received your security deposit.',
                    'next' => 'Our team will arrange the lease signing and your move-in.',
                ],
                Booking::STATUS_REJECTED => [
                    'subject' => 'Your rental application %s was not accepted',
                    'greeting' => 'Hello %s,',
                    'intro' => 'We are sorry: %s could not accept this rental application.',
                    'next' => 'Please contact us if you would like another move-in date or another unit.',
                ],
                Booking::STATUS_CANCELLED => [
                    'subject' => 'Your rental application %s was cancelled',
                    'greeting' => 'Hello %s,',
                    'intro' => 'We are sorry: %s could not keep this rental application.',
                    'next' => 'Please contact us if you would like another move-in date or another unit.',
                ],
            ],
            'ja' => [
                Booking::STATUS_APPROVED => [
                    'subject' => '入居申込が承認されました %s',
                    'greeting' => '%s 様',
                    'intro' => '%s が入居申込を承認しました。',
                    'next' => '敷金のお支払いと賃貸借契約について、スタッフよりご連絡します。',
                ],
                Booking::STATUS_DEPOSIT_PAID => [
                    'subject' => '敷金を確認しました %s',
                    'greeting' => '%s 様',
                    'intro' => '%s が敷金のお支払いを確認しました。',
                    'next' => '契約の締結と入居の手配について、スタッフよりご連絡します。',
                ],
                Booking::STATUS_REJECTED => [
                    'subject' => '入居申込をお受けできませんでした %s',
                    'greeting' => '%s 様',
                    'intro' => '申し訳ありません。%s ではこの入居申込をお受けできませんでした。',
                    'next' => '別の入居日やお部屋をご希望の場合はお問い合わせください。',
                ],
                Booking::STATUS_CANCELLED => [
                    'subject' => '入居申込はキャンセルされました %s',
                    'greeting' => '%s 様',
                    'intro' => '申し訳ありません。%s ではこの入居申込を維持できませんでした。',
                    'next' => '別の入居日やお部屋をご希望の場合はお問い合わせください。',
                ],
            ],
            'id' => [
                Booking::STATUS_APPROVED => [
                    'subject' => 'Pengajuan sewa %s disetujui',
                    'greeting' => 'Halo %s,',
                    'intro' => 'Kabar baik: %s telah menyetujui pengajuan sewa Anda.',
                    'next' => 'Tim kami akan menghubungi Anda soal deposit dan perjanjian sewa.',
                ],
                Booking::STATUS_DEPOSIT_PAID => [
                    'subject' => 'Deposit untuk %s sudah kami terima',
                    'greeting' => 'Halo %s,',
                    'intro' => 'Terima kasih: %s telah menerima deposit Anda.',
                    'next' => 'Tim kami akan mengatur penandatanganan kontrak dan jadwal masuk unit.',
                ],
                Booking::STATUS_REJECTED => [
                    'subject' => 'Pengajuan sewa %s belum dapat diterima',
                    'greeting' => 'Halo %s,',
                    'intro' => 'Mohon maaf, %s belum dapat menerima pengajuan sewa ini.',
                    'next' => 'Hubungi kami bila Anda ingin tanggal masuk atau unit lain.',
                ],
                Booking::STATUS_CANCELLED => [
                    'subject' => 'Pengajuan sewa %s dibatalkan',
                    'greeting' => 'Halo %s,',
                    'intro' => 'Mohon maaf, %s tidak dapat melanjutkan pengajuan sewa ini.',
                    'next' => 'Hubungi kami bila Anda ingin tanggal masuk atau unit lain.',
                ],
            ],
        ][$locale] ?? $this->copy('en');
    }
}
