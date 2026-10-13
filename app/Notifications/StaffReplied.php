<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest who left the chat that a team member answered them.
 */
class StaffReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Conversation $conversation, public string $message)
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
        $apartment = $this->conversation->apartment;
        $copy = $this->copy()[$this->conversation->locale] ?? $this->copy()['en'];

        return (new MailMessage)
            ->subject(sprintf($copy['subject'], $apartment->name))
            ->greeting($copy['greeting'])
            ->line(sprintf($copy['intro'], $apartment->name))
            ->line('> '.str_replace("\n", "\n> ", $this->message))
            ->action($copy['action'], $apartment->publicUrl().'?lang='.$this->conversation->locale)
            ->line($copy['next'])
            ->salutation($apartment->name);
    }

    /**
     * @return array<string, array{subject: string, greeting: string, intro: string, action: string, next: string}>
     */
    private function copy(): array
    {
        return [
            'en' => [
                'subject' => '%s replied to your message',
                'greeting' => 'Hello,',
                'intro' => 'A member of the %s team answered you:',
                'action' => 'Continue the chat',
                'next' => 'Open the chat in the same browser to reply.',
            ],
            'ja' => [
                'subject' => '%s からの返信があります',
                'greeting' => 'こんにちは。',
                'intro' => '%s のスタッフより返信がありました。',
                'action' => 'チャットを開く',
                'next' => '同じブラウザでチャットを開くと、そのままご返信いただけます。',
            ],
            'id' => [
                'subject' => '%s membalas pesan Anda',
                'greeting' => 'Halo,',
                'intro' => 'Tim %s menjawab Anda:',
                'action' => 'Lanjutkan chat',
                'next' => 'Buka chat di browser yang sama untuk membalas.',
            ],
        ];
    }
}
