<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\UnitInventory;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'timezone' => 'Asia/Makassar']);
        $this->today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
    }

    private function unitType(string $slug, bool $active = true): UnitType
    {
        return $this->apartment->unitTypes()->create(['name' => $slug, 'slug' => $slug, 'base_price' => 500000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => $active]);
    }

    private function night(UnitType $unitType, int $offset, int $total = 2, int $booked = 0): void
    {
        UnitInventory::create(['unit_type_id' => $unitType->id, 'stay_date' => $this->today->addDays($offset)->toDateString(), 'total_units' => $total, 'booked_units' => $booked, 'price' => 500000]);
    }

    /**
     * @return list<string>
     */
    private function openNights(): array
    {
        return $this->getJson('/demo/apply/availability')->assertOk()->json('dates');
    }

    public function test_a_night_is_open_while_any_active_unit_type_has_a_free_unit(): void
    {
        $studio = $this->unitType('studio');
        $suite = $this->unitType('suite');
        $this->night($studio, 1, total: 1, booked: 1);
        $this->night($suite, 1, total: 2, booked: 1);

        $this->assertSame([$this->today->addDay()->toDateString()], $this->openNights());
    }

    public function test_a_night_is_closed_when_every_unit_is_booked_or_nothing_is_open(): void
    {
        $studio = $this->unitType('studio');
        $this->night($studio, 1, total: 2, booked: 2);
        $this->night($studio, 3, total: 0);

        $this->assertSame([], $this->openNights());
    }

    public function test_inactive_unit_types_and_past_nights_do_not_open_a_date(): void
    {
        $hidden = $this->unitType('hidden', active: false);
        $studio = $this->unitType('studio');
        $this->night($hidden, 2);
        $this->night($studio, -1);

        $this->assertSame([], $this->openNights());
    }

    public function test_nights_are_listed_once_and_in_date_order_with_the_guest_time_zone_today(): void
    {
        $studio = $this->unitType('studio');
        $suite = $this->unitType('suite');
        $this->night($studio, 5);
        $this->night($suite, 5);
        $this->night($studio, 2);

        $response = $this->getJson('/demo/apply/availability')->assertOk();

        $this->assertSame([$this->today->addDays(2)->toDateString(), $this->today->addDays(5)->toDateString()], $response->json('dates'));
        $this->assertSame($this->today->toDateString(), $response->json('today'));
    }

    public function test_nights_beyond_the_look_ahead_window_are_left_out(): void
    {
        $studio = $this->unitType('studio');
        $this->night($studio, 366);
        $this->night($studio, 367);

        $this->assertSame([$this->today->addDays(366)->toDateString()], $this->openNights());
    }

    public function test_another_apartments_inventory_is_never_listed(): void
    {
        $other = Apartment::create(['name' => 'Other', 'slug' => 'other', 'public_status' => 'published']);
        $foreign = $other->unitTypes()->create(['name' => 'Foreign', 'slug' => 'foreign', 'base_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => true]);
        $this->night($foreign, 2);

        $this->assertSame([], $this->openNights());
    }

    public function test_an_unpublished_or_unknown_apartment_returns_404(): void
    {
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->getJson('/draft/apply/availability')->assertNotFound();
        $this->getJson('/nowhere/apply/availability')->assertNotFound();
    }
}
