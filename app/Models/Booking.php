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
    'preferred_unit_id',
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
    'total_price',
    'deposit_total',
    'status',
    'notes',
])]
class Booking extends Model
{
    /** Nightly stays are no longer offered; only rows from before that change carry this type. */
    public const RENTAL_DAILY = 'daily';

    public const RENTAL_MONTHLY = 'monthly';

    public const RENTAL_YEARLY = 'yearly';

    /** @var list<string> The ways a new rental application can be made. */
    public const RENTAL_TYPES = [self::RENTAL_MONTHLY, self::RENTAL_YEARLY];

    /** Submitted by the applicant, waiting for the team. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DEPOSIT_PAID = 'deposit_paid';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> Statuses in which the unit is held for the applicant. */
    public const HOLDING_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DEPOSIT_PAID, self::STATUS_ACTIVE];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DEPOSIT_PAID, self::STATUS_ACTIVE,
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /**
     * Which status an application may move to from its current one. Rejected,
     * cancelled and completed are final: the unit is no longer held for it.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_DEPOSIT_PAID, self::STATUS_CANCELLED],
        self::STATUS_DEPOSIT_PAID => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
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

    public function preferredUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'preferred_unit_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * A short, unambiguous, non-sequential code applicants can quote to staff.
     */
    public static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $reference = 'AP-'.collect(range(1, 6))
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

    /**
     * The status as the team reads it.
     */
    public static function statusName(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DEPOSIT_PAID => 'Deposit paid',
            self::STATUS_ACTIVE => 'Active lease',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($status),
        };
    }

    public function statusLabel(): string
    {
        return self::statusName($this->status);
    }

    /**
     * The statuses the team may move this application to next.
     *
     * @return list<string>
     */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function holdsUnit(): bool
    {
        return in_array($this->status, self::HOLDING_STATUSES, true);
    }
}
