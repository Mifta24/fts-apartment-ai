<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoApartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberedApartmentTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $studio;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'default_locale' => 'en', 'address' => 'Jl. Senopati 88', 'city' => 'Jakarta']);
        $this->studio = $this->apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 13500000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $this->today = CarbonImmutable::now($this->apartment->timezone)->startOfDay();
    }

    private function addUnit(string $number, int $floor, array $overrides = []): Unit
    {
        return $this->studio->units()->create(['apartment_id' => $this->apartment->id, 'unit_number' => $number, 'floor' => $floor, 'facing' => 'north', 'status' => Unit::STATUS_AVAILABLE, ...$overrides]);
    }

    public function test_the_unit_page_lists_free_apartments_and_those_that_free_up_later(): void
    {
        $this->addUnit('801', 8);
        $this->addUnit('902', 9, ['status' => Unit::STATUS_OCCUPIED, 'available_from' => $this->today->addDays(30)->toDateString()]);
        $this->addUnit('1003', 10, ['status' => Unit::STATUS_OCCUPIED]);
        $this->addUnit('1104', 11, ['status' => Unit::STATUS_MAINTENANCE, 'available_from' => $this->today->addDays(5)->toDateString()]);

        $this->get('/demo/units/studio?lang=en')
            ->assertOk()
            ->assertSee('Unit 801')
            ->assertSee('Ready now')
            ->assertSee('Unit 902')
            ->assertSee('Free from '.$this->today->addDays(30)->locale('en')->translatedFormat('j M Y'))
            ->assertDontSee('Unit 1003')
            ->assertDontSee('Unit 1104')
            ->assertSee('room=801', false);
    }

    public function test_choosing_an_apartment_carries_it_into_the_application_form(): void
    {
        $this->addUnit('801', 8);

        $this->get('/demo/apply?lang=en&unit=studio&room=801')
            ->assertOk()
            ->assertSee('name="preferred_unit_number" value="801"', false)
            ->assertSee('Preferred unit: 801');

        $this->get('/demo/apply?lang=en&unit=studio&room=999')
            ->assertOk()
            ->assertDontSee('preferred_unit_number');
    }

    public function test_an_application_remembers_the_preferred_apartment_only_if_it_fits(): void
    {
        $unit = $this->addUnit('801', 8);
        $other = $this->apartment->unitTypes()->create(['name' => 'Suite', 'slug' => 'suite', 'monthly_price' => 30000000, 'max_adults' => 4, 'max_children' => 0, 'is_active' => true]);
        $otherUnit = $other->units()->create(['apartment_id' => $this->apartment->id, 'unit_number' => '1201', 'floor' => 12, 'status' => Unit::STATUS_AVAILABLE]);

        foreach ([$this->studio, $other] as $type) {
            UnitInventory::insert(collect(range(1, 120))->map(fn (int $offset) => [
                'unit_type_id' => $type->id, 'stay_date' => $this->today->addDays($offset)->toDateString(),
                'total_units' => 2, 'booked_units' => 0, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }

        $payload = [
            'unit_type_slug' => 'studio', 'rental_type' => 'monthly', 'lease_months' => 2, 'check_in' => $this->today->addDays(5)->toDateString(),
            'adults' => 1, 'children' => 0, 'units' => 1, 'locale' => 'en',
            'guest_name' => 'John Tan', 'contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 1111',
        ];

        $this->postJson('/demo/apply', [...$payload, 'preferred_unit_number' => '801'])->assertCreated();
        $this->postJson('/demo/apply', [...$payload, 'preferred_unit_number' => '1201'])->assertCreated();

        $bookings = Booking::orderBy('id')->get();
        $this->assertSame($unit->id, $bookings[0]->preferred_unit_id);
        $this->assertNull($bookings[1]->preferred_unit_id, 'an apartment of another type must be ignored');
        $this->assertNotNull($otherUnit->id);
    }

    public function test_staff_manage_the_numbered_apartments(): void
    {
        $unit = $this->addUnit('801', 8);
        $staff = User::factory()->create();
        $this->apartment->users()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        $this->actingAs($staff)->get('/admin/units')->assertOk()->assertSee('801');

        $this->actingAs($staff)->put("/admin/units/{$unit->id}", ['status' => 'occupied', 'available_from' => $this->today->addDays(14)->toDateString(), 'facing' => 'south'])->assertRedirect();
        $this->assertSame(Unit::STATUS_OCCUPIED, $unit->fresh()->status);
        $this->assertSame('south', $unit->fresh()->facing);

        $this->actingAs($staff)->post('/admin/units', ['unit_type_id' => $this->studio->id, 'unit_number' => '802', 'floor' => 8, 'status' => 'available'])->assertRedirect();
        $this->assertTrue($this->apartment->units()->where('unit_number', '802')->exists());

        $this->actingAs($staff)->post('/admin/units', ['unit_type_id' => $this->studio->id, 'unit_number' => '802', 'floor' => 8, 'status' => 'available'])->assertSessionHasErrors('unit_number');
    }

    public function test_the_sample_lease_is_readable_in_every_language(): void
    {
        $this->get('/demo/lease-sample?lang=id')->assertOk()->assertSee('Contoh perjanjian sewa hunian')->assertSee('HANYA CONTOH');
        $this->get('/demo/lease-sample?lang=en')->assertOk()->assertSee('Sample residential lease agreement');
        $this->get('/demo/lease-sample?lang=ja')->assertOk()->assertSee('賃貸借契約書（サンプル）');
    }

    public function test_the_application_page_lists_the_documents_to_prepare(): void
    {
        $this->get('/demo/apply?lang=en')
            ->assertOk()
            ->assertSee('Documents to prepare')
            ->assertSee('Read a sample lease agreement');
    }

    public function test_the_demo_seed_numbers_every_rentable_unit_and_explains_move_in_costs(): void
    {
        $this->seed(DemoApartmentSeeder::class);
        $demo = Apartment::where('name', 'FTS Apartment AI')->firstOrFail();

        $this->assertSame(24, $demo->unitTypes()->where('slug', 'studio-urban')->firstOrFail()->units()->count());
        $this->assertTrue($demo->units()->where('status', Unit::STATUS_AVAILABLE)->exists());
        $this->assertTrue($demo->units()->whereNotNull('available_from')->exists());
        $this->assertSame(0, $demo->knowledgeItems()->where('is_active', true)->where('body', 'like', '%housekeeping%')->count());
        $this->assertTrue($demo->knowledgeItems()->where('is_active', true)->where('title', 'Biaya awal sewa')->exists());
        $this->assertTrue($demo->knowledgeItems()->where('is_active', true)->where('title', 'Dokumen persyaratan sewa')->exists());
    }
}
