<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Services\Concierge\ApartmentConciergeTools;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoApartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitCategoryAndSaleListingTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $studio;

    private UnitType $office;

    private UnitType $penthouseForSale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta', 'default_locale' => 'en',
        ]);

        $this->studio = $this->apartment->unitTypes()->create([
            'name' => 'Studio', 'slug' => 'studio', 'base_price' => 650000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true,
        ]);
        $this->office = $this->apartment->unitTypes()->create([
            'name' => 'Office Suite', 'slug' => 'office-suite', 'category' => UnitType::CATEGORY_OFFICE, 'listing_type' => UnitType::LISTING_RENT,
            'base_price' => 0, 'monthly_price' => 25000000, 'bedrooms' => 0, 'bathrooms' => 1, 'max_adults' => 10, 'max_children' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->penthouseForSale = $this->apartment->unitTypes()->create([
            'name' => 'Sky Penthouse', 'slug' => 'sky-penthouse', 'listing_type' => UnitType::LISTING_SALE, 'sale_price' => 12500000000,
            'base_price' => 0, 'bedrooms' => 3, 'bathrooms' => 3, 'max_adults' => 6, 'max_children' => 2, 'is_active' => true, 'sort_order' => 2,
        ]);

        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        foreach ([$this->studio, $this->office, $this->penthouseForSale] as $unitType) {
            UnitInventory::insert(collect(range(10, 14))->map(fn (int $offset) => [
                'unit_type_id' => $unitType->id, 'stay_date' => $today->addDays($offset)->toDateString(),
                'total_units' => 2, 'booked_units' => 0, 'price' => 650000, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unitPayload(array $overrides = []): array
    {
        return [
            'name' => 'Corner Office', 'category' => 'office', 'listing_type' => 'rent', 'bedrooms' => 0, 'bathrooms' => 1,
            'max_adults' => 8, 'max_children' => 0, 'monthly_price' => 18000000, 'is_active' => 1,
            ...$overrides,
        ];
    }

    private function staff(): User
    {
        $staff = User::factory()->create();
        $this->apartment->users()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        return $staff;
    }

    public function test_existing_style_units_default_to_residential_rentals(): void
    {
        $this->assertTrue($this->studio->isResidential());
        $this->assertTrue($this->studio->isForRent());
        $this->assertFalse($this->studio->isForSale());
        $this->assertTrue($this->studio->offersDailyRental());
    }

    public function test_a_monthly_only_office_is_not_rented_by_the_night(): void
    {
        $this->assertTrue($this->office->offersMonthlyRental());
        $this->assertFalse($this->office->offersDailyRental());
        $this->assertFalse($this->office->offersRental('daily'));
        $this->assertFalse($this->office->isResidential());
    }

    public function test_a_unit_for_sale_offers_no_rental_of_any_kind(): void
    {
        $this->assertTrue($this->penthouseForSale->offersSalePrice());
        $this->assertFalse($this->penthouseForSale->offersRental('daily'));
        $this->assertFalse($this->penthouseForSale->offersRental('monthly'));
    }

    public function test_the_public_pages_show_the_sale_price_and_an_enquiry_button_instead_of_booking(): void
    {
        $this->get('/demo/units/sky-penthouse')
            ->assertOk()
            ->assertSee('12.500.000.000')
            ->assertSee('data-hero-quick-message', false)
            ->assertSee('buying')
            ->assertDontSee('unit=sky-penthouse', false);

        $this->get('/demo/units/studio')
            ->assertOk()
            ->assertSee('unit=studio', false)
            ->assertDontSee('interested in buying');
    }

    public function test_an_office_page_names_its_category_instead_of_bedrooms(): void
    {
        $this->get('/demo/units/office-suite')
            ->assertOk()
            ->assertSee('Office')
            ->assertDontSee('per night');
    }

    public function test_the_reservation_form_lists_only_units_for_rent(): void
    {
        $this->get('/demo/reservation')
            ->assertOk()
            ->assertSee('value="studio"', false)
            ->assertSee('value="office-suite"', false)
            ->assertDontSee('value="sky-penthouse"', false);
    }

    public function test_a_unit_for_sale_cannot_be_quoted_or_reserved(): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        $this->postJson('/demo/reservation/quote', [
            'unit_type_slug' => 'sky-penthouse', 'check_in' => $today->addDays(10)->toDateString(), 'check_out' => $today->addDays(12)->toDateString(),
            'adults' => 2, 'children' => 0, 'units' => 1, 'locale' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
    }

    public function test_a_monthly_only_unit_cannot_be_reserved_by_the_night(): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        $this->postJson('/demo/reservation/quote', [
            'unit_type_slug' => 'office-suite', 'check_in' => $today->addDays(10)->toDateString(), 'check_out' => $today->addDays(12)->toDateString(),
            'adults' => 2, 'children' => 0, 'units' => 1, 'locale' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
    }

    public function test_the_concierge_reports_the_sale_price_and_refuses_to_book_a_unit_for_sale(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'locale' => 'en', 'guest_token' => (string) Str::uuid()]);
        $tools = new ApartmentConciergeTools($this->apartment, $conversation, 'en');

        $detail = json_decode($tools->dispatch('get_unit_detail', ['unit_type_slug' => 'sky-penthouse'])['text'], true);

        $this->assertSame('sale', $detail['listing_type']);
        $this->assertEquals(12500000000, $detail['sale_price']);
        $this->assertNull($detail['starting_price']);

        $reservation = $tools->dispatch('start_reservation', ['unit_type_slug' => 'sky-penthouse']);

        $this->assertStringContainsString('for sale', $reservation['text']);
        $this->assertNull($reservation['ui']);
    }

    public function test_staff_can_add_an_office_that_is_rented_monthly(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.unit-types.store'), $this->unitPayload())
            ->assertRedirect(route('admin.unit-types.index'));

        $this->assertDatabaseHas('unit_types', [
            'apartment_id' => $this->apartment->id, 'name' => 'Corner Office', 'category' => 'office', 'listing_type' => 'rent', 'base_price' => 0,
        ]);
    }

    public function test_staff_can_list_a_unit_for_sale_with_a_price(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.unit-types.store'), $this->unitPayload([
                'name' => 'Retail Kiosk', 'category' => 'commercial', 'listing_type' => 'sale', 'sale_price' => 3200000000, 'monthly_price' => null,
            ]))
            ->assertRedirect(route('admin.unit-types.index'));

        $this->assertDatabaseHas('unit_types', ['name' => 'Retail Kiosk', 'category' => 'commercial', 'listing_type' => 'sale', 'sale_price' => 3200000000]);
    }

    public function test_a_unit_for_sale_needs_a_sale_price(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.unit-types.store'), $this->unitPayload(['listing_type' => 'sale', 'monthly_price' => null]))
            ->assertSessionHasErrors('sale_price');
    }

    public function test_a_unit_for_rent_needs_at_least_one_rate(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.unit-types.store'), $this->unitPayload(['monthly_price' => null]))
            ->assertSessionHasErrors('base_price');
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.unit-types.store'), $this->unitPayload(['category' => 'warehouse']))
            ->assertSessionHasErrors('category');
    }

    public function test_the_demo_seeder_adds_an_office_a_commercial_unit_and_a_unit_for_sale(): void
    {
        $this->seed(DemoApartmentSeeder::class);

        $demo = Apartment::where('name', 'FTS Apartment AI')->firstOrFail();
        $units = $demo->unitTypes()->get()->keyBy('slug');

        $this->assertSame(UnitType::CATEGORY_OFFICE, $units['office-suite-senopati']->category);
        $this->assertFalse($units['office-suite-senopati']->offersDailyRental());
        $this->assertTrue($units['office-suite-senopati']->offersMonthlyRental());
        $this->assertTrue($units['retail-kiosk-podium']->isForRent() && $units['retail-kiosk-podium']->offersSalePrice());
        $this->assertFalse($units['two-bedroom-residence-for-sale']->isForRent());
        $this->assertTrue($units['two-bedroom-residence-for-sale']->offersSalePrice());
        $this->assertSame(0, $units['two-bedroom-residence-for-sale']->inventory()->count());
        $this->assertGreaterThan(0, $units['office-suite-senopati']->inventory()->count());

        $this->get('/'.$demo->slug.'/units')->assertOk();
    }
}
