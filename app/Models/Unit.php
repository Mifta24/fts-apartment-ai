<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical apartment in the building (e.g. unit 1205), as opposed to a
 * UnitType, which describes a layout. Shown to visitors so they can see which
 * units are free; the date-by-date availability engine still works per type.
 */
#[Fillable([
    'apartment_id',
    'unit_type_id',
    'unit_number',
    'floor',
    'facing',
    'status',
    'available_from',
])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_MAINTENANCE = 'maintenance';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_AVAILABLE, self::STATUS_RESERVED, self::STATUS_OCCUPIED, self::STATUS_MAINTENANCE];

    /** @var list<string> */
    public const FACINGS = ['north', 'east', 'south', 'west'];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'available_from' => 'date',
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

    /**
     * Units a visitor can be told about: free now, or taken but with a known
     * date on which they become free. Maintenance and undated units stay hidden.
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeListable(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('status', self::STATUS_AVAILABLE)
                ->orWhere(function (Builder $query) {
                    $query->whereIn('status', [self::STATUS_RESERVED, self::STATUS_OCCUPIED])->whereNotNull('available_from');
                });
        });
    }

    /**
     * The first day the unit can be moved into, or null when it is free now.
     */
    public function freeFrom(CarbonInterface $today): ?CarbonInterface
    {
        if ($this->status === self::STATUS_AVAILABLE) {
            return null;
        }

        return $this->available_from !== null && $this->available_from->greaterThan($today) ? $this->available_from : null;
    }

    public function isFreeNow(CarbonInterface $today): bool
    {
        return $this->status === self::STATUS_AVAILABLE
            || ($this->available_from !== null && $this->available_from->lessThanOrEqualTo($today) && $this->status !== self::STATUS_MAINTENANCE);
    }
}
