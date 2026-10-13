<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Notifications\GuestBookingSummary;
use App\Services\Concierge\ApartmentConciergeTools;
use App\Services\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaseRentalTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $studio;

    private UnitType $nightlyOnly;

    private CarbonImmutable $moveIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta',
            'default_locale' => 'en', 'security_deposit_months' => 2, 'min_monthly_lease_months' => 3,
        ]);

        $this->studio = $this->apartment->unitTypes()->create([
            'name' => 'Studio', 'slug' => 'studio', 'base_price' => 650000, 'monthly_price' => 13500000, 'yearly_price' => 150000000,
            'max_adults' => 2, 'max_children' => 0, 'is_active' => true,
        ]);
        $this->nightlyOnly = $this->apartment->unitTypes()->create([
            'name' => 'Suite', 'slug' => 'suite', 'base_price' => 2000000, 'max_adults' => 4, 'max_children' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->moveIn = CarbonImmutable::now('Asia/Jakarta')->startOfDay()->addDays(10);

        foreach ([$this->studio, $this->nightlyOnly] as $unitType) {
            $this->openInventory($unitType, $this->moveIn, 1100, 2);
        }
    }

    private function openInventory(UnitType $unitType, CarbonImmutable $from, int $nights, int $units): void
    {
        $now = now();

        UnitInventory::insert(collect(range(0, $nights - 1))->map(fn (int $offset) => [
            'unit_type_id' => $unitType->id, 'stay_date' => $from->addDays($offset)->toDateString(),
            'total_units' => $units, 'booked_units' => 0, 'price' => $unitType->base_price, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lease(array $overrides = []): array
    {
        return [
            'unit_type_slug' => 'studio', 'rental_type' => 'monthly', 'lease_months' => 6, 'check_in' => $this->moveIn->toDateString(),
            'adults' => 2, 'children' => 0, 'units' => 1, 'locale' => 'en', ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return [...$this->lease($overrides), 'guest_name' => 'Ayu', 'contact_type' => 'whatsapp', 'contact_value' => '+62 811 1111 2222'];
    }

    public function test_a_monthly_lease_costs_the_monthly_rent_for_every_month_plus_a_deposit(): void
    {
        $quote = app(ReservationService::class)->quoteLease($this->studio, 'monthly', $this->moveIn, 6);

        $this->assertSame(81000000.0, $quote['grand_total']);
        $this->assertSame(13500000.0, $quote['rate']);
        $this->assertSame('month', $quote['rate_period']);
        $this->assertSame(27000000.0, $quote['deposit_total']);
        $this->assertSame($this->moveIn->addMonthsNoOverflow(6)->toDateString(), $quote['check_out']);
        $this->assertSame(0, $quote['discount_percent']);
    }

    public function test_a_yearly_lease_costs_the_yearly_rent_for_every_year_and_is_cheaper_per_month(): void
    {
        $service = app(ReservationService::class);

        $oneYear = $service->quoteLease($this->studio, 'yearly', $this->moveIn, 12);
        $twoYears = $service->quoteLease($this->studio, 'yearly', $this->moveIn, 24, units: 2);

        $this->assertSame(150000000.0, $oneYear['grand_total']);
        $this->assertSame(12500000.0, $oneYear['monthly_rate']);
        $this->assertLessThan($this->studio->monthly_price, $oneYear['monthly_rate']);
        $this->assertSame(25000000.0, $oneYear['deposit_total']);
        $this->assertSame(600000000.0, $twoYears['grand_total']);
        $this->assertSame(50000000.0, $twoYears['deposit_total']);
    }

    public function test_leases_are_refused_for_units_without_that_rent_and_for_terms_the_apartment_does_not_offer(): void
    {
        $service = app(ReservationService::class);

        $this->assertNull($service->quoteLease($this->nightlyOnly, 'monthly', $this->moveIn, 6));
        $this->assertNull($service->quoteLease($this->studio, 'monthly', $this->moveIn, 2), 'shorter than the shortest monthly lease');
        $this->assertNull($service->quoteLease($this->studio, 'monthly', $this->moveIn, 12), 'a full year is a yearly rental');
        $this->assertNull($service->quoteLease($this->studio, 'yearly', $this->moveIn, 18));
        $this->assertNull($service->quoteLease($this->studio, 'yearly', $this->moveIn, 48));
    }

    public function test_the_quote_endpoint_prices_a_lease_without_a_check_out_date(): void
    {
        $this->postJson(route('reservation.quote', 'demo'), $this->lease())
            ->assertOk()
            ->assertJsonPath('rental_type', 'monthly')
            ->assertJsonPath('lease_months', 6)
            ->assertJsonPath('grand_total', 81000000)
            ->assertJsonPath('deposit_total', 27000000)
            ->assertJsonPath('check_out', $this->moveIn->addMonthsNoOverflow(6)->toDateString());
    }

    public function test_a_lease_longer_than_the_online_limit_for_nights_is_still_accepted(): void
    {
        $this->assertGreaterThan(ReservationService::MAX_NIGHTS, (int) $this->moveIn->diffInDays($this->moveIn->addMonthsNoOverflow(11)));

        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['lease_months' => 11]))->assertOk();
        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['rental_type' => 'yearly', 'lease_months' => 36]))->assertOk();
    }

    public function test_invalid_lease_requests_are_rejected_with_a_message_in_the_guests_language(): void
    {
        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['unit_type_slug' => 'suite']))
            ->assertStatus(422)->assertJsonValidationErrors('unit_type_slug');

        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['lease_months' => 2, 'locale' => 'id']))
            ->assertStatus(422)->assertJsonPath('errors.lease_months.0', 'Sewa bulanan berlaku 3 sampai 11 bulan. Untuk satu tahun atau lebih, pilih sewa tahunan.');

        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['rental_type' => 'yearly', 'lease_months' => 15]))
            ->assertStatus(422)->assertJsonValidationErrors('lease_months');

        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['lease_months' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('lease_months');

        $this->postJson(route('reservation.quote', 'demo'), $this->lease(['check_in' => $this->moveIn->subDays(30)->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('check_in');
    }

    public function test_a_lease_request_holds_the_unit_for_every_night_of_the_lease_and_cancelling_gives_it_back(): void
    {
        $response = $this->postJson(route('reservation.store', 'demo'), $this->request())->assertCreated();

        $response->assertJsonPath('rental_type', 'monthly')->assertJsonPath('lease_months', 6)->assertJsonPath('total', 81000000)->assertJsonPath('deposit_total', 27000000);

        $booking = Booking::firstOrFail();
        $moveOut = $this->moveIn->addMonthsNoOverflow(6);

        $this->assertSame('monthly', $booking->rental_type);
        $this->assertSame(6, $booking->lease_months);
        $this->assertSame($moveOut->toDateString(), $booking->check_out->toDateString());
        $this->assertSame(81000000.0, (float) $booking->total_price);
        $this->assertSame(27000000.0, (float) $booking->deposit_total);
        $this->assertTrue($booking->isLease());

        $held = UnitInventory::where('unit_type_id', $this->studio->id)->where('booked_units', 1);
        $this->assertSame((int) $this->moveIn->diffInDays($moveOut), $held->count());
        $this->assertSame($this->moveIn->toDateString(), $held->min('stay_date'));
        $this->assertSame($moveOut->subDay()->toDateString(), $held->max('stay_date'));

        $this->assertTrue(app(ReservationService::class)->changeStatus($booking, Booking::STATUS_CANCELLED));
        $this->assertSame(0, UnitInventory::where('booked_units', '>', 0)->count());
    }

    public function test_a_lease_cannot_overlap_a_night_that_is_already_fully_booked(): void
    {
        UnitInventory::where('unit_type_id', $this->studio->id)
            ->whereDate('stay_date', $this->moveIn->addMonths(3)->toDateString())
            ->update(['booked_units' => 2]);

        $this->postJson(route('reservation.store', 'demo'), $this->request())
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_type_slug');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, UnitInventory::where('booked_units', '>', 0)->where('booked_units', '<', 2)->count());
    }

    public function test_the_alternatives_offered_for_a_taken_lease_are_leases_too(): void
    {
        $other = $this->apartment->unitTypes()->create([
            'name' => 'Loft', 'slug' => 'loft', 'base_price' => 900000, 'monthly_price' => 18000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true, 'sort_order' => 2,
        ]);
        $this->openInventory($other, $this->moveIn, 1100, 1);
        UnitInventory::where('unit_type_id', $this->studio->id)->update(['booked_units' => 2]);

        $this->postJson(route('reservation.quote', 'demo'), $this->lease())
            ->assertStatus(422)
            ->assertJsonPath('alternatives.0.slug', 'loft')
            ->assertJsonPath('alternatives.0.total', 108000000)
            ->assertJsonCount(1, 'alternatives');
    }

    public function test_nightly_stays_are_unchanged_and_record_no_lease(): void
    {
        $checkOut = $this->moveIn->addDays(3);

        $this->postJson(route('reservation.store', 'demo'), [
            'unit_type_slug' => 'studio', 'check_in' => $this->moveIn->toDateString(), 'check_out' => $checkOut->toDateString(),
            'adults' => 2, 'units' => 1, 'locale' => 'en', 'guest_name' => 'Ayu', 'contact_type' => 'whatsapp', 'contact_value' => '+62 811 1111 2222',
        ])->assertCreated()->assertJsonPath('rental_type', 'daily')->assertJsonPath('total', 1950000);

        $booking = Booking::firstOrFail();
        $this->assertSame('daily', $booking->rental_type);
        $this->assertNull($booking->lease_months);
        $this->assertNull($booking->deposit_total);
        $this->assertFalse($booking->isLease());
    }

    public function test_the_concierge_quotes_and_books_a_monthly_and_a_yearly_lease(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $tools = new ApartmentConciergeTools($this->apartment, $conversation, 'en');
        $stay = ['unit_type_slug' => 'studio', 'check_in' => $this->moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 4];

        $quote = $tools->dispatch('check_availability', $stay)['ui']['quote'];
        $this->assertSame(54000000.0, $quote['grand_total']);
        $this->assertSame(27000000.0, $quote['deposit_total']);
        $this->assertSame('monthly', $quote['rental_type']);

        $search = $tools->dispatch('search_units', ['check_in' => $this->moveIn->toDateString(), 'rental_type' => 'yearly', 'lease_months' => 12, 'adults' => 2])['ui'];
        $this->assertSame(['studio'], array_column($search['units'], 'unit_type_slug'), 'only units with a yearly rent are listed');
        $this->assertSame(150000000.0, $search['units'][0]['total_price']);

        $booked = $tools->dispatch('create_booking_request', [...$stay, 'adults' => 1, 'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222'])['ui']['booking'];
        $this->assertSame('monthly', $booked['rental_type']);
        $this->assertSame(4, $booked['lease_months']);
        $this->assertSame(54000000.0, $booked['total_price']);
        $this->assertSame($this->moveIn->addMonthsNoOverflow(4)->toDateString(), $booked['check_out']);
        $this->assertSame(1, Booking::where('rental_type', 'monthly')->count());
    }

    public function test_the_concierge_reports_a_bad_lease_term_instead_of_guessing(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $tools = new ApartmentConciergeTools($this->apartment, $conversation, 'en');

        $result = $tools->dispatch('check_availability', ['unit_type_slug' => 'studio', 'check_in' => $this->moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 12]);
        $this->assertNull($result['ui']);
        $this->assertStringContainsString('3 to 11', $result['text']);

        $unsupported = $tools->dispatch('check_availability', ['unit_type_slug' => 'suite', 'check_in' => $this->moveIn->toDateString(), 'rental_type' => 'yearly', 'lease_months' => 12]);
        $this->assertNull($unsupported['ui']);
        $this->assertStringContainsString('not rented', $unsupported['text']);
    }

    public function test_unit_listings_show_the_monthly_and_yearly_rents(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $units = collect((new ApartmentConciergeTools($this->apartment, $conversation, 'en'))->dispatch('search_units', [])['ui']['units'])->keyBy('unit_type_slug');

        $this->assertEquals(13500000, $units['studio']['monthly_price']);
        $this->assertEquals(150000000, $units['studio']['yearly_price']);
        $this->assertSame(12500000.0, $units['studio']['yearly_monthly_equivalent']);
        $this->assertNull($units['suite']['monthly_price']);
        $this->assertSame(2, $units['studio']['lease']['security_deposit_months']);
    }

    public function test_guest_emails_describe_the_lease_and_the_deposit(): void
    {
        $this->postJson(route('reservation.store', 'demo'), $this->request(['rental_type' => 'yearly', 'lease_months' => 24]))->assertCreated();
        $lines = GuestBookingSummary::lines(Booking::firstOrFail(), 'en');

        $this->assertContains('Rental: Yearly rental, 2 years', $lines);
        $this->assertContains('Security deposit (refundable): IDR 25.000.000', $lines);
        $this->assertContains('Total: IDR 300.000.000', $lines);
        $this->assertContains('Jenis sewa: Sewa tahunan, 2 tahun', GuestBookingSummary::lines(Booking::firstOrFail(), 'id'));
    }

    public function test_staff_set_the_monthly_and_yearly_rent_of_a_unit_and_the_deposit_rules(): void
    {
        $owner = User::factory()->create();
        $this->apartment->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)->put(route('admin.unit-types.update', $this->nightlyOnly), [
            'name' => 'Suite', 'bedrooms' => 2, 'bathrooms' => 1, 'max_adults' => 4, 'max_children' => 0, 'base_price' => 2000000,
            'monthly_price' => 38000000, 'yearly_price' => '', 'is_active' => 1,
        ])->assertRedirect(route('admin.unit-types.index'));

        $this->nightlyOnly->refresh();
        $this->assertTrue($this->nightlyOnly->offersMonthlyRental());
        $this->assertFalse($this->nightlyOnly->offersYearlyRental());

        $this->actingAs($owner)->put(route('admin.settings.update'), [
            'name' => 'Demo', 'timezone' => 'Asia/Jakarta', 'default_locale' => 'en', 'check_in_time' => '14:00', 'check_out_time' => '12:00',
            'weekly_discount_percent' => 0, 'monthly_discount_percent' => 0, 'public_status' => 'published',
            'security_deposit_months' => 1, 'min_monthly_lease_months' => 6,
        ])->assertRedirect(route('admin.settings.edit'));

        $this->apartment->refresh();
        $this->assertSame(1, $this->apartment->security_deposit_months);
        $this->assertSame(6, $this->apartment->min_monthly_lease_months);

        $this->actingAs($owner)->put(route('admin.settings.update'), [
            'name' => 'Demo', 'timezone' => 'Asia/Jakarta', 'default_locale' => 'en', 'check_in_time' => '14:00', 'check_out_time' => '12:00',
            'weekly_discount_percent' => 0, 'monthly_discount_percent' => 0, 'public_status' => 'published',
            'security_deposit_months' => 1, 'min_monthly_lease_months' => 12,
        ])->assertSessionHasErrors('min_monthly_lease_months');
    }
}
