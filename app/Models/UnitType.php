<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'apartment_id',
    'name',
    'slug',
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
    'breakfast_included',
    'extra_bed_available',
    'extra_bed_price',
    'base_price',
    'monthly_price',
    'yearly_price',
    'amenities',
    'is_active',
    'sort_order',
])]
class UnitType extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'bed_config' => 'array',
            'amenities' => 'array',
            'breakfast_included' => 'boolean',
            'extra_bed_available' => 'boolean',
            'extra_bed_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
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

    public function inventory(): HasMany
    {
        return $this->hasMany(UnitInventory::class);
    }

    public function offersMonthlyRental(): bool
    {
        return $this->monthly_price !== null && (float) $this->monthly_price > 0;
    }

    public function offersYearlyRental(): bool
    {
        return $this->yearly_price !== null && (float) $this->yearly_price > 0;
    }

    /**
     * Whether this unit can be rented in the given way. Nightly rental is
     * always possible; monthly and yearly need a rent set by the apartment.
     */
    public function offersRental(string $rentalType): bool
    {
        return match ($rentalType) {
            Booking::RENTAL_DAILY => true,
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
