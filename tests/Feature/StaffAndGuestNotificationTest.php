<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\HandoverRequest;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use App\Notifications\BookingRequestReceived;
use App\Notifications\BookingStatusChanged;
use App\Notifications\NewBookingRequest;
use App\Notifications\NewHandoverRequest;
use App\Notifications\StaffReplied;
use App\Services\Concierge\ApartmentConciergeTools;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffAndGuestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $unitType;

    private User $owner;

    private User $inactiveStaff;

    private CarbonImmutable $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'default_locale' => 'en']);
        $this->unitType = $this->apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'translations' => ['ja' => ['name' => 'スタジオ']], 'base_price' => 500000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->owner = User::factory()->create();
        $this->inactiveStaff = User::factory()->create();
        $this->apartment->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'active']);
        $this->apartment->users()->attach($this->inactiveStaff->id, ['role' => 'staff', 'status' => 'suspended']);

        $this->checkIn = CarbonImmutable::now($this->apartment->timezone)->addDays(5)->startOfDay();
        foreach ([0, 1] as $offset) {
            UnitInventory::create(['unit_type_id' => $this->unitType->id, 'stay_date' => $this->checkIn->addDays($offset)->toDateString(), 'total_units' => 2, 'booked_units' => 0, 'price' => 500000]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reservation(array $overrides = []): array
    {
        return [
            'unit_type_slug' => 'studio', 'check_in' => $this->checkIn->toDateString(), 'check_out' => $this->checkIn->addDays(2)->toDateString(),
            'adults' => 2, 'units' => 1, 'locale' => 'id', 'guest_name' => 'Ayu', 'contact_type' => 'email', 'contact_value' => 'ayu@example.test',
            ...$overrides,
        ];
    }

    public function test_a_stay_request_notifies_active_staff_and_acknowledges_a_guest_who_left_an_email(): void
    {
        Notification::fake();

        $this->postJson('/demo/reservation', $this->reservation())->assertCreated();

        $booking = Booking::firstOrFail();
        Notification::assertSentTo($this->owner, NewBookingRequest::class, fn ($notification) => $notification->booking->is($booking));
        Notification::assertNotSentTo($this->inactiveStaff, NewBookingRequest::class);
        Notification::assertSentOnDemand(BookingRequestReceived::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ayu@example.test');
        $this->assertSame('id', $booking->locale);
    }

    public function test_a_guest_who_chose_whatsapp_gets_no_email_but_staff_are_still_told(): void
    {
        Notification::fake();

        $this->postJson('/demo/reservation', $this->reservation(['contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 1111']))->assertCreated();

        Notification::assertSentTo($this->owner, NewBookingRequest::class);
        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertSentOnDemandTimes(BookingRequestReceived::class, 0);
    }

    public function test_a_request_that_finds_no_free_unit_notifies_nobody(): void
    {
        Notification::fake();

        $this->postJson('/demo/reservation', $this->reservation(['units' => 3, 'adults' => 6]))->assertUnprocessable();

        Notification::assertNothingSent();
    }

    public function test_the_concierge_booking_tool_notifies_staff_and_remembers_the_guest_language(): void
    {
        Notification::fake();
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'ja']);

        (new ApartmentConciergeTools($this->apartment, $conversation, 'ja'))->dispatch('create_booking_request', [
            'unit_type_slug' => 'studio', 'check_in' => $this->checkIn->toDateString(), 'check_out' => $this->checkIn->addDays(2)->toDateString(),
            'adults' => 2, 'guest_name' => 'Aki', 'guest_phone' => '+81 90 0000 0000', 'guest_email' => 'aki@example.test',
        ]);

        Notification::assertSentTo($this->owner, NewBookingRequest::class);
        Notification::assertSentOnDemand(BookingRequestReceived::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'aki@example.test');
        $this->assertSame('ja', Booking::firstOrFail()->locale);
    }

    private function handedOverConversation(array $attributes = []): HandoverRequest
    {
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'id', 'status' => Conversation::STATUS_HANDED_OVER, ...$attributes]);

        return HandoverRequest::create(['conversation_id' => $conversation->id, 'reason' => HandoverRequest::REASON_COMPLAINT, 'summary' => 'Noisy unit']);
    }

    public function test_a_staff_reply_is_emailed_to_the_guest_who_left_an_address(): void
    {
        Notification::fake();
        $handover = $this->handedOverConversation(['guest_email' => 'ayu@example.test']);

        $this->actingAs($this->owner)->post(route('admin.handovers.reply', $handover), ['message' => 'Kami segera cek.'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'ayu@example.test'));

        Notification::assertSentOnDemand(StaffReplied::class, function (StaffReplied $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'ayu@example.test'
                && $notification->locale === 'id'
                && str_contains(implode(' ', $mail->introLines), 'Kami segera cek.')
                && str_contains($mail->actionUrl, '/demo?lang=id');
        });
    }

    public function test_a_staff_reply_falls_back_to_the_email_on_the_guests_stay_request(): void
    {
        Notification::fake();
        $handover = $this->handedOverConversation();
        Booking::unguard();
        Booking::create(['reference' => 'BK-AAAAAA', 'apartment_id' => $this->apartment->id, 'unit_type_id' => $this->unitType->id, 'conversation_id' => $handover->conversation_id, 'guest_name' => 'Ayu', 'guest_email' => 'booked@example.test', 'check_in' => $this->checkIn, 'check_out' => $this->checkIn->addDays(2), 'total_price' => 1, 'status' => Booking::STATUS_PENDING]);
        Booking::reguard();

        $this->actingAs($this->owner)->post(route('admin.handovers.reply', $handover), ['message' => 'Halo'])->assertRedirect();

        Notification::assertSentOnDemand(StaffReplied::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'booked@example.test');
    }

    public function test_a_staff_reply_without_any_guest_email_is_only_shown_in_the_chat(): void
    {
        Notification::fake();
        $handover = $this->handedOverConversation();

        $this->actingAs($this->owner)->post(route('admin.handovers.reply', $handover), ['message' => 'Halo'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'no email'));

        Notification::assertNothingSent();
        $this->assertSame('Halo', $handover->conversation->messages()->value('content'));
    }

    public function test_a_handover_keeps_a_valid_guest_email_and_ignores_an_invalid_one(): void
    {
        Notification::fake();
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        $tools = new ApartmentConciergeTools($this->apartment, $conversation, 'en');

        $result = $tools->dispatch('request_human_handover', ['reason' => 'complaint', 'summary' => 'Noisy', 'guest_email' => 'not-an-email']);
        $this->assertNull($conversation->fresh()->guest_email);
        $this->assertStringContainsString('not valid', $result['text']);
        $this->assertTrue($conversation->fresh()->isHandedOver());

        $other = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);
        (new ApartmentConciergeTools($this->apartment, $other, 'en'))->dispatch('request_human_handover', ['reason' => 'complaint', 'summary' => 'Noisy', 'guest_email' => 'ayu@example.test']);
        $this->assertSame('ayu@example.test', $other->fresh()->guest_email);
    }

    public function test_a_handover_notifies_active_staff_with_a_link_to_the_conversation(): void
    {
        Notification::fake();
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'en']);

        (new ApartmentConciergeTools($this->apartment, $conversation, 'en'))->dispatch('request_human_handover', ['reason' => HandoverRequest::REASON_COMPLAINT, 'summary' => 'Noisy neighbours']);

        $handover = HandoverRequest::firstOrFail();
        Notification::assertSentTo($this->owner, NewHandoverRequest::class, fn ($notification) => $notification->handover->is($handover));
        Notification::assertNotSentTo($this->inactiveStaff, NewHandoverRequest::class);

        $mail = (new NewHandoverRequest($handover))->toMail($this->owner);
        $this->assertStringContainsString('Noisy neighbours', implode(' ', $mail->introLines));
        $this->assertSame(route('admin.handovers.show', $handover), $mail->actionUrl);
    }

    public function test_the_guest_emails_are_written_in_the_language_the_guest_used(): void
    {
        $booking = Booking::create([
            'reference' => 'BK-TEST01', 'apartment_id' => $this->apartment->id, 'unit_type_id' => $this->unitType->id, 'guest_name' => 'Aki',
            'guest_email' => 'aki@example.test', 'adults' => 2, 'unit_count' => 1, 'check_in' => $this->checkIn, 'check_out' => $this->checkIn->addDays(2), 'total_price' => 1000000,
            'status' => Booking::STATUS_CONFIRMED, 'locale' => 'ja',
        ]);

        $confirmed = (new BookingStatusChanged($booking))->toMail(new AnonymousNotifiable);
        $this->assertSame('ご入居が確定しました BK-TEST01', $confirmed->subject);
        $this->assertContains('お部屋: 1 × スタジオ', $confirmed->introLines);
        $this->assertContains('入居日: '.$this->checkIn->year.'年'.$this->checkIn->month.'月'.$this->checkIn->day.'日', $confirmed->introLines);

        $booking->update(['status' => Booking::STATUS_CANCELLED, 'locale' => 'id']);
        $this->assertSame('Permintaan sewa BK-TEST01 dibatalkan', (new BookingStatusChanged($booking))->toMail(new AnonymousNotifiable)->subject);

        $booking->update(['locale' => null]);
        $this->assertSame('Your stay request BK-TEST01 was cancelled', (new BookingStatusChanged($booking))->toMail(new AnonymousNotifiable)->subject);
    }
}
