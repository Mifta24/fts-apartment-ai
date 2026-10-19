<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a guest a copy of their chat with the concierge, on request.
 */
class ChatTranscript extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Conversation $conversation)
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
        $names = $copy['names'];

        $mail = (new MailMessage)
            ->subject(sprintf($copy['subject'], $apartment->name))
            ->greeting($copy['greeting'])
            ->line(sprintf($copy['intro'], $apartment->name));

        $this->conversation->messages()
            ->whereIn('role', [ConversationMessage::ROLE_GUEST, ConversationMessage::ROLE_ASSISTANT, ConversationMessage::ROLE_STAFF])
            ->whereNotNull('content')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (ConversationMessage $message) use ($mail, $names) {
                $mail->line('**'.$names[$message->role].':** '.str_replace("\n", ' ', $message->content));
            });

        return $mail
            ->action($copy['action'], $apartment->publicUrl().'?lang='.$this->conversation->locale)
            ->salutation($apartment->name);
    }

    /**
     * @return array<string, array{subject: string, greeting: string, intro: string, action: string, names: array<string, string>}>
     */
    private function copy(): array
    {
        return [
            'en' => [
                'subject' => 'Your chat with %s',
                'greeting' => 'Hello,',
                'intro' => 'Here is a copy of your conversation with the %s concierge:',
                'action' => 'Open the apartment page',
                'names' => ['guest' => 'You', 'assistant' => 'Concierge', 'staff' => 'Team'],
            ],
            'ja' => [
                'subject' => '%s とのチャット履歴',
                'greeting' => 'こんにちは。',
                'intro' => '%s のコンシェルジュとの会話のコピーです。',
                'action' => '物件ページを開く',
                'names' => ['guest' => 'あなた', 'assistant' => 'コンシェルジュ', 'staff' => 'スタッフ'],
            ],
            'id' => [
                'subject' => 'Chat Anda dengan %s',
                'greeting' => 'Halo,',
                'intro' => 'Berikut salinan percakapan Anda dengan concierge %s:',
                'action' => 'Buka halaman apartemen',
                'names' => ['guest' => 'Anda', 'assistant' => 'Concierge', 'staff' => 'Tim'],
            ],
        ];
    }
}
