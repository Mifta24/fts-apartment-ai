<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'reason',
    'summary',
    'status',
    'assigned_to',
    'resolved_at',
])]
class HandoverRequest extends Model
{
    public const REASON_SPECIAL_REQUEST = 'special_request';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_GROUP_BOOKING = 'group_booking';

    public const REASON_NEGOTIATED_RATE = 'negotiated_rate';

    public const REASON_UNUSUAL_CANCELLATION = 'unusual_cancellation';

    public const REASON_PAYMENT_ISSUE = 'payment_issue';

    public const REASON_LOW_CONFIDENCE = 'low_confidence';

    /** The model was down, so the guest left a message for the team instead. */
    public const REASON_AI_UNAVAILABLE = 'ai_unavailable';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
