<?php

namespace App\Notifications;

use App\Models\ViewingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a visitor their viewing request arrived. The time is not final until the team confirms it.
 */
class ViewingRequestReceived extends Notification implements ShouldQueue
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
        $locale = $viewing->locale ?? $viewing->apartment->default_locale;
        $copy = $this->copy($locale);
        $when = $viewing->preferred_date->locale($locale)->translatedFormat($locale === 'ja' ? 'Y年n月j日' : 'j F Y').' '.$viewing->preferred_time;

        return (new MailMessage)
            ->subject(sprintf($copy['subject'], $viewing->reference))
            ->greeting(sprintf($copy['greeting'], $viewing->visitor_name))
            ->line(sprintf($copy['intro'], $viewing->apartment->name))
            ->line("{$copy['unit']}: {$viewing->unitType->translatedName($locale)}")
            ->line("{$copy['time']}: {$when}")
            ->line($copy['next'])
            ->salutation($viewing->apartment->name);
    }

    /**
     * @return array{subject: string, greeting: string, intro: string, unit: string, time: string, next: string}
     */
    private function copy(string $locale): array
    {
        return [
            'en' => [
                'subject' => 'We received your viewing request %s',
                'greeting' => 'Hello %s,',
                'intro' => 'Thank you for your interest in %s. Our team will confirm the viewing time shortly.',
                'unit' => 'Unit',
                'time' => 'Preferred time',
                'next' => 'The time is not final until our team confirms it.',
            ],
            'ja' => [
                'subject' => '内見のご希望を受け付けました %s',
                'greeting' => '%s 様',
                'intro' => '%s にご関心をお寄せいただきありがとうございます。スタッフが内見の日時を確認し、ご連絡します。',
                'unit' => 'お部屋',
                'time' => 'ご希望の日時',
                'next' => 'スタッフが確認するまで日時は確定ではありません。',
            ],
            'id' => [
                'subject' => 'Permintaan survei %s kami terima',
                'greeting' => 'Halo %s,',
                'intro' => 'Terima kasih atas minat Anda di %s. Tim kami akan segera mengonfirmasi jadwal survei.',
                'unit' => 'Unit',
                'time' => 'Waktu yang diinginkan',
                'next' => 'Jadwal belum final sebelum dikonfirmasi tim kami.',
            ],
        ][$locale] ?? $this->copy('en');
    }
}
