<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Services\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $deluxe;

    private UnitType $suite;

    private string $checkIn;

    private string $checkOut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar',
            'default_locale' => 'en', 'whatsapp' => '+62 812-3456-7890', 'phone' => '+62 361 771234', 'email' => 'front@demo.test',
        ]);

        $this->deluxe = $this->apartment->unitTypes()->create([
            'name' => 'Deluxe King', 'slug' => 'deluxe-king', 'base_price' => 1000000, 'max_adults' => 2, 'max_children' => 1,
            'extra_bed_available' => true, 'extra_bed_price' => 250000, 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->suite = $this->apartment->unitTypes()->create([
            'name' => 'Family Suite', 'slug' => 'family-suite', 'base_price' => 2000000, 'max_adults' => 4, 'max_children' => 2,
            'is_active' => true, 'sort_order' => 1,
        ]);

        $today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
        $this->checkIn = $today->addDays(10)->toDateString();
        $this->checkOut = $today->addDays(13)->toDateString();

        foreach ([$this->deluxe->id => [2, 1000000], $this->suite->id => [1, 2000000]] as $unitTypeId => [$units, $price]) {
            foreach (range(10, 12) as $offset) {
                UnitInventory::create([
                    'unit_type_id' => $unitTypeId, 'stay_date' => $today->addDays($offset)->toDateString(),
                    'total_units' => $units, 'booked_units' => 0, 'price' => $price,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stay(array $overrides = []): array
    {
        return [
            'unit_type_slug' => 'deluxe-king', 'check_in' => $this->checkIn, 'check_out' => $this->checkOut,
            'adults' => 2, 'children' => 0, 'units' => 1, 'locale' => 'en',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return $this->stay([
            'guest_name' => 'John Tan', 'contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 1111',
            'special_request' => 'High floor', ...$overrides,
        ]);
    }

    public function test_quote_prices_every_night_for_every_unit(): void
    {
        $this->postJson('/demo/reservation/quote', $this->stay(['units' => 2, 'extra_bed' => true]))
            ->assertOk()
            ->assertJson([
                'available' => true, 'nights' => 3, 'unit_total' => 6000000, 'extra_bed_total' => 1500000,
                'grand_total' => 7500000, 'currency' => 'IDR',
            ]);
    }

    public function test_quote_rejects_invalid_dates_in_the_guest_language(): void
    {
        $yesterday = CarbonImmutable::now('Asia/Makassar')->subDay()->toDateString();

        $this->postJson('/demo/reservation/quote', $this->stay(['check_in' => $yesterday, 'locale' => 'id']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.check_in.0', 'Tanggal check-in tidak boleh di masa lalu.');

        $this->postJson('/demo/reservation/quote', $this->stay(['check_out' => $this->checkIn]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.check_out.0', 'Check-out must be after check-in.');
    }

    public function test_quote_rejects_stays_longer_than_the_limit(): void
    {
        $tooLong = CarbonImmutable::parse($this->checkIn)->addDays(ReservationService::MAX_NIGHTS + 1)->toDateString();

        $this->postJson('/demo/reservation/quote', $this->stay(['check_out' => $tooLong]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_out');
    }

    public function test_quote_rejects_a_party_larger_than_the_units_can_hold(): void
    {
        $this->postJson('/demo/reservation/quote', $this->stay(['adults' => 3]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.adults.0', 'This unit does not fit that many residents. Choose a larger unit or add another unit.');

        $this->postJson('/demo/reservation/quote', $this->stay(['adults' => 3, 'units' => 2]))->assertOk();
    }

    public function test_quote_rejects_unknown_and_inactive_units(): void
    {
        $this->deluxe->update(['is_active' => false]);

        $this->postJson('/demo/reservation/quote', $this->stay())->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
        $this->postJson('/demo/reservation/quote', $this->stay(['unit_type_slug' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
    }

    public function test_unavailable_unit_offers_alternatives_that_fit_the_party(): void
    {
        UnitInventory::where('unit_type_id', $this->deluxe->id)->update(['booked_units' => 2]);

        $this->postJson('/demo/reservation/quote', $this->stay())
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available for those dates.')
            ->assertJsonPath('alternatives.0.slug', 'family-suite')
            ->assertJsonPath('alternatives.0.total', 6000000);
    }

    public function test_quote_asks_for_more_units_than_are_free_when_booking_several_units(): void
    {
        $this->postJson('/demo/reservation/quote', $this->stay(['unit_type_slug' => 'family-suite', 'units' => 2, 'adults' => 2]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available for those dates.');
    }

    public function test_submitting_creates_a_pending_request_holds_inventory_and_returns_handover_links(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $response = $this->postJson('/demo/reservation', $this->request(['units' => 2, 'adults' => 3, 'guest_token' => $conversation->guest_token]))
            ->assertCreated()
            ->assertJsonPath('status', Booking::STATUS_PENDING)
            ->assertJsonPath('total', 6000000)
            ->assertJsonPath('handover.phone_url', 'tel:+62361771234');

        $booking = Booking::firstOrFail();
        $this->assertMatchesRegularExpression('/^BK-[A-Z0-9]{6}$/', $booking->reference);
        $response->assertJsonPath('reference', $booking->reference);
        $this->assertSame(2, $booking->unit_count);
        $this->assertSame('whatsapp', $booking->contact_type);
        $this->assertSame('+62 812 0000 1111', $booking->guest_phone);
        $this->assertNull($booking->guest_email);
        $this->assertSame('High floor', $booking->notes);
        $this->assertSame($conversation->id, $booking->conversation_id);
        $this->assertSame([2, 2, 2], UnitInventory::where('unit_type_id', $this->deluxe->id)->orderBy('stay_date')->pluck('booked_units')->all());

        $whatsapp = $response->json('handover.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $whatsapp);
        $message = urldecode(substr($whatsapp, strlen('https://wa.me/6281234567890?text=')));
        $this->assertStringContainsString('Name: John Tan', $message);
        $this->assertStringContainsString('Move-in: ', $message);
        $this->assertStringContainsString('Units: 2', $message);
        $this->assertStringContainsString('Unit type: Deluxe King', $message);
        $this->assertStringContainsString('Special request: High floor', $message);
        $this->assertStringContainsString("Reference: {$booking->reference}", $message);
        $this->assertStringStartsWith('mailto:front@demo.test?subject=', $response->json('handover.email_url'));
    }

    public function test_email_contacts_are_stored_as_email_and_validated(): void
    {
        $this->postJson('/demo/reservation', $this->request(['contact_type' => 'email', 'contact_value' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact_value.0', 'Please enter a valid email address.');

        $this->postJson('/demo/reservation', $this->request(['contact_type' => 'phone', 'contact_value' => 'call me']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_value');

        $this->postJson('/demo/reservation', $this->request(['contact_type' => 'email', 'contact_value' => 'john@example.com']))->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertSame('john@example.com', $booking->guest_email);
        $this->assertNull($booking->guest_phone);
    }

    public function test_the_last_free_unit_can_only_be_requested_once(): void
    {
        $this->postJson('/demo/reservation', $this->request(['unit_type_slug' => 'family-suite']))->assertCreated();

        $this->postJson('/demo/reservation', $this->request(['unit_type_slug' => 'family-suite', 'guest_name' => 'Late Guest']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available for those dates.');

        $this->assertSame(1, Booking::count());
    }

    public function test_reference_is_unique_per_request(): void
    {
        $this->postJson('/demo/reservation', $this->request())->assertCreated();
        $this->postJson('/demo/reservation', $this->request())->assertCreated();

        $this->assertSame(2, Booking::distinct()->count('reference'));
    }

    public function test_unpublished_apartments_reject_reservations(): void
    {
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->postJson('/draft/reservation', $this->request())->assertNotFound();
        $this->postJson('/draft/reservation/quote', $this->stay())->assertNotFound();
    }

    public function test_reservation_requests_are_rate_limited(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->postJson('/demo/reservation/quote', $this->stay())->assertOk();
        }

        $this->postJson('/demo/reservation/quote', $this->stay())->assertTooManyRequests();
    }

    public function test_cancelling_a_multi_unit_booking_releases_every_held_unit(): void
    {
        $this->postJson('/demo/reservation', $this->request(['units' => 2, 'adults' => 3]))->assertCreated();
        $booking = Booking::firstOrFail();

        $user = User::factory()->create();
        $this->apartment->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($user)
            ->patch(route('admin.bookings.status', $booking), ['status' => Booking::STATUS_CANCELLED])
            ->assertRedirect();

        $this->assertSame([0, 0, 0], UnitInventory::where('unit_type_id', $this->deluxe->id)->orderBy('stay_date')->pluck('booked_units')->all());
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_long_stays_are_quoted_with_the_weekly_or_monthly_discount(): void
    {
        $this->apartment->update(['weekly_discount_percent' => 10, 'monthly_discount_percent' => 25]);

        $today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
        foreach (range(20, 49) as $offset) {
            UnitInventory::create([
                'unit_type_id' => $this->deluxe->id, 'stay_date' => $today->addDays($offset)->toDateString(),
                'total_units' => 2, 'booked_units' => 0, 'price' => 1000000,
            ]);
        }

        $stay = fn (int $nights) => $this->stay([
            'check_in' => $today->addDays(20)->toDateString(),
            'check_out' => $today->addDays(20 + $nights)->toDateString(),
        ]);

        $this->postJson('/demo/reservation/quote', $stay(6))
            ->assertOk()
            ->assertJsonPath('discount_percent', 0)
            ->assertJsonPath('grand_total', 6000000);

        $this->postJson('/demo/reservation/quote', $stay(7))
            ->assertOk()
            ->assertJsonPath('subtotal', 7000000)
            ->assertJsonPath('discount_percent', 10)
            ->assertJsonPath('discount_total', 700000)
            ->assertJsonPath('grand_total', 6300000);

        $this->postJson('/demo/reservation/quote', $stay(28))
            ->assertOk()
            ->assertJsonPath('discount_percent', 25)
            ->assertJsonPath('grand_total', 21000000);

        $this->postJson('/demo/reservation', [...$stay(28), 'guest_name' => 'Long Stayer', 'contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 2222'])
            ->assertCreated()
            ->assertJsonPath('total', 21000000);
    }

    public function test_long_stays_are_not_discounted_when_the_apartment_offers_no_long_stay_rate(): void
    {
        $today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
        foreach (range(20, 27) as $offset) {
            UnitInventory::create([
                'unit_type_id' => $this->deluxe->id, 'stay_date' => $today->addDays($offset)->toDateString(),
                'total_units' => 2, 'booked_units' => 0, 'price' => 1000000,
            ]);
        }

        $this->postJson('/demo/reservation/quote', $this->stay([
            'check_in' => $today->addDays(20)->toDateString(),
            'check_out' => $today->addDays(28)->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('discount_percent', 0)
            ->assertJsonPath('grand_total', 8000000);
    }

    public function test_a_request_made_from_the_chat_form_is_kept_in_the_conversation(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $response = $this->postJson('/demo/reservation', $this->request(['guest_token' => $conversation->guest_token]))->assertCreated();

        $message = $conversation->messages()->firstOrFail();
        $this->assertSame('assistant', $message->role);
        $this->assertStringContainsString($response->json('reference'), $message->content);
        $this->assertSame('booking_confirmation', $message->ui_payload[0]['type']);
        $this->assertSame($response->json('reference'), $message->ui_payload[0]['booking']['booking_reference']);
        $this->assertSame(3, $message->ui_payload[0]['booking']['nights']);
        $this->assertEquals(3000000, $message->ui_payload[0]['booking']['total_price']);
        $this->assertSame($conversation->id, Booking::firstOrFail()->conversation_id);
    }

    public function test_a_request_without_a_conversation_leaves_no_chat_message(): void
    {
        $this->postJson('/demo/reservation', $this->request())->assertCreated();

        $this->assertDatabaseCount('conversation_messages', 0);
    }
}
