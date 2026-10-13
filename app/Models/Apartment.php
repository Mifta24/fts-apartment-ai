<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'slug',
    'custom_domain',
    'description',
    'translations',
    'address',
    'city',
    'country',
    'latitude',
    'longitude',
    'phone',
    'whatsapp',
    'email',
    'timezone',
    'currency',
    'default_locale',
    'check_in_time',
    'check_out_time',
    'weekly_discount_percent',
    'monthly_discount_percent',
    'security_deposit_months',
    'min_monthly_lease_months',
    'logo_path',
    'cover_path',
    'public_status',
])]
class Apartment extends Model
{
    use HasFactory, SoftDeletes;

    /** Stays of at least this many nights get the weekly rate. */
    public const WEEKLY_STAY_NIGHTS = 7;

    /** Stays of at least this many nights get the monthly rate. */
    public const MONTHLY_STAY_NIGHTS = 28;

    /** A monthly rental covers up to this many months; a full year or more is a yearly rental. */
    public const MAX_MONTHLY_LEASE_MONTHS = 11;

    /** A yearly rental covers up to this many years. */
    public const MAX_YEARLY_LEASE_YEARS = 3;

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'weekly_discount_percent' => 'integer',
            'monthly_discount_percent' => 'integer',
            'security_deposit_months' => 'integer',
            'min_monthly_lease_months' => 'integer',
        ];
    }

    /**
     * The long-stay discount a stay of this length earns, in percent.
     */
    public function longStayDiscountPercent(int $nights): int
    {
        return match (true) {
            $nights >= self::MONTHLY_STAY_NIGHTS && $this->monthly_discount_percent > 0 => $this->monthly_discount_percent,
            $nights >= self::WEEKLY_STAY_NIGHTS && $this->weekly_discount_percent > 0 => $this->weekly_discount_percent,
            default => 0,
        };
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'apartment_users')
            ->using(ApartmentUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Sends a notification to every active member of the apartment team.
     */
    public function notifyStaff(Notification $notification): void
    {
        NotificationFacade::send($this->users()->wherePivot('status', 'active')->get(), $notification);
    }

    public function unitTypes(): HasMany
    {
        return $this->hasMany(UnitType::class);
    }

    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(ApartmentKnowledgeItem::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function translatedDescription(string $locale): ?string
    {
        $value = $this->translations[$locale]['description'] ?? null;

        return filled($value) ? $value : $this->description;
    }

    public function isPublished(): bool
    {
        return $this->public_status === 'published';
    }

    public function publicUrl(): string
    {
        return $this->custom_domain
            ? 'https://'.$this->custom_domain
            : url('/'.$this->slug);
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'apartment';
        $slug = $base;
        $suffix = 1;

        while (static::withTrashed()->where('slug', $slug)->exists() || in_array($slug, static::reservedSlugs(), true)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public static function reservedSlugs(): array
    {
        return [
            'admin', 'login', 'logout', 'register', 'pricing', 'dashboard',
            'api', 'terms', 'privacy', 'forgot-password', 'reset-password',
            'verify-email', 'confirm-password', 'storage', 'build', 'account',
        ];
    }
}
