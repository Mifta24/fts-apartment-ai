<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'apartment_id',
    'guest_token',
    'guest_name',
    'guest_email',
    'locale',
    'status',
    'handover_summary',
    'current_scene',
    'selected_unit_type_id',
    'selected_facility_id',
    'reservation_state',
    'last_message_at',
])]
class Conversation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HANDED_OVER = 'handed_over';

    public const STATUS_CLOSED = 'closed';

    /**
     * The UI scenes the guest can be in while talking to the concierge.
     */
    public const SCENES = ['lobby', 'reception', 'units', 'unit_detail', 'facilities', 'facility_detail', 'reservation', 'handover'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'reservation_state' => 'array',
        ];
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function selectedUnitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'selected_unit_type_id');
    }

    public function selectedFacility(): BelongsTo
    {
        return $this->belongsTo(ApartmentKnowledgeItem::class, 'selected_facility_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function handoverRequest(): HasOne
    {
        return $this->hasOne(HandoverRequest::class)->latestOfMany();
    }

    /**
     * Where to reach a guest who left the chat: the address they gave the
     * concierge, or failing that the one on their latest rental application.
     */
    public function guestEmail(): ?string
    {
        return filled($this->guest_email)
            ? $this->guest_email
            : $this->bookings()->whereNotNull('guest_email')->latest('id')->value('guest_email');
    }

    public function isHandedOver(): bool
    {
        return $this->status === self::STATUS_HANDED_OVER;
    }
}
