<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'role',
    'content',
    'ui_payload',
    'tool_calls',
    'rating',
])]
class ConversationMessage extends Model
{
    public const ROLE_GUEST = 'guest';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_STAFF = 'staff';

    public const ROLE_SYSTEM = 'system';

    public const RATING_UP = 1;

    public const RATING_DOWN = -1;

    protected function casts(): array
    {
        return [
            'ui_payload' => 'array',
            'tool_calls' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
