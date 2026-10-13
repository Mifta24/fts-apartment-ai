<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Notifications\BookingStatusChanged;
use App\Services\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingStatusTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $unitType;

    private User $staff;

    private CarbonImmutable $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $this->unitType = $this->apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 500000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->staff = User::factory()->create();
        $this->apartment->users()->attach($this->staff->id, ['role' => 'owner', 'status' => 'active']);

        $this->checkIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        foreach ([0, 1] as $offset) {
            UnitInventory::create(['unit_type_id' => $this->unitType->id, 'stay_date' => $this->checkIn->addDays($offset)->toDateString(), 'total_units' => 2, 'booked_units' => 0, 'price' => 500000]);
        }
    }

    private function holdUnit(): Booking
    {
        return app(ReservationService::class)->createRequest($this->apartment, $this->unitType, [
            'check_in' => $this->checkIn, 'check_out' => $this->checkIn->addDays(2), 'adults' => 2,
            'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222', 'contact_type' => 'whatsapp',
        ]);
    }

    /**
     * @return list<int>
     */
    private function bookedUnits(): array
    {
        return UnitInventory::orderBy('stay_date')->pluck('booked_units')->all();
    }

    private function setStatus(Booking $booking, string $status)
    {
        return $this->actingAs($this->staff)->patch(route('admin.bookings.status', $booking), ['status' => $status]);
    }

    public function test_confirming_a_pending_booking_keeps_its_units_held(): void
    {
        $booking = $this->holdUnit();

        $this->setStatus($booking, Booking::STATUS_CONFIRMED)->assertRedirect()->assertSessionHas('status');

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertSame([1, 1], $this->bookedUnits());
    }

    public function test_cancelling_a_confirmed_booking_gives_its_units_back(): void
    {
        $booking = $this->holdUnit();
        $this->setStatus($booking, Booking::STATUS_CONFIRMED);

        $this->setStatus($booking, Booking::STATUS_CANCELLED)->assertRedirect()->assertSessionHas('status');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame([0, 0], $this->bookedUnits());
    }

    public function test_a_cancelled_booking_cannot_be_confirmed_again(): void
    {
        $booking = $this->holdUnit();
        $this->setStatus($booking, Booking::STATUS_CANCELLED);

        $this->setStatus($booking, Booking::STATUS_CONFIRMED)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame([0, 0], $this->bookedUnits());
    }

    public function test_cancelling_twice_releases_the_units_only_once(): void
    {
        $first = $this->holdUnit();
        $this->holdUnit();

        $this->setStatus($first, Booking::STATUS_CANCELLED);
        $this->setStatus($first, Booking::STATUS_CANCELLED)->assertSessionHas('error');

        $this->assertSame([1, 1], $this->bookedUnits());
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $booking = $this->holdUnit();

        $this->setStatus($booking, Booking::STATUS_PENDING)->assertSessionHasErrors('status');

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_the_booking_list_offers_only_the_moves_a_booking_can_make(): void
    {
        $booking = $this->holdUnit();
        $this->setStatus($booking, Booking::STATUS_CONFIRMED);

        $this->actingAs($this->staff)->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertDontSee('>Confirm</button>', false)
            ->assertSee('>Cancel</button>', false);
    }

    public function test_a_unit_type_with_open_bookings_cannot_be_deleted(): void
    {
        $this->holdUnit();

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->unitType);
    }

    public function test_a_unit_type_whose_bookings_are_cancelled_can_be_deleted_and_the_booking_list_still_works(): void
    {
        $booking = $this->holdUnit();
        $this->setStatus($booking, Booking::STATUS_CANCELLED);

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('status');

        $this->assertSoftDeleted($this->unitType);
        $this->actingAs($this->staff)->get(route('admin.bookings.index'))->assertOk()->assertSee($booking->reference)->assertSee('Studio');
    }

    public function test_a_unit_type_can_be_created_again_under_the_name_of_a_deleted_one(): void
    {
        $this->unitType->delete();

        $this->actingAs($this->staff)->post(route('admin.unit-types.store'), [
            'name' => 'Studio', 'bedrooms' => 0, 'bathrooms' => 1, 'max_adults' => 2, 'max_children' => 0, 'base_price' => 500000, 'is_active' => 1,
        ])->assertRedirect(route('admin.unit-types.index'));

        $this->assertSame('studio-1', $this->apartment->unitTypes()->latest('id')->value('slug'));
    }

    public function test_a_unit_type_whose_open_bookings_ended_in_the_past_can_be_deleted(): void
    {
        $booking = $this->holdUnit();
        $booking->update(['check_in' => now()->subDays(10), 'check_out' => now()->subDays(8)]);

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('status');

        $this->assertSoftDeleted($this->unitType);
    }

    public function test_guests_with_an_email_are_told_when_their_booking_is_confirmed_or_cancelled(): void
    {
        Notification::fake();
        $booking = $this->holdUnit();
        $booking->update(['guest_email' => 'ayu@example.test']);

        $this->setStatus($booking, Booking::STATUS_CONFIRMED);
        $this->setStatus($booking, Booking::STATUS_CANCELLED);
        $this->setStatus($booking, Booking::STATUS_CONFIRMED);

        Notification::assertSentOnDemandTimes(BookingStatusChanged::class, 2);
    }
}
