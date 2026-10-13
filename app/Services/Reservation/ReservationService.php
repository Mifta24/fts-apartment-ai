<?php

namespace App\Services\Reservation;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Notifications\BookingRequestReceived;
use App\Notifications\BookingStatusChanged;
use App\Notifications\NewBookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The single place where stay prices, availability and reservation requests
 * are computed. Both the AI concierge tools and the guest-facing reservation
 * wizard go through here, so they can never disagree.
 */
class ReservationService
{
    /** Apartments take long stays, so a request may cover up to about three months. */
    public const MAX_NIGHTS = 90;

    /** What a guest's phone or WhatsApp number may look like. */
    public const PHONE_PATTERN = '/^\+?[0-9\s\-().]{6,20}$/';

    /**
     * Whether the requested party fits the unit type across the requested units.
     */
    public function fitsOccupancy(UnitType $unitType, int $adults, int $children, int $units): bool
    {
        return $adults <= $unitType->max_adults * $units
            && ($adults + $children) <= $unitType->maxOccupancy() * $units;
    }

    /**
     * Prices a stay across every night in range, then takes off the
     * apartment's weekly or monthly long-stay discount. Returns null if any
     * night lacks enough free units — this is the one place price and
     * availability truth comes from, never the model.
     *
     * @return array{nights: int, nightly: list<array{date: string, price: float}>, unit_total: float, extra_bed_total: float, subtotal: float, discount_percent: int, discount_total: float, grand_total: float, min_available_units: int}|null
     */
    public function quote(
        UnitType $unitType,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        int $units = 1,
        bool $extraBed = false,
        bool $lockForUpdate = false,
    ): ?array {
        $nights = $checkIn->diffInDays($checkOut);

        $inventory = $this->openNights($unitType, $checkIn, $checkOut, $units, $lockForUpdate);

        if (! $inventory) {
            return null;
        }

        $nightly = [];
        $perUnitTotal = 0.0;

        foreach ($inventory as $night) {
            $nightly[] = ['date' => $night->stay_date->toDateString(), 'price' => (float) $night->price];
            $perUnitTotal += (float) $night->price;
        }

        $minAvailable = $inventory->min(fn (UnitInventory $night) => $night->availableUnits());

        $unitTotal = $perUnitTotal * $units;
        $extraBedTotal = $extraBed && $unitType->extra_bed_available
            ? (float) $unitType->extra_bed_price * $nights * $units
            : 0.0;

        $subtotal = $unitTotal + $extraBedTotal;
        $discountPercent = $unitType->apartment->longStayDiscountPercent($nights);
        $discountTotal = round($subtotal * $discountPercent / 100, 2);

        return [
            'nights' => $nights,
            'nightly' => $nightly,
            'unit_total' => $unitTotal,
            'extra_bed_total' => $extraBedTotal,
            'subtotal' => $subtotal,
            'discount_percent' => $discountPercent,
            'discount_total' => $discountTotal,
            'grand_total' => $subtotal - $discountTotal,
            'min_available_units' => $minAvailable,
        ];
    }

    /**
     * Every night of the stay, when each of them still has enough free units.
     * Null when a night is missing from the inventory or fully booked — the
     * one availability rule that nightly stays and long leases share.
     *
     * @return Collection<int, UnitInventory>|null
     */
    private function openNights(UnitType $unitType, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $units, bool $lockForUpdate): ?Collection
    {
        $nights = $checkIn->diffInDays($checkOut);

        if ($nights < 1) {
            return null;
        }

        $query = UnitInventory::where('unit_type_id', $unitType->id)
            ->whereDate('stay_date', '>=', $checkIn->toDateString())
            ->whereDate('stay_date', '<=', $checkOut->subDay()->toDateString())
            ->orderBy('stay_date');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $inventory = $query->get();

        if ($inventory->count() < $nights || $inventory->contains(fn (UnitInventory $night) => $night->availableUnits() < $units)) {
            return null;
        }

        return $inventory;
    }

    /**
     * Whether a lease of this length is one the apartment offers: a monthly
     * rental runs from its shortest lease up to eleven months, a yearly rental
     * covers whole years up to three.
     */
    public function isValidLeaseTerm(Apartment $apartment, string $rentalType, int $months): bool
    {
        return match ($rentalType) {
            Booking::RENTAL_MONTHLY => $months >= max(1, $apartment->min_monthly_lease_months) && $months <= Apartment::MAX_MONTHLY_LEASE_MONTHS,
            Booking::RENTAL_YEARLY => $months >= 12 && $months % 12 === 0 && $months <= 12 * Apartment::MAX_YEARLY_LEASE_YEARS,
            default => false,
        };
    }

    /**
     * The day a lease that starts on the move-in date ends.
     */
    public function leaseEnd(CarbonImmutable $moveIn, int $months): CarbonImmutable
    {
        return $moveIn->addMonthsNoOverflow($months);
    }

    /**
     * Prices a monthly or yearly rental: the unit's own monthly or yearly rent
     * for the whole term, plus the refundable security deposit. The unit is
     * held every night of the lease, so a long lease can never overlap a
     * nightly guest. Returns null when the unit is not rented that way, the
     * term is not one the apartment offers, or any night is already taken.
     *
     * @return array{rental_type: string, lease_months: int, check_in: string, check_out: string, nights: int, rate: float, rate_period: string, monthly_rate: float, unit_total: float, extra_bed_total: float, subtotal: float, discount_percent: int, discount_total: float, grand_total: float, deposit_months: int, deposit_total: float, min_available_units: int}|null
     */
    public function quoteLease(
        UnitType $unitType,
        string $rentalType,
        CarbonImmutable $moveIn,
        int $months,
        int $units = 1,
        bool $lockForUpdate = false,
    ): ?array {
        $apartment = $unitType->apartment;

        if (! $unitType->offersRental($rentalType) || ! $this->isValidLeaseTerm($apartment, $rentalType, $months)) {
            return null;
        }

        $moveOut = $this->leaseEnd($moveIn, $months);
        $inventory = $this->openNights($unitType, $moveIn, $moveOut, $units, $lockForUpdate);

        if (! $inventory) {
            return null;
        }

        $yearly = $rentalType === Booking::RENTAL_YEARLY;
        $rate = (float) ($yearly ? $unitType->yearly_price : $unitType->monthly_price);
        $monthlyRate = $yearly ? (float) $unitType->yearlyMonthlyEquivalent() : $rate;
        $unitTotal = round($yearly ? $rate * ($months / 12) * $units : $rate * $months * $units, 2);
        $deposit = round($monthlyRate * $apartment->security_deposit_months * $units, 2);

        return [
            'rental_type' => $rentalType,
            'lease_months' => $months,
            'check_in' => $moveIn->toDateString(),
            'check_out' => $moveOut->toDateString(),
            'nights' => (int) $moveIn->diffInDays($moveOut),
            'rate' => $rate,
            'rate_period' => $yearly ? 'year' : 'month',
            'monthly_rate' => $monthlyRate,
            'unit_total' => $unitTotal,
            'extra_bed_total' => 0.0,
            'subtotal' => $unitTotal,
            'discount_percent' => 0,
            'discount_total' => 0.0,
            'grand_total' => $unitTotal,
            'deposit_months' => $apartment->security_deposit_months,
            'deposit_total' => $deposit,
            'min_available_units' => $inventory->min(fn (UnitInventory $night) => $night->availableUnits()),
        ];
    }

    /**
     * Creates a pending reservation request and holds the inventory for it.
     * Returns null when the unit is no longer available for those nights.
     *
     * @param  array{rental_type?: string, lease_months?: ?int, check_in: CarbonImmutable, check_out: CarbonImmutable, adults: int, children?: int, units?: int, extra_bed?: bool, guest_name: string, guest_email?: ?string, guest_phone?: ?string, contact_type?: ?string, locale?: ?string, notes?: ?string}  $data
     */
    public function createRequest(Apartment $apartment, UnitType $unitType, array $data, ?Conversation $conversation = null): ?Booking
    {
        $units = $data['units'] ?? 1;
        $rentalType = $data['rental_type'] ?? Booking::RENTAL_DAILY;
        $leaseMonths = $rentalType === Booking::RENTAL_DAILY ? null : (int) ($data['lease_months'] ?? 0);
        $extraBed = $rentalType === Booking::RENTAL_DAILY && (bool) ($data['extra_bed'] ?? false);

        $booking = DB::transaction(function () use ($apartment, $unitType, $data, $conversation, $units, $extraBed, $rentalType, $leaseMonths) {
            $quote = $rentalType === Booking::RENTAL_DAILY
                ? $this->quote($unitType, $data['check_in'], $data['check_out'], $units, $extraBed, lockForUpdate: true)
                : $this->quoteLease($unitType, $rentalType, $data['check_in'], $leaseMonths, $units, lockForUpdate: true);

            if (! $quote) {
                return null;
            }

            $checkOut = $rentalType === Booking::RENTAL_DAILY ? $data['check_out'] : $this->leaseEnd($data['check_in'], $leaseMonths);

            $booking = Booking::create([
                'reference' => Booking::generateReference(),
                'rental_type' => $rentalType,
                'apartment_id' => $apartment->id,
                'unit_type_id' => $unitType->id,
                'conversation_id' => $conversation?->id,
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'] ?? null,
                'guest_phone' => $data['guest_phone'] ?? null,
                'contact_type' => $data['contact_type'] ?? null,
                'locale' => $data['locale'] ?? null,
                'check_in' => $data['check_in']->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'lease_months' => $leaseMonths,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'unit_count' => $units,
                'extra_bed' => $extraBed && $unitType->extra_bed_available,
                'total_price' => $quote['grand_total'],
                'deposit_total' => $quote['deposit_total'] ?? null,
                'status' => Booking::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->adjustInventory($booking, +1);

            return $booking;
        });

        if ($booking) {
            $apartment->notifyStaff(new NewBookingRequest($booking));
            $this->notifyGuest($booking, new BookingRequestReceived($booking));
        }

        return $booking;
    }

    /**
     * Confirms or cancels a booking. Returns false when its current status
     * does not allow the change, so a cancelled booking can never come back
     * without its units being held again. Cancelling gives the units back.
     */
    public function changeStatus(Booking $booking, string $status): bool
    {
        $changed = DB::transaction(function () use ($booking, $status) {
            $current = Booking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->canTransitionTo($status)) {
                return false;
            }

            if ($status === Booking::STATUS_CANCELLED) {
                $this->releaseInventory($current);
            }

            $current->update(['status' => $status]);

            return true;
        });

        $booking->refresh();

        if ($changed) {
            $this->notifyGuest($booking, new BookingStatusChanged($booking));
        }

        return $changed;
    }

    /**
     * Guests who left an email address get a message; those who chose
     * WhatsApp or phone are answered by the team instead.
     */
    private function notifyGuest(Booking $booking, Notification $notification): void
    {
        if (filled($booking->guest_email)) {
            NotificationFacade::route('mail', $booking->guest_email)->notify($notification->locale($booking->locale));
        }
    }

    /**
     * Gives the units held by a booking back to the inventory.
     */
    public function releaseInventory(Booking $booking): void
    {
        $this->adjustInventory($booking, -1);
    }

    private function adjustInventory(Booking $booking, int $direction): void
    {
        UnitInventory::where('unit_type_id', $booking->unit_type_id)
            ->whereDate('stay_date', '>=', $booking->check_in->toDateString())
            ->whereDate('stay_date', '<=', $booking->check_out->copy()->subDay()->toDateString())
            ->{$direction > 0 ? 'increment' : 'decrement'}('booked_units', $booking->unit_count);
    }
}
