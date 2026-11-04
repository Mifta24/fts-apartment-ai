<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
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

    private string $moveIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar',
            'default_locale' => 'en', 'whatsapp' => '+62 812-3456-7890', 'phone' => '+62 361 771234', 'email' => 'front@demo.test',
            'security_deposit_months' => 2, 'min_monthly_lease_months' => 1,
        ]);

        $this->deluxe = $this->apartment->unitTypes()->create([
            'name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 10000000, 'max_adults' => 2, 'max_children' => 1,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $this->suite = $this->apartment->unitTypes()->create([
            'name' => 'Family Suite', 'slug' => 'family-suite', 'monthly_price' => 20000000, 'max_adults' => 4, 'max_children' => 2,
            'is_active' => true, 'sort_order' => 1,
        ]);

        $today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
        $this->moveIn = $today->addDays(10)->toDateString();

        $now = now();
        foreach ([$this->deluxe->id => 2, $this->suite->id => 1] as $unitTypeId => $units) {
            UnitInventory::insert(collect(range(10, 90))->map(fn (int $offset) => [
                'unit_type_id' => $unitTypeId, 'stay_date' => $today->addDays($offset)->toDateString(),
                'total_units' => $units, 'booked_units' => 0, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lease(array $overrides = []): array
    {
        return [
            'unit_type_slug' => 'deluxe-king', 'rental_type' => 'monthly', 'lease_months' => 2, 'check_in' => $this->moveIn,
            'adults' => 2, 'children' => 0, 'units' => 1, 'locale' => 'en',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides = []): array
    {
        return $this->lease([
            'guest_name' => 'John Tan', 'contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 1111',
            'special_request' => 'High floor', ...$overrides,
        ]);
    }

    /**
     * @return list<int>
     */
    private function heldUnits(UnitType $unitType): array
    {
        return UnitInventory::where('unit_type_id', $unitType->id)->orderBy('stay_date')->pluck('booked_units')->unique()->values()->all();
    }

    public function test_quote_prices_the_whole_lease_and_the_deposit_for_every_unit(): void
    {
        $this->postJson('/demo/apply/quote', $this->lease(['units' => 2]))
            ->assertOk()
            ->assertJson([
                'available' => true, 'rental_type' => 'monthly', 'lease_months' => 2, 'rate' => 10000000, 'rate_period' => 'month',
                'grand_total' => 40000000, 'deposit_total' => 40000000, 'currency' => 'IDR',
            ])
            ->assertJsonPath('move_out', CarbonImmutable::parse($this->moveIn)->addMonthsNoOverflow(2)->toDateString());
    }

    public function test_quote_rejects_invalid_requests_in_the_guest_language(): void
    {
        $yesterday = CarbonImmutable::now('Asia/Makassar')->subDay()->toDateString();

        $this->postJson('/demo/apply/quote', $this->lease(['check_in' => $yesterday, 'locale' => 'id']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.check_in.0', 'Tanggal masuk tidak boleh di masa lalu.');

        $this->postJson('/demo/apply/quote', $this->lease(['check_in' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_in');
    }

    public function test_nightly_rentals_are_not_offered_any_more(): void
    {
        $this->postJson('/demo/apply/quote', $this->lease(['rental_type' => 'daily']))->assertUnprocessable()->assertJsonValidationErrors('rental_type');
        $this->postJson('/demo/apply/quote', $this->lease(['rental_type' => null]))->assertUnprocessable()->assertJsonValidationErrors('rental_type');
        $this->postJson('/demo/apply', $this->application(['rental_type' => 'daily']))->assertUnprocessable()->assertJsonValidationErrors('rental_type');

        $this->assertSame(0, Booking::count());
    }

    public function test_quote_rejects_a_household_larger_than_the_units_can_hold(): void
    {
        $this->postJson('/demo/apply/quote', $this->lease(['adults' => 3]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.adults.0', 'This unit does not fit that many residents. Choose a larger unit or add another unit.');

        $this->postJson('/demo/apply/quote', $this->lease(['adults' => 3, 'units' => 2]))->assertOk();
    }

    public function test_quote_rejects_unknown_and_inactive_units(): void
    {
        $this->deluxe->update(['is_active' => false]);

        $this->postJson('/demo/apply/quote', $this->lease())->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
        $this->postJson('/demo/apply/quote', $this->lease(['unit_type_slug' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
    }

    public function test_an_unavailable_unit_offers_alternatives_that_fit_the_household(): void
    {
        UnitInventory::where('unit_type_id', $this->deluxe->id)->update(['booked_units' => 2]);

        $this->postJson('/demo/apply/quote', $this->lease())
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available from that move-in date for that lease term.')
            ->assertJsonPath('alternatives.0.slug', 'family-suite')
            ->assertJsonPath('alternatives.0.total', 40000000);
    }

    public function test_quote_asks_for_more_units_than_are_free_when_applying_for_several_units(): void
    {
        $this->postJson('/demo/apply/quote', $this->lease(['unit_type_slug' => 'family-suite', 'units' => 2, 'adults' => 2]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available from that move-in date for that lease term.');
    }

    public function test_submitting_creates_a_pending_application_holds_the_units_and_returns_handover_links(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $response = $this->postJson('/demo/apply', $this->application(['units' => 2, 'adults' => 3, 'guest_token' => $conversation->guest_token]))
            ->assertCreated()
            ->assertJsonPath('status', Booking::STATUS_PENDING)
            ->assertJsonPath('total', 40000000)
            ->assertJsonPath('deposit_total', 40000000)
            ->assertJsonPath('handover.phone_url', 'tel:+62361771234');

        $booking = Booking::firstOrFail();
        $this->assertMatchesRegularExpression('/^AP-[A-Z0-9]{6}$/', $booking->reference);
        $response->assertJsonPath('reference', $booking->reference);
        $this->assertSame(2, $booking->unit_count);
        $this->assertSame('whatsapp', $booking->contact_type);
        $this->assertSame('+62 812 0000 1111', $booking->guest_phone);
        $this->assertNull($booking->guest_email);
        $this->assertSame('High floor', $booking->notes);
        $this->assertSame($conversation->id, $booking->conversation_id);

        $held = UnitInventory::where('unit_type_id', $this->deluxe->id)->where('booked_units', 2);
        $this->assertSame((int) $booking->check_in->diffInDays($booking->check_out), $held->count());

        $whatsapp = $response->json('handover.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $whatsapp);
        $message = urldecode(substr($whatsapp, strlen('https://wa.me/6281234567890?text=')));
        $this->assertStringContainsString('apply to rent', $message);
        $this->assertStringContainsString('Name: John Tan', $message);
        $this->assertStringContainsString('Move-in: ', $message);
        $this->assertStringContainsString('Lease ends: ', $message);
        $this->assertStringContainsString('Units: 2', $message);
        $this->assertStringContainsString('Unit type: Deluxe King', $message);
        $this->assertStringContainsString('Note: High floor', $message);
        $this->assertStringContainsString("Reference: {$booking->reference}", $message);
        $this->assertStringStartsWith('mailto:front@demo.test?subject=', $response->json('handover.email_url'));
    }

    public function test_email_contacts_are_stored_as_email_and_validated(): void
    {
        $this->postJson('/demo/apply', $this->application(['contact_type' => 'email', 'contact_value' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact_value.0', 'Please enter a valid email address.');

        $this->postJson('/demo/apply', $this->application(['contact_type' => 'phone', 'contact_value' => 'call me']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_value');

        $this->postJson('/demo/apply', $this->application(['contact_type' => 'email', 'contact_value' => 'john@example.com']))->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertSame('john@example.com', $booking->guest_email);
        $this->assertNull($booking->guest_phone);
    }

    public function test_the_last_free_unit_can_only_be_applied_for_once(): void
    {
        $this->postJson('/demo/apply', $this->application(['unit_type_slug' => 'family-suite']))->assertCreated();

        $this->postJson('/demo/apply', $this->application(['unit_type_slug' => 'family-suite', 'guest_name' => 'Late Applicant']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit_type_slug.0', 'This unit type is not available from that move-in date for that lease term.');

        $this->assertSame(1, Booking::count());
    }

    public function test_reference_is_unique_per_application(): void
    {
        $this->postJson('/demo/apply', $this->application())->assertCreated();
        $this->postJson('/demo/apply', $this->application())->assertCreated();

        $this->assertSame(2, Booking::distinct()->count('reference'));
    }

    public function test_unpublished_apartments_reject_applications(): void
    {
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->postJson('/draft/apply', $this->application())->assertNotFound();
        $this->postJson('/draft/apply/quote', $this->lease())->assertNotFound();
    }

    public function test_applications_are_rate_limited(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->postJson('/demo/apply/quote', $this->lease())->assertOk();
        }

        $this->postJson('/demo/apply/quote', $this->lease())->assertTooManyRequests();
    }

    public function test_rejecting_or_cancelling_a_multi_unit_application_releases_every_held_unit(): void
    {
        $user = User::factory()->create();
        $this->apartment->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        foreach ([Booking::STATUS_REJECTED, Booking::STATUS_CANCELLED] as $status) {
            $this->postJson('/demo/apply', $this->application(['units' => 2, 'adults' => 3]))->assertCreated();
            $booking = Booking::latest('id')->firstOrFail();
            $this->assertSame([2, 0], $this->heldUnits($this->deluxe));

            $this->actingAs($user)->patch(route('admin.bookings.status', $booking), ['status' => $status])->assertRedirect();

            $this->assertSame([0], $this->heldUnits($this->deluxe));
            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_an_application_made_from_the_chat_form_is_kept_in_the_conversation(): void
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        $response = $this->postJson('/demo/apply', $this->application(['guest_token' => $conversation->guest_token]))->assertCreated();

        $message = $conversation->messages()->firstOrFail();
        $this->assertSame('assistant', $message->role);
        $this->assertStringContainsString($response->json('reference'), $message->content);
        $this->assertSame('booking_confirmation', $message->ui_payload[0]['type']);
        $this->assertSame($response->json('reference'), $message->ui_payload[0]['booking']['booking_reference']);
        $this->assertSame(2, $message->ui_payload[0]['booking']['lease_months']);
        $this->assertEquals(20000000, $message->ui_payload[0]['booking']['total_price']);
        $this->assertSame($conversation->id, Booking::firstOrFail()->conversation_id);
    }

    public function test_an_application_without_a_conversation_leaves_no_chat_message(): void
    {
        $this->postJson('/demo/apply', $this->application())->assertCreated();

        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_the_old_reservation_page_address_redirects_to_the_application_page(): void
    {
        $this->get('/demo/reservation?lang=id&unit=deluxe-king')
            ->assertRedirect(route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'id', 'unit' => 'deluxe-king']))
            ->assertStatus(301);
    }
}
