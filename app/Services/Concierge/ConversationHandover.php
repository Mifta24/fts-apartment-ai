<?php

namespace App\Services\Concierge;

use App\Models\Apartment;
use App\Models\Conversation;
use App\Models\HandoverRequest;
use App\Notifications\NewHandoverRequest;

/**
 * Passes a conversation to the apartment team: records why, tells the staff,
 * and stops the concierge from answering until a person resolves it.
 */
class ConversationHandover
{
    public function open(Apartment $apartment, Conversation $conversation, string $reason, string $summary, ?string $guestEmail = null): HandoverRequest
    {
        $handover = HandoverRequest::create([
            'conversation_id' => $conversation->id,
            'reason' => $reason,
            'summary' => $summary,
            'status' => HandoverRequest::STATUS_OPEN,
        ]);

        $apartment->notifyStaff(new NewHandoverRequest($handover));

        $conversation->update([
            'status' => Conversation::STATUS_HANDED_OVER,
            'handover_summary' => $summary,
            ...(filled($guestEmail) ? ['guest_email' => $guestEmail] : []),
        ]);

        return $handover;
    }
}
