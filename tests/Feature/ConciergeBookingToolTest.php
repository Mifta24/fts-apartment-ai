<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\HandoverRequest;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Notifications\NewViewingRequest;
use App\Services\Concierge\ApartmentConciergeTools;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConciergeBookingToolTest extends TestCase
{
    use RefreshDatabase;

    private function apartment(): Apartment
    {
        return Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'security_deposit_months' => 2, 'min_monthly_lease_months' => 1]);
    }

    private function conversation(Apartment $apartment, string $locale = 'en'): Conversation
    {
        return Conversation::create(['apartment_id' => $apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => $locale]);
    }

    private function openInventory(UnitType $unit, CarbonImmutable $from, int $days, int $units): void
    {
        $now = now();

        UnitInventory::insert(collect(range(0, $days - 1))->map(fn (int $offset) => [
            'unit_type_id' => $unit->id, 'stay_date' => $from->addDays($offset)->toDateString(),
            'total_units' => $units, 'booked_units' => 0, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    public function test_ai_booking_tool_creates_a_reference_and_holds_the_requested_units(): void
    {
        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 5000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $conversation = $this->conversation($apartment);

        $moveIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        $this->openInventory($unit, $moveIn, 120, 3);

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $input = [
            'unit_type_slug' => 'deluxe-king', 'check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 2,
            'adults' => 2, 'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222',
        ];

        $quote = $tools->dispatch('check_availability', $input);
        $this->assertSame(10000000.0, $quote['ui']['quote']['grand_total']);
        $this->assertSame(10000000.0, $quote['ui']['quote']['deposit_total']);

        $result = $tools->dispatch('create_booking_request', [...$input, 'units' => 2]);

        $booking = Booking::firstOrFail();
        $this->assertSame($booking->reference, $result['ui']['booking']['booking_reference']);
        $this->assertSame(2, $booking->unit_count);
        $this->assertSame(20000000.0, (float) $booking->total_price);
        $this->assertSame([2], UnitInventory::where('booked_units', '>', 0)->pluck('booked_units')->unique()->values()->all());

        $tooMany = $tools->dispatch('create_booking_request', [...$input, 'units' => 3]);
        $this->assertNull($tooMany['ui']);
        $this->assertSame(1, Booking::count());
    }

    public function test_a_retried_booking_request_does_not_hold_the_units_twice(): void
    {
        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 4000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $conversation = $this->conversation($apartment);

        $moveIn = CarbonImmutable::now()->addDays(3)->startOfDay();
        $this->openInventory($unit, $moveIn, 120, 5);

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $input = ['unit_type_slug' => 'studio', 'check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 1, 'adults' => 1, 'guest_name' => 'Ayu', 'guest_phone' => '0811111222'];

        $first = $tools->dispatch('create_booking_request', $input);
        $retry = $tools->dispatch('create_booking_request', $input);

        $this->assertSame($first['ui']['booking']['booking_reference'], $retry['ui']['booking']['booking_reference']);
        $this->assertSame(1, Booking::count());
        $this->assertSame([1], UnitInventory::where('booked_units', '>', 0)->pluck('booked_units')->unique()->values()->all());

        $other = $tools->dispatch('create_booking_request', [...$input, 'guest_phone' => '0899999999']);
        $this->assertNotSame($first['ui']['booking']['booking_reference'], $other['ui']['booking']['booking_reference']);
        $this->assertSame(2, Booking::count());
    }

    public function test_a_second_handover_in_the_same_conversation_does_not_notify_staff_again(): void
    {
        $apartment = $this->apartment();
        $tools = new ApartmentConciergeTools($apartment, $this->conversation($apartment), 'en');
        $input = ['reason' => 'complaint', 'summary' => 'Noisy unit'];

        $this->assertSame('handover', $tools->dispatch('request_human_handover', $input)['ui']['type']);
        $this->assertStringContainsString('already', $tools->dispatch('request_human_handover', $input)['text']);
        $this->assertSame(1, HandoverRequest::count());
    }

    public function test_ai_booking_tool_applies_the_same_rules_as_the_application_form(): void
    {
        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 4000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $conversation = $this->conversation($apartment);

        $start = CarbonImmutable::now()->subDays(5)->startOfDay();
        $this->openInventory($unit, $start, 200, 3);

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $tomorrow = CarbonImmutable::now()->addDay()->startOfDay();
        $valid = [
            'unit_type_slug' => 'studio', 'check_in' => $tomorrow->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 3,
            'adults' => 1, 'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222',
        ];

        $rejected = [
            'a past move-in date' => [...$valid, 'check_in' => $start->toDateString()],
            'a missing lease length' => [...$valid, 'lease_months' => null],
            'a monthly lease of a full year' => [...$valid, 'lease_months' => 12],
            'a yearly lease of 15 months' => [...$valid, 'rental_type' => 'yearly', 'lease_months' => 15],
            'a nightly stay' => [...$valid, 'rental_type' => 'daily', 'lease_months' => null, 'check_out' => $tomorrow->addDays(2)->toDateString()],
            'a phone that is not a number' => [...$valid, 'guest_phone' => 'abc'],
            'an invalid email' => [...$valid, 'guest_email' => 'not-an-email'],
            'too many units' => [...$valid, 'units' => 11],
            'a blank name' => [...$valid, 'guest_name' => '  '],
            'a date that is not a date' => [...$valid, 'check_in' => 'besok'],
            'an impossible date' => [...$valid, 'check_in' => '2099-02-31'],
        ];

        foreach ($rejected as $case => $input) {
            $result = $tools->dispatch('create_booking_request', $input);
            $this->assertNull($result['ui'], "Created an application with {$case}.");
        }

        $this->assertSame(0, Booking::count());

        $unit->update(['is_active' => false]);
        $this->assertNull($tools->dispatch('create_booking_request', $valid)['ui']);
        $this->assertSame('Unit type not found.', $tools->dispatch('check_availability', $valid)['text']);
        $this->assertSame(0, Booking::count());

        $unit->update(['is_active' => true]);
        $this->assertSame('booking_confirmation', $tools->dispatch('create_booking_request', $valid)['ui']['type']);
        $this->assertSame(1, Booking::count());
    }

    public function test_ai_tools_report_missing_or_malformed_arguments_instead_of_failing(): void
    {
        $apartment = $this->apartment();
        $tools = new ApartmentConciergeTools($apartment, $this->conversation($apartment), 'en');

        foreach ([
            'create_booking_request' => ['unit_type_slug' => 'studio', 'check_in' => '2099-01-01'],
            'check_availability' => ['check_in' => '2099-01-01'],
            'get_unit_detail' => [],
            'request_human_handover' => ['reason' => 'complaint'],
            'schedule_viewing' => ['unit_type_slug' => 'studio'],
            'start_reservation' => ['unit_type_slug' => ['studio']],
        ] as $tool => $input) {
            $result = $tools->dispatch($tool, $input);

            $this->assertNull($result['ui'], $tool);
            $this->assertStringContainsString('Missing or invalid', $result['text'], $tool);
        }

        $this->assertStringContainsString('YYYY-MM-DD', $tools->dispatch('search_units', ['check_in' => 'besok', 'lease_months' => 3])['text']);
        $this->assertSame(0, HandoverRequest::count());
    }

    public function test_ai_tools_quote_leases_and_filter_by_bedrooms(): void
    {
        $apartment = $this->apartment();
        $studio = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 6000000, 'bedrooms' => 0, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $family = $apartment->unitTypes()->create(['name' => 'Two Bedroom', 'slug' => 'two-bedroom', 'monthly_price' => 15000000, 'bedrooms' => 2, 'bathrooms' => 2, 'floor_range' => '15–26', 'max_adults' => 4, 'max_children' => 2, 'is_active' => true]);
        $conversation = $this->conversation($apartment);

        $moveIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        foreach ([$studio, $family] as $unit) {
            $this->openInventory($unit, $moveIn, 120, 2);
        }

        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $lease = ['check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 3];

        $quote = $tools->dispatch('check_availability', [...$lease, 'unit_type_slug' => 'two-bedroom'])['ui']['quote'];
        $this->assertSame(45000000.0, $quote['grand_total']);
        $this->assertSame(30000000.0, $quote['deposit_total']);
        $this->assertSame($moveIn->addMonthsNoOverflow(3)->toDateString(), $quote['check_out']);
        $this->assertArrayNotHasKey('discount_percent', $quote);

        $search = $tools->dispatch('search_units', [...$lease, 'adults' => 2, 'bedrooms' => 1])['ui']['units'];
        $this->assertSame(['two-bedroom'], array_column($search, 'unit_type_slug'));
        $this->assertSame(45000000.0, $search[0]['total_price']);
        $this->assertSame('15–26', $search[0]['floor_range']);

        $detail = json_decode($tools->dispatch('get_unit_detail', ['unit_type_slug' => 'studio'])['text'], true);
        $this->assertSame(0, $detail['bedrooms']);
        $this->assertArrayNotHasKey('long_stay', $detail);
        $this->assertArrayNotHasKey('extra_bed_available', $detail);
    }

    public function test_search_units_without_dates_lists_every_active_unit_type_with_starting_rents(): void
    {
        $apartment = $this->apartment();
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 5000000, 'max_adults' => 2, 'max_children' => 0, 'bedrooms' => 0, 'is_active' => true]);
        $apartment->unitTypes()->create(['name' => 'Two Bedroom', 'slug' => 'two-bedroom', 'monthly_price' => 15000000, 'max_adults' => 4, 'max_children' => 2, 'bedrooms' => 2, 'is_active' => true]);
        $apartment->unitTypes()->create(['name' => 'Hidden', 'slug' => 'hidden', 'monthly_price' => 1000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => false]);

        $result = (new ApartmentConciergeTools($apartment, $this->conversation($apartment, 'id'), 'id'))->dispatch('search_units', []);

        $this->assertSame('unit_results', $result['ui']['type']);
        $this->assertSame(['studio', 'two-bedroom'], array_column($result['ui']['units'], 'unit_type_slug'));
        $this->assertSame(5000000.0, (float) $result['ui']['units'][0]['monthly_price']);
        $this->assertArrayNotHasKey('starting_price', $result['ui']['units'][0]);
        $this->assertArrayNotHasKey('total_price', $result['ui']['units'][0]);
    }

    public function test_start_reservation_opens_a_prefilled_form_and_quotes_the_lease(): void
    {
        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 5000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $unit->images()->create(['image_url' => 'https://example.test/a.jpg', 'tags' => ['bedroom'], 'alt_text' => 'Bedroom', 'sort_order' => 0]);
        $conversation = $this->conversation($apartment);

        $moveIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        $this->openInventory($unit, $moveIn, 120, 3);

        $result = (new ApartmentConciergeTools($apartment, $conversation, 'en'))->dispatch('start_reservation', [
            'unit_type_slug' => 'deluxe-king', 'check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 2, 'adults' => 2,
        ]);

        $this->assertSame('reservation_form', $result['ui']['type']);
        $this->assertSame('deluxe-king', $result['ui']['unit']['unit_type_slug']);
        $this->assertSame('https://example.test/a.jpg', $result['ui']['unit']['images'][0]['url']);
        $this->assertSame(
            ['adults' => 2, 'children' => 0, 'units' => 1, 'rental_type' => 'monthly', 'lease_months' => 2, 'check_in' => $moveIn->toDateString()],
            $result['ui']['prefill'],
        );
        $this->assertSame(10000000.0, $result['ui']['quote']['grand_total']);
        $this->assertSame(0, Booking::count());
    }

    public function test_start_reservation_works_without_dates_and_ignores_past_ones(): void
    {
        $apartment = $this->apartment();
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 6000000, 'max_adults' => 2, 'is_active' => true]);
        $tools = new ApartmentConciergeTools($apartment, $this->conversation($apartment), 'en');

        $bare = $tools->dispatch('start_reservation', ['unit_type_slug' => 'studio']);
        $this->assertSame('reservation_form', $bare['ui']['type']);
        $this->assertNull($bare['ui']['quote']);
        $this->assertArrayNotHasKey('check_in', $bare['ui']['prefill']);

        $past = $tools->dispatch('start_reservation', ['unit_type_slug' => 'studio', 'check_in' => '2020-01-01', 'lease_months' => 3]);
        $this->assertSame('reservation_form', $past['ui']['type']);
        $this->assertArrayNotHasKey('check_in', $past['ui']['prefill']);

        $this->assertNull($tools->dispatch('start_reservation', ['unit_type_slug' => 'nope'])['ui']);
    }

    public function test_start_reservation_does_not_open_a_form_for_a_lease_that_is_not_available(): void
    {
        $apartment = $this->apartment();
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 6000000, 'max_adults' => 2, 'is_active' => true]);
        $moveIn = CarbonImmutable::now()->addDays(5);

        $result = (new ApartmentConciergeTools($apartment, $this->conversation($apartment), 'en'))->dispatch('start_reservation', [
            'unit_type_slug' => 'studio', 'check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 3,
        ]);

        $this->assertSame('availability', $result['ui']['type']);
        $this->assertFalse($result['ui']['available']);
    }

    public function test_unit_search_results_carry_the_photos_and_details_the_stage_needs(): void
    {
        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 6000000, 'bedrooms' => 0, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $unit->images()->create(['image_url' => 'https://example.test/studio.jpg', 'tags' => ['living_room'], 'alt_text' => 'Living', 'sort_order' => 0]);

        $moveIn = CarbonImmutable::now()->addDays(5)->startOfDay();
        $this->openInventory($unit, $moveIn, 120, 1);

        $result = (new ApartmentConciergeTools($apartment, $this->conversation($apartment), 'en'))->dispatch('search_units', [
            'check_in' => $moveIn->toDateString(), 'rental_type' => 'monthly', 'lease_months' => 1, 'adults' => 2,
        ]);

        $ui = $result['ui']['units'][0];
        $this->assertSame('https://example.test/studio.jpg', $ui['images'][0]['url']);
        $this->assertSame(6000000.0, $ui['total_price']);
        $this->assertSame(2, $result['ui']['adults']);
        // The model only ever receives the compact result, never the photo gallery.
        $this->assertStringNotContainsString('images', $result['text']);
    }

    public function test_the_concierge_records_a_viewing_request_and_tells_the_team(): void
    {
        Notification::fake();

        $apartment = $this->apartment();
        $unit = $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 6000000, 'max_adults' => 2, 'is_active' => true]);
        $staff = User::factory()->create();
        $apartment->users()->attach($staff->id, ['role' => 'owner', 'status' => 'active']);
        $conversation = $this->conversation($apartment);
        $tools = new ApartmentConciergeTools($apartment, $conversation, 'en');
        $date = CarbonImmutable::now()->addDays(3)->toDateString();
        $input = ['unit_type_slug' => 'studio', 'preferred_date' => $date, 'preferred_time' => '15:30', 'guest_name' => 'Ayu', 'guest_phone' => '+62 811 111 222'];

        $result = $tools->dispatch('schedule_viewing', $input);

        $viewing = ViewingRequest::firstOrFail();
        $this->assertSame($unit->id, $viewing->unit_type_id);
        $this->assertSame($conversation->id, $viewing->conversation_id);
        $this->assertSame('15:30', $viewing->preferred_time);
        $this->assertSame(ViewingRequest::STATUS_REQUESTED, $viewing->status);
        $this->assertStringContainsString($viewing->reference, $result['text']);
        Notification::assertSentTo($staff, NewViewingRequest::class);

        foreach ([
            'a past date' => [...$input, 'preferred_date' => '2020-01-01'],
            'a time that is not a time' => [...$input, 'preferred_time' => '3pm'],
            'a phone that is not a number' => [...$input, 'guest_phone' => 'abc'],
            'an unknown unit' => [...$input, 'unit_type_slug' => 'nope'],
        ] as $case => $bad) {
            $this->assertStringNotContainsString('viewing_reference', $tools->dispatch('schedule_viewing', $bad)['text'], "Recorded a viewing with {$case}.");
        }

        $this->assertSame(1, ViewingRequest::count());
    }
}
