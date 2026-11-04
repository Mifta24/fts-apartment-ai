<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\HandoverRequest;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $unitType;

    private User $staff;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'timezone' => 'Asia/Jakarta']);
        $this->unitType = $this->apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 13500000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $this->staff = User::factory()->create();
        $this->apartment->users()->attach($this->staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
    }

    private function booking(string $status, int $checkInOffset, ?\DateTimeInterface $createdAt = null, ?Apartment $apartment = null, ?UnitType $unitType = null): Booking
    {
        $booking = Booking::create([
            'reference' => 'BK-'.Str::upper(Str::random(6)), 'apartment_id' => ($apartment ?? $this->apartment)->id, 'unit_type_id' => ($unitType ?? $this->unitType)->id,
            'guest_name' => 'Guest '.Str::random(4), 'check_in' => $this->today->addDays($checkInOffset), 'check_out' => $this->today->addDays($checkInOffset + 2),
            'adults' => 2, 'unit_count' => 1, 'total_price' => 1000000, 'status' => $status,
        ]);

        if ($createdAt) {
            $booking->forceFill(['created_at' => $createdAt])->save();
        }

        return $booking;
    }

    private function dashboard()
    {
        return $this->actingAs($this->staff)->get(route('admin.dashboard'));
    }

    public function test_pending_requests_older_than_a_day_are_flagged_as_waiting(): void
    {
        $this->freezeTime();
        $this->booking(Booking::STATUS_PENDING, 5);
        $this->booking(Booking::STATUS_PENDING, 6, now()->subHours(25));
        $this->booking(Booking::STATUS_APPROVED, 7, now()->subDays(3));

        $this->dashboard()->assertOk()->assertSee('1 waiting over 24h')->assertSee('border-amber-300', false);
    }

    public function test_nothing_is_flagged_when_every_pending_request_is_recent(): void
    {
        $this->booking(Booking::STATUS_PENDING, 5);

        $this->dashboard()->assertOk()->assertSee('None waiting over 24h')->assertDontSee('border-amber-300', false);
    }

    public function test_move_ins_count_and_list_only_confirmed_bookings_in_the_next_seven_days(): void
    {
        $soon = $this->booking(Booking::STATUS_APPROVED, 0);
        $this->booking(Booking::STATUS_APPROVED, 6);
        $this->booking(Booking::STATUS_APPROVED, 7);
        $this->booking(Booking::STATUS_APPROVED, -1);
        $this->booking(Booking::STATUS_PENDING, 2);
        $this->booking(Booking::STATUS_CANCELLED, 2);

        $response = $this->dashboard()->assertOk()->assertSee('Move-ins · 7 days')->assertSee($soon->guest_name);

        $this->assertCount(2, $response->viewData('arrivals'));
        $this->assertSame(2, $response->viewData('stats')['arrivals']);
    }

    public function test_occupancy_is_booked_over_total_units_for_the_next_thirty_days_of_active_unit_types(): void
    {
        $hidden = $this->apartment->unitTypes()->create(['name' => 'Hidden', 'slug' => 'hidden', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => false]);
        foreach ([[$this->unitType, 0, 10, 4], [$this->unitType, 29, 10, 6], [$this->unitType, 30, 10, 10], [$hidden, 1, 10, 10]] as [$unitType, $offset, $total, $booked]) {
            UnitInventory::create(['unit_type_id' => $unitType->id, 'stay_date' => $this->today->addDays($offset)->toDateString(), 'total_units' => $total, 'booked_units' => $booked, 'price' => 1]);
        }

        $response = $this->dashboard()->assertOk()->assertSee('Occupancy · 30 days')->assertSee('50%');

        $this->assertSame(50, $response->viewData('stats')['occupancy_percent']);
    }

    public function test_occupancy_is_a_dash_until_dates_are_open(): void
    {
        $this->dashboard()->assertOk()->assertSee('No dates open yet');

        $this->assertNull($this->dashboard()->viewData('stats')['occupancy_percent']);
    }

    public function test_figures_never_include_another_apartments_data(): void
    {
        $this->freezeTime();
        $other = Apartment::create(['name' => 'Other', 'slug' => 'other', 'public_status' => 'published']);
        $foreign = $other->unitTypes()->create(['name' => 'Foreign', 'slug' => 'foreign', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => true]);
        $this->booking(Booking::STATUS_APPROVED, 1, null, $other, $foreign);
        $this->booking(Booking::STATUS_PENDING, 1, now()->subDays(2), $other, $foreign);
        UnitInventory::create(['unit_type_id' => $foreign->id, 'stay_date' => $this->today->toDateString(), 'total_units' => 5, 'booked_units' => 5, 'price' => 1]);
        $conversation = Conversation::create(['apartment_id' => $other->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        HandoverRequest::create(['conversation_id' => $conversation->id, 'reason' => 'complaint', 'summary' => 'Foreign complaint']);

        $stats = $this->dashboard()->assertOk()->viewData('stats');

        $this->assertSame(0, $stats['arrivals']);
        $this->assertSame(0, $stats['pending_bookings']);
        $this->assertSame(0, $stats['waiting_bookings']);
        $this->assertSame(0, $stats['open_handovers']);
        $this->assertNull($stats['occupancy_percent']);
    }

    public function test_every_admin_page_has_a_menu_for_phones_and_marks_the_current_page(): void
    {
        $this->dashboard()
            ->assertOk()
            ->assertSee('<details class="group">', false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($this->staff)->get(route('admin.bookings.index'))->assertOk()->assertSee('<details class="group">', false);
    }
}
