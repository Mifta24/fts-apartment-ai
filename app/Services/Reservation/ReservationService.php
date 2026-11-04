<?php

namespace App\Services\Reservation;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\ViewingRequest;
use App\Notifications\BookingRequestReceived;
use App\Notifications\BookingStatusChanged;
use App\Notifications\NewBookingRequest;
use App\Notifications\NewViewingRequest;
use App\Notifications\ViewingRequestReceived;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The single place where rent, deposit, availability and rental applications
 * are computed. Both the AI concierge tools and the applicant-facing wizard
 * go through here, so they can never disagree.
 */
class ReservationService
{
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
     * Every day of the lease, when each of them still has enough free units.
     * Null when a day is missing from the inventory or fully booked.
     *
     * @return Collection<int, UnitInventory>|null
     */
    private function openDays(UnitType $unitType, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $units, bool $lockForUpdate): ?Collection
    {
        $days = $checkIn->diffInDays($checkOut);

        if ($days < 1) {
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

        if ($inventory->count() < $days || $inventory->contains(fn (UnitInventory $night) => $night->availableUnits() < $units)) {
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
     * held every day of the lease, so two leases can never overlap. Returns
     * null when the unit is not rented that way, the term is not one the
     * apartment offers, or any day is already taken.
     *
     * @return array{rental_type: string, lease_months: int, move_in: string, move_out: string, days: int, rate: float, rate_period: string, monthly_rate: float, grand_total: float, deposit_months: int, deposit_total: float, min_available_units: int}|null
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
        $inventory = $this->openDays($unitType, $moveIn, $moveOut, $units, $lockForUpdate);

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
            'move_in' => $moveIn->toDateString(),
            'move_out' => $moveOut->toDateString(),
            'days' => (int) $moveIn->diffInDays($moveOut),
            'rate' => $rate,
            'rate_period' => $yearly ? 'year' : 'month',
            'monthly_rate' => $monthlyRate,
            'grand_total' => $unitTotal,
            'deposit_months' => $apartment->security_deposit_months,
            'deposit_total' => $deposit,
            'min_available_units' => $inventory->min(fn (UnitInventory $night) => $night->availableUnits()),
        ];
    }

    /**
     * Creates a rental application and holds the unit for it until the team
     * answers. Returns null when the unit is no longer free on those days.
     *
     * @param  array{rental_type: string, lease_months: int, check_in: CarbonImmutable, adults: int, children?: int, units?: int, guest_name: string, guest_email?: ?string, guest_phone?: ?string, contact_type?: ?string, locale?: ?string, notes?: ?string}  $data
     */
    public function createRequest(Apartment $apartment, UnitType $unitType, array $data, ?Conversation $conversation = null): ?Booking
    {
        $units = $data['units'] ?? 1;
        $rentalType = $data['rental_type'];
        $leaseMonths = (int) $data['lease_months'];

        $booking = DB::transaction(function () use ($apartment, $unitType, $data, $conversation, $units, $rentalType, $leaseMonths) {
            $quote = $this->quoteLease($unitType, $rentalType, $data['check_in'], $leaseMonths, $units, lockForUpdate: true);

            if (! $quote) {
                return null;
            }

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
                'check_in' => $quote['move_in'],
                'check_out' => $quote['move_out'],
                'lease_months' => $leaseMonths,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'unit_count' => $units,
                'total_price' => $quote['grand_total'],
                'deposit_total' => $quote['deposit_total'],
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
     * Records a visitor's wish to look at a unit in person. Viewings do not
     * hold the unit; the team picks a time and confirms it with the visitor.
     *
     * @param  array{preferred_date: string, preferred_time: string, visitor_name: string, visitor_email?: ?string, visitor_phone?: ?string, contact_type?: ?string, locale?: ?string, notes?: ?string}  $data
     */
    public function createViewing(Apartment $apartment, UnitType $unitType, array $data, ?Conversation $conversation = null): ViewingRequest
    {
        $viewing = ViewingRequest::create([
            'reference' => ViewingRequest::generateReference(),
            'apartment_id' => $apartment->id,
            'unit_type_id' => $unitType->id,
            'conversation_id' => $conversation?->id,
            'visitor_name' => $data['visitor_name'],
            'visitor_email' => $data['visitor_email'] ?? null,
            'visitor_phone' => $data['visitor_phone'] ?? null,
            'contact_type' => $data['contact_type'] ?? null,
            'locale' => $data['locale'] ?? null,
            'preferred_date' => $data['preferred_date'],
            'preferred_time' => $data['preferred_time'],
            'notes' => $data['notes'] ?? null,
        ]);

        $apartment->notifyStaff(new NewViewingRequest($viewing));

        if (filled($viewing->visitor_email)) {
            NotificationFacade::route('mail', $viewing->visitor_email)->notify((new ViewingRequestReceived($viewing))->locale($viewing->locale));
        }

        return $viewing;
    }

    /**
     * Moves an application along its lifecycle. Returns false when its current
     * status does not allow the change. Rejecting or cancelling gives the unit
     * back; completing a lease early frees the days that have not passed yet.
     */
    public function changeStatus(Booking $booking, string $status): bool
    {
        $changed = DB::transaction(function () use ($booking, $status) {
            $current = Booking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->canTransitionTo($status)) {
                return false;
            }

            if (in_array($status, [Booking::STATUS_REJECTED, Booking::STATUS_CANCELLED], true)) {
                $this->releaseInventory($current);
            }

            if ($status === Booking::STATUS_COMPLETED) {
                $this->releaseInventory($current, from: CarbonImmutable::now($current->apartment->timezone)->startOfDay());
            }

            $current->update(['status' => $status]);

            return true;
        });

        $booking->refresh();

        if ($changed && in_array($status, BookingStatusChanged::NOTIFIED_STATUSES, true)) {
            $this->notifyGuest($booking, new BookingStatusChanged($booking));
        }

        return $changed;
    }

    /**
     * Applicants who left an email address get a message; those who chose
     * WhatsApp or phone are answered by the team instead.
     */
    private function notifyGuest(Booking $booking, Notification $notification): void
    {
        if (filled($booking->guest_email)) {
            NotificationFacade::route('mail', $booking->guest_email)->notify($notification->locale($booking->locale));
        }
    }

    /**
     * Gives the units held by an application back to the inventory, from the
     * given day on (the whole lease by default).
     */
    public function releaseInventory(Booking $booking, ?CarbonImmutable $from = null): void
    {
        $this->adjustInventory($booking, -1, $from);
    }

    private function adjustInventory(Booking $booking, int $direction, ?CarbonImmutable $from = null): void
    {
        $firstDay = $from && $from->greaterThan($booking->check_in) ? $from->toDateString() : $booking->check_in->toDateString();

        UnitInventory::where('unit_type_id', $booking->unit_type_id)
            ->whereDate('stay_date', '>=', $firstDay)
            ->whereDate('stay_date', '<=', $booking->check_out->copy()->subDay()->toDateString())
            ->{$direction > 0 ? 'increment' : 'decrement'}('booked_units', $booking->unit_count);
    }
}
