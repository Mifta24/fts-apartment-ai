<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'apartment_id',
    'name',
    'slug',
    'category',
    'listing_type',
    'description',
    'translations',
    'size_sqm',
    'bedrooms',
    'bathrooms',
    'floor_range',
    'max_adults',
    'max_children',
    'bed_config',
    'view_type',
    'base_price',
    'monthly_price',
    'yearly_price',
    'sale_price',
    'amenities',
    'is_active',
    'sort_order',
])]
class UnitType extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_RESIDENTIAL = 'residential';

    public const CATEGORY_OFFICE = 'office';

    public const CATEGORY_COMMERCIAL = 'commercial';

    /** @var list<string> */
    public const CATEGORIES = [self::CATEGORY_RESIDENTIAL, self::CATEGORY_OFFICE, self::CATEGORY_COMMERCIAL];

    public const LISTING_RENT = 'rent';

    public const LISTING_SALE = 'sale';

    public const LISTING_BOTH = 'both';

    /** @var list<string> */
    public const LISTING_TYPES = [self::LISTING_RENT, self::LISTING_SALE, self::LISTING_BOTH];

    /** @var array<string, string> */
    protected $attributes = [
        'category' => self::CATEGORY_RESIDENTIAL,
        'listing_type' => self::LISTING_RENT,
    ];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'bed_config' => 'array',
            'amenities' => 'array',
            'base_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UnitImage::class)->orderBy('sort_order');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(UnitInventory::class);
    }

    /**
     * Units guests can rent: everything except units that are only for sale.
     *
     * @param  Builder<UnitType>  $query
     */
    public function scopeForRent(Builder $query): void
    {
        $query->whereIn('listing_type', [self::LISTING_RENT, self::LISTING_BOTH]);
    }

    public function isForRent(): bool
    {
        return in_array($this->listing_type, [self::LISTING_RENT, self::LISTING_BOTH], true);
    }

    public function isForSale(): bool
    {
        return in_array($this->listing_type, [self::LISTING_SALE, self::LISTING_BOTH], true);
    }

    public function isResidential(): bool
    {
        return $this->category === self::CATEGORY_RESIDENTIAL;
    }

    /** A unit for sale is shown with its asking price; without one guests are told to ask the team. */
    public function offersSalePrice(): bool
    {
        return $this->isForSale() && $this->sale_price !== null && (float) $this->sale_price > 0;
    }

    public function offersMonthlyRental(): bool
    {
        return $this->isForRent() && $this->monthly_price !== null && (float) $this->monthly_price > 0;
    }

    public function offersYearlyRental(): bool
    {
        return $this->isForRent() && $this->yearly_price !== null && (float) $this->yearly_price > 0;
    }

    /**
     * Whether this unit can be rented in the given way. Every way needs the
     * unit to be for rent and the rate for it to be set by the apartment.
     * Nightly rental is not offered any more.
     */
    public function offersRental(string $rentalType): bool
    {
        return match ($rentalType) {
            Booking::RENTAL_MONTHLY => $this->offersMonthlyRental(),
            Booking::RENTAL_YEARLY => $this->offersYearlyRental(),
            default => false,
        };
    }

    /**
     * What one month costs on a yearly rental, so guests can compare it with
     * the monthly rent. Null when the unit has no yearly rent.
     */
    public function yearlyMonthlyEquivalent(): ?float
    {
        return $this->offersYearlyRental() ? round((float) $this->yearly_price / 12, 2) : null;
    }

    public function isStudio(): bool
    {
        return $this->bedrooms === 0;
    }

    public function maxOccupancy(): int
    {
        return $this->max_adults + $this->max_children;
    }

    public function translatedName(string $locale): string
    {
        $value = $this->translations[$locale]['name'] ?? null;

        return filled($value) ? $value : $this->name;
    }

    public function translatedDescription(string $locale): ?string
    {
        $value = $this->translations[$locale]['description'] ?? null;

        return filled($value) ? $value : $this->description;
    }
}
