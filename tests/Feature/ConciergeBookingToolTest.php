<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Services\Concierge\ApartmentConciergeTools;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConciergeBookingToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_booking_tool_creates_a_reference_and_holds_the_requested_units(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $unit = $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'base_price' => 1000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $checkIn = CarbonImmutable::now()->addDays(5);
        foreach ([0, 1] as $offset) {
            UnitInventory::create(['unit_type_id' => $unit->id, 'stay_date' => $checkIn->addDays($offset)->toDateString(), 'total_units' => 3, 'booked_units' => 0, 'price' => 1000000]);
        }

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $input = [
            'unit_type_slug' => 'deluxe-king', 'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2, 'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222',
        ];

        $quote = $tools->dispatch('check_availability', [...$input, 'extra_bed' => false]);
        $this->assertSame(2000000.0, $quote['ui']['quote']['grand_total']);

        $result = $tools->dispatch('create_booking_request', [...$input, 'units' => 2]);

        $booking = Booking::firstOrFail();
        $this->assertSame($booking->reference, $result['ui']['booking']['booking_reference']);
        $this->assertSame(2, $booking->unit_count);
        $this->assertSame(4000000.0, (float) $booking->total_price);
        $this->assertSame([2, 2], UnitInventory::orderBy('stay_date')->pluck('booked_units')->all());

        $tooMany = $tools->dispatch('create_booking_request', [...$input, 'units' => 2]);
        $this->assertNull($tooMany['ui']);
        $this->assertSame(1, Booking::count());
    }

    public function test_ai_tools_quote_long_stays_with_the_discount_and_filter_by_bedrooms(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'weekly_discount_percent' => 10, 'monthly_discount_percent' => 25]);
        $studio = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 600000, 'bedrooms' => 0, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $family = $apartment->unitTypes()->create(['name' => 'Two Bedroom', 'slug' => 'two-bedroom', 'base_price' => 1500000, 'bedrooms' => 2, 'bathrooms' => 2, 'floor_range' => '15–26', 'max_adults' => 4, 'max_children' => 2, 'is_active' => true]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $checkIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        foreach ([$studio, $family] as $unit) {
            foreach (range(0, 6) as $offset) {
                UnitInventory::create(['unit_type_id' => $unit->id, 'stay_date' => $checkIn->addDays($offset)->toDateString(), 'total_units' => 2, 'booked_units' => 0, 'price' => $unit->base_price]);
            }
        }

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $week = ['check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(7)->toDateString()];

        $quote = $tools->dispatch('check_availability', [...$week, 'unit_type_slug' => 'two-bedroom'])['ui']['quote'];
        $this->assertSame(10, $quote['discount_percent']);
        $this->assertSame(10500000.0, $quote['subtotal']);
        $this->assertSame(9450000.0, $quote['grand_total']);

        $search = $tools->dispatch('search_units', [...$week, 'adults' => 2, 'bedrooms' => 1])['ui']['units'];
        $this->assertSame(['two-bedroom'], array_column($search, 'unit_type_slug'));
        $this->assertSame(9450000.0, $search[0]['total_price']);
        $this->assertSame('15–26', $search[0]['floor_range']);

        $detail = json_decode($tools->dispatch('get_unit_detail', ['unit_type_slug' => 'studio'])['text'], true);
        $this->assertSame(0, $detail['bedrooms']);
        $this->assertSame(25, $detail['long_stay']['monthly_discount_percent']);
    }

    public function test_search_units_without_dates_lists_every_active_unit_type_with_starting_prices(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 500000, 'max_adults' => 2, 'max_children' => 0, 'bedrooms' => 0, 'is_active' => true]);
        $apartment->unitTypes()->create(['name' => 'Two Bedroom', 'slug' => 'two-bedroom', 'base_price' => 1500000, 'max_adults' => 4, 'max_children' => 2, 'bedrooms' => 2, 'is_active' => true]);
        $apartment->unitTypes()->create(['name' => 'Hidden', 'slug' => 'hidden', 'base_price' => 100000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => false]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'id']);

        $result = (new ApartmentConciergeTools($apartment, $conversation, 'id'))->dispatch('search_units', []);

        $this->assertSame('unit_results', $result['ui']['type']);
        $this->assertSame(['studio', 'two-bedroom'], array_column($result['ui']['units'], 'unit_type_slug'));
        $this->assertSame(500000.0, (float) $result['ui']['units'][0]['starting_price']);
        $this->assertArrayNotHasKey('total_price', $result['ui']['units'][0]);
    }

    public function test_start_reservation_opens_a_prefilled_form_and_quotes_the_dates(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $unit = $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'base_price' => 1000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $unit->images()->create(['image_url' => 'https://example.test/a.jpg', 'tags' => ['bedroom'], 'alt_text' => 'Bedroom', 'sort_order' => 0]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $checkIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        foreach ([0, 1] as $offset) {
            UnitInventory::create(['unit_type_id' => $unit->id, 'stay_date' => $checkIn->addDays($offset)->toDateString(), 'total_units' => 3, 'booked_units' => 0, 'price' => 1000000]);
        }

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');

        $result = $tools->dispatch('start_reservation', [
            'unit_type_slug' => 'deluxe-king', 'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(2)->toDateString(), 'adults' => 2,
        ]);

        $this->assertSame('reservation_form', $result['ui']['type']);
        $this->assertSame('deluxe-king', $result['ui']['unit']['unit_type_slug']);
        $this->assertSame('https://example.test/a.jpg', $result['ui']['unit']['images'][0]['url']);
        $this->assertSame(['adults' => 2, 'children' => 0, 'units' => 1, 'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(2)->toDateString()], $result['ui']['prefill']);
        $this->assertSame(2000000.0, $result['ui']['quote']['grand_total']);
        $this->assertSame(0, Booking::count());
    }

    public function test_start_reservation_works_without_dates_and_ignores_past_ones(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 600000, 'max_adults' => 2, 'is_active' => true]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');

        $bare = $tools->dispatch('start_reservation', ['unit_type_slug' => 'studio']);
        $this->assertSame('reservation_form', $bare['ui']['type']);
        $this->assertNull($bare['ui']['quote']);
        $this->assertArrayNotHasKey('check_in', $bare['ui']['prefill']);

        $past = $tools->dispatch('start_reservation', ['unit_type_slug' => 'studio', 'check_in' => '2020-01-01', 'check_out' => '2020-01-05']);
        $this->assertSame('reservation_form', $past['ui']['type']);
        $this->assertArrayNotHasKey('check_in', $past['ui']['prefill']);

        $this->assertNull($tools->dispatch('start_reservation', ['unit_type_slug' => 'nope'])['ui']);
    }

    public function test_start_reservation_does_not_open_a_form_for_dates_that_are_not_available(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 600000, 'max_adults' => 2, 'is_active' => true]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $checkIn = CarbonImmutable::now()->addDays(5);

        $result = (new ApartmentConciergeTools($apartment, $conversation, 'en'))->dispatch('start_reservation', [
            'unit_type_slug' => 'studio', 'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(2)->toDateString(),
        ]);

        $this->assertSame('availability', $result['ui']['type']);
        $this->assertFalse($result['ui']['available']);
    }

    public function test_unit_search_results_carry_the_photos_and_details_the_stage_needs(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $unit = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'base_price' => 600000, 'bedrooms' => 0, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $unit->images()->create(['image_url' => 'https://example.test/studio.jpg', 'tags' => ['living_room'], 'alt_text' => 'Living', 'sort_order' => 0]);
        $conversation = Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $checkIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        UnitInventory::create(['unit_type_id' => $unit->id, 'stay_date' => $checkIn->toDateString(), 'total_units' => 1, 'booked_units' => 0, 'price' => 600000]);

        $result = (new ApartmentConciergeTools($apartment, $conversation, 'en'))->dispatch('search_units', [
            'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDay()->toDateString(), 'adults' => 2,
        ]);

        $ui = $result['ui']['units'][0];
        $this->assertSame('https://example.test/studio.jpg', $ui['images'][0]['url']);
        $this->assertSame(600000.0, $ui['total_price']);
        $this->assertSame(2, $result['ui']['adults']);
        // The model only ever receives the compact result, never the photo gallery.
        $this->assertStringNotContainsString('images', $result['text']);
    }
}
