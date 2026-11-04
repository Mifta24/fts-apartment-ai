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

    private CarbonImmutable $moveIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $this->unitType = $this->apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 5000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->staff = User::factory()->create();
        $this->apartment->users()->attach($this->staff->id, ['role' => 'owner', 'status' => 'active']);

        $this->moveIn = CarbonImmutable::now($this->apartment->timezone)->addDays(5)->startOfDay();

        $now = now();
        UnitInventory::insert(collect(range(0, 60))->map(fn (int $offset) => [
            'unit_type_id' => $this->unitType->id, 'stay_date' => $this->moveIn->addDays($offset)->toDateString(),
            'total_units' => 2, 'booked_units' => 0, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    private function apply(): Booking
    {
        return app(ReservationService::class)->createRequest($this->apartment, $this->unitType, [
            'rental_type' => Booking::RENTAL_MONTHLY, 'lease_months' => 1, 'check_in' => $this->moveIn, 'adults' => 2,
            'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222', 'contact_type' => 'whatsapp',
        ]);
    }

    /**
     * How many days of the inventory are held for the unit type.
     */
    private function heldDays(): int
    {
        return UnitInventory::where('booked_units', '>', 0)->count();
    }

    private function setStatus(Booking $booking, string $status)
    {
        return $this->actingAs($this->staff)->patch(route('admin.bookings.status', $booking), ['status' => $status]);
    }

    private function advanceTo(Booking $booking, string ...$statuses): void
    {
        foreach ($statuses as $status) {
            $this->setStatus($booking, $status)->assertSessionHas('status');
        }
    }

    public function test_an_application_moves_from_submitted_through_approval_and_deposit_to_an_active_lease(): void
    {
        $booking = $this->apply();
        $held = $this->heldDays();
        $this->assertGreaterThan(0, $held);

        foreach ([Booking::STATUS_APPROVED, Booking::STATUS_DEPOSIT_PAID, Booking::STATUS_ACTIVE] as $status) {
            $this->setStatus($booking, $status)->assertRedirect()->assertSessionHas('status');
            $this->assertSame($status, $booking->fresh()->status);
            $this->assertSame($held, $this->heldDays(), "The unit stays held while the application is {$status}.");
        }
    }

    public function test_an_application_cannot_skip_a_step_or_go_back(): void
    {
        $booking = $this->apply();

        $this->setStatus($booking, Booking::STATUS_ACTIVE)->assertSessionHas('error');
        $this->setStatus($booking, Booking::STATUS_DEPOSIT_PAID)->assertSessionHas('error');
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);

        $this->advanceTo($booking, Booking::STATUS_APPROVED);
        $this->setStatus($booking, Booking::STATUS_PENDING)->assertSessionHasErrors('status');
        $this->setStatus($booking, Booking::STATUS_REJECTED)->assertSessionHas('error');
        $this->assertSame(Booking::STATUS_APPROVED, $booking->fresh()->status);
    }

    public function test_rejecting_a_submitted_application_gives_its_units_back(): void
    {
        $booking = $this->apply();

        $this->setStatus($booking, Booking::STATUS_REJECTED)->assertRedirect()->assertSessionHas('status');

        $this->assertSame(Booking::STATUS_REJECTED, $booking->fresh()->status);
        $this->assertSame(0, $this->heldDays());
    }

    public function test_cancelling_an_approved_application_gives_its_units_back_and_it_cannot_return(): void
    {
        $booking = $this->apply();
        $this->advanceTo($booking, Booking::STATUS_APPROVED);

        $this->setStatus($booking, Booking::STATUS_CANCELLED)->assertRedirect()->assertSessionHas('status');
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $this->heldDays());

        $this->setStatus($booking, Booking::STATUS_APPROVED)->assertSessionHas('error');
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $this->heldDays());
    }

    public function test_cancelling_twice_releases_the_units_only_once(): void
    {
        $first = $this->apply();
        $this->apply();
        $heldByBoth = UnitInventory::where('booked_units', 2)->count();
        $this->assertGreaterThan(0, $heldByBoth);

        $this->setStatus($first, Booking::STATUS_CANCELLED);
        $this->setStatus($first, Booking::STATUS_CANCELLED)->assertSessionHas('error');

        $this->assertSame(0, UnitInventory::where('booked_units', 2)->count());
        $this->assertSame($heldByBoth, UnitInventory::where('booked_units', 1)->count());
    }

    public function test_completing_a_lease_early_frees_only_the_days_that_have_not_passed(): void
    {
        $booking = $this->apply();
        $booking->update(['check_in' => now()->subDays(10), 'status' => Booking::STATUS_ACTIVE]);
        UnitInventory::query()->update(['booked_units' => 0]);
        UnitInventory::whereDate('stay_date', '>=', $this->moveIn->subDays(10)->toDateString())
            ->whereDate('stay_date', '<', $booking->check_out->toDateString())
            ->update(['booked_units' => 1]);

        $this->setStatus($booking, Booking::STATUS_COMPLETED)->assertSessionHas('status');

        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->status);
        $today = now($this->apartment->timezone)->toDateString();
        $this->assertSame(0, UnitInventory::where('booked_units', '>', 0)->whereDate('stay_date', '>=', $today)->count());
    }

    public function test_active_leases_complete_on_their_own_once_the_end_date_has_passed(): void
    {
        UnitInventory::query()->update(['total_units' => 5]);
        $ended = $this->apply();
        $ended->update(['status' => Booking::STATUS_ACTIVE, 'check_in' => now()->subMonths(2), 'check_out' => now()->subDay()]);
        $running = $this->apply();
        $running->update(['status' => Booking::STATUS_ACTIVE]);
        $waiting = $this->apply();
        $waiting->update(['status' => Booking::STATUS_APPROVED, 'check_out' => now()->subDay()]);

        $this->artisan('bookings:complete-ended-leases')->assertSuccessful();

        $this->assertSame(Booking::STATUS_COMPLETED, $ended->fresh()->status);
        $this->assertSame(Booking::STATUS_ACTIVE, $running->fresh()->status);
        $this->assertSame(Booking::STATUS_APPROVED, $waiting->fresh()->status);
    }

    public function test_unanswered_pending_applications_expire_and_give_their_units_back(): void
    {
        config(['reservation.pending_expiry_hours' => 48]);
        UnitInventory::query()->update(['total_units' => 5]);

        $stale = $this->apply();
        $stale->forceFill(['created_at' => now()->subHours(49)])->save();
        $fresh = $this->apply();
        $approved = $this->apply();
        $approved->forceFill(['status' => Booking::STATUS_APPROVED, 'created_at' => now()->subHours(100)])->save();

        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $this->assertSame(Booking::STATUS_CANCELLED, $stale->fresh()->status);
        $this->assertSame(Booking::STATUS_PENDING, $fresh->fresh()->status);
        $this->assertSame(Booking::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertSame(2, UnitInventory::max('booked_units'));
    }

    public function test_pending_applications_never_expire_unless_a_limit_is_set(): void
    {
        $booking = $this->apply();
        $booking->forceFill(['created_at' => now()->subDays(30)])->save();
        $held = $this->heldDays();

        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertSame($held, $this->heldDays());
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $booking = $this->apply();

        $this->setStatus($booking, 'confirmed')->assertSessionHasErrors('status');

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_the_application_list_offers_only_the_moves_an_application_can_make(): void
    {
        $booking = $this->apply();

        $this->actingAs($this->staff)->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertSee('>Approve</button>', false)
            ->assertSee('>Reject</button>', false)
            ->assertDontSee('>Start lease</button>', false);

        $this->advanceTo($booking, Booking::STATUS_APPROVED, Booking::STATUS_DEPOSIT_PAID);

        $this->actingAs($this->staff)->get(route('admin.bookings.index'))
            ->assertSee('>Start lease</button>', false)
            ->assertDontSee('>Approve</button>', false)
            ->assertDontSee('>Reject</button>', false);
    }

    public function test_the_application_list_can_be_filtered_by_status(): void
    {
        $approved = $this->apply();
        $this->advanceTo($approved, Booking::STATUS_APPROVED);
        $pending = $this->apply();

        $this->actingAs($this->staff)->get(route('admin.bookings.index', ['status' => 'approved']))
            ->assertSee($approved->reference)
            ->assertDontSee($pending->reference);
    }

    public function test_a_unit_type_with_open_applications_cannot_be_deleted(): void
    {
        $this->apply();

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->unitType);
    }

    public function test_a_unit_type_whose_applications_are_closed_can_be_deleted_and_the_list_still_works(): void
    {
        $booking = $this->apply();
        $this->setStatus($booking, Booking::STATUS_REJECTED);

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('status');

        $this->assertSoftDeleted($this->unitType);
        $this->actingAs($this->staff)->get(route('admin.bookings.index'))->assertOk()->assertSee($booking->reference)->assertSee('Studio');
    }

    public function test_a_unit_type_can_be_created_again_under_the_name_of_a_deleted_one(): void
    {
        $this->unitType->delete();

        $this->actingAs($this->staff)->post(route('admin.unit-types.store'), [
            'name' => 'Studio', 'bedrooms' => 0, 'bathrooms' => 1, 'max_adults' => 2, 'max_children' => 0, 'monthly_price' => 5000000, 'is_active' => 1,
        ])->assertRedirect(route('admin.unit-types.index'));

        $this->assertSame('studio-1', $this->apartment->unitTypes()->latest('id')->value('slug'));
    }

    public function test_a_unit_type_whose_open_applications_ended_in_the_past_can_be_deleted(): void
    {
        $booking = $this->apply();
        $booking->update(['check_in' => now()->subDays(40), 'check_out' => now()->subDays(8)]);

        $this->actingAs($this->staff)->delete(route('admin.unit-types.destroy', $this->unitType))->assertSessionHas('status');

        $this->assertSoftDeleted($this->unitType);
    }

    public function test_applicants_with_an_email_hear_about_approval_deposit_rejection_and_cancellation_but_not_lease_start(): void
    {
        Notification::fake();
        $booking = $this->apply();
        $booking->update(['guest_email' => 'ayu@example.test']);

        $this->advanceTo($booking, Booking::STATUS_APPROVED, Booking::STATUS_DEPOSIT_PAID);
        Notification::assertSentOnDemandTimes(BookingStatusChanged::class, 2);

        $this->advanceTo($booking, Booking::STATUS_ACTIVE, Booking::STATUS_COMPLETED);
        Notification::assertSentOnDemandTimes(BookingStatusChanged::class, 2);

        $other = $this->apply();
        $other->update(['guest_email' => 'budi@example.test']);
        $this->advanceTo($other, Booking::STATUS_REJECTED);
        Notification::assertSentOnDemandTimes(BookingStatusChanged::class, 3);
    }
}
