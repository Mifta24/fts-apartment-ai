<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'rental_type',
    'apartment_id',
    'unit_type_id',
    'conversation_id',
    'guest_name',
    'guest_email',
    'guest_phone',
    'contact_type',
    'locale',
    'check_in',
    'check_out',
    'lease_months',
    'adults',
    'children',
    'unit_count',
    'extra_bed',
    'total_price',
    'deposit_total',
    'status',
    'notes',
])]
class Booking extends Model
{
    public const RENTAL_DAILY = 'daily';

    public const RENTAL_MONTHLY = 'monthly';

    public const RENTAL_YEARLY = 'yearly';

    /** @var list<string> */
    public const RENTAL_TYPES = [self::RENTAL_DAILY, self::RENTAL_MONTHLY, self::RENTAL_YEARLY];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Which status a booking may move to from its current one. Cancelled is
     * final: its units went back to the inventory and are not held any more.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'extra_bed' => 'boolean',
            'total_price' => 'decimal:2',
            'deposit_total' => 'decimal:2',
            'lease_months' => 'integer',
        ];
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class)->withTrashed();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * A short, unambiguous, non-sequential code guests can quote to staff.
     */
    public static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $reference = 'BK-'.collect(range(1, 6))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isLease(): bool
    {
        return in_array($this->rental_type, [self::RENTAL_MONTHLY, self::RENTAL_YEARLY], true);
    }

    public function nights(): int
    {
        return $this->check_in->diffInDays($this->check_out);
    }
}
