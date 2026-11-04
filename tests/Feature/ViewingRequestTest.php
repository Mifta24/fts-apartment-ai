<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Conversation;
use App\Models\UnitType;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Notifications\NewViewingRequest;
use App\Notifications\ViewingRequestReceived;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ViewingRequestTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private UnitType $studio;

    private User $owner;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta', 'default_locale' => 'en',
        ]);
        $this->studio = $this->apartment->unitTypes()->create([
            'name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 8000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true,
        ]);
        $this->owner = User::factory()->create();
        $this->apartment->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'active']);

        $this->date = CarbonImmutable::now('Asia/Jakarta')->addDays(4)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function viewing(array $overrides = []): array
    {
        return [
            'unit_type_slug' => 'studio', 'preferred_date' => $this->date, 'preferred_time' => '10:30', 'visitor_name' => 'Ayu',
            'contact_type' => 'email', 'contact_value' => 'ayu@example.test', 'notes' => 'Bringing my partner', 'locale' => 'id', ...$overrides,
        ];
    }

    public function test_a_visitor_can_ask_to_see_a_unit_and_the_team_and_visitor_are_told(): void
    {
        Notification::fake();
        $conversation = Conversation::create(['apartment_id' => $this->apartment->id, 'guest_token' => (string) Str::uuid(), 'locale' => 'id']);

        $response = $this->postJson('/demo/apply/viewing', $this->viewing(['guest_token' => $conversation->guest_token]))
            ->assertCreated()
            ->assertJsonPath('status', ViewingRequest::STATUS_REQUESTED)
            ->assertJsonPath('preferred_time', '10:30');

        $viewing = ViewingRequest::firstOrFail();
        $this->assertMatchesRegularExpression('/^VW-[A-Z0-9]{6}$/', $viewing->reference);
        $this->assertSame($viewing->reference, $response->json('reference'));
        $this->assertStringContainsString($viewing->reference, $response->json('message'));
        $this->assertSame($this->studio->id, $viewing->unit_type_id);
        $this->assertSame($conversation->id, $viewing->conversation_id);
        $this->assertSame('ayu@example.test', $viewing->visitor_email);
        $this->assertNull($viewing->visitor_phone);
        $this->assertSame('Bringing my partner', $viewing->notes);
        $this->assertSame('id', $viewing->locale);

        Notification::assertSentTo($this->owner, NewViewingRequest::class, fn ($notification) => $notification->viewing->is($viewing));
        Notification::assertSentOnDemand(ViewingRequestReceived::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ayu@example.test');
    }

    public function test_a_viewing_does_not_hold_any_unit(): void
    {
        $this->postJson('/demo/apply/viewing', $this->viewing())->assertCreated();
        $this->postJson('/demo/apply/viewing', $this->viewing(['visitor_name' => 'Budi']))->assertCreated();

        $this->assertSame(2, ViewingRequest::count());
        $this->assertDatabaseCount('unit_inventory', 0);
    }

    public function test_a_whatsapp_visitor_gets_no_email_but_the_team_is_still_told(): void
    {
        Notification::fake();

        $this->postJson('/demo/apply/viewing', $this->viewing(['contact_type' => 'whatsapp', 'contact_value' => '+62 812 0000 1111']))->assertCreated();

        Notification::assertSentTo($this->owner, NewViewingRequest::class);
        Notification::assertSentOnDemandTimes(ViewingRequestReceived::class, 0);
        $this->assertSame('+62 812 0000 1111', ViewingRequest::firstOrFail()->visitor_phone);
    }

    public function test_invalid_viewing_requests_are_rejected_in_the_visitors_language(): void
    {
        $yesterday = CarbonImmutable::now('Asia/Jakarta')->subDay()->toDateString();

        $this->postJson('/demo/apply/viewing', $this->viewing(['preferred_date' => $yesterday]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.preferred_date.0', 'Tanggal survei tidak boleh di masa lalu.');

        $this->postJson('/demo/apply/viewing', $this->viewing(['preferred_time' => '3pm']))->assertUnprocessable()->assertJsonValidationErrors('preferred_time');
        $this->postJson('/demo/apply/viewing', $this->viewing(['visitor_name' => '']))->assertUnprocessable()->assertJsonValidationErrors('visitor_name');
        $this->postJson('/demo/apply/viewing', $this->viewing(['unit_type_slug' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('unit_type_slug');
        $this->postJson('/demo/apply/viewing', $this->viewing(['contact_value' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors('contact_value');
        $this->postJson('/demo/apply/viewing', $this->viewing(['contact_type' => 'phone', 'contact_value' => 'call me']))->assertUnprocessable()->assertJsonValidationErrors('contact_value');

        $this->assertSame(0, ViewingRequest::count());
    }

    public function test_unpublished_apartments_take_no_viewing_requests(): void
    {
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->postJson('/draft/apply/viewing', $this->viewing())->assertNotFound();
    }

    public function test_the_application_page_offers_the_viewing_form_and_opens_it_when_asked(): void
    {
        $this->get('/demo/apply?lang=en')
            ->assertOk()
            ->assertSee('data-viewing-url="'.route('reservation.viewing', 'demo').'"', false)
            ->assertSee('Want to see the unit first? Schedule a viewing')
            ->assertSee('name="preferred_date"', false)
            ->assertDontSee('<details class="wizard-viewing" data-viewing open', false);

        $this->get('/demo/apply?lang=en&unit=studio&viewing=1')
            ->assertOk()
            ->assertSee('<details class="wizard-viewing" data-viewing open', false);
    }

    public function test_a_unit_page_links_to_a_viewing(): void
    {
        $this->get('/demo/units/studio?lang=en')
            ->assertOk()
            ->assertSee('Schedule a viewing')
            ->assertSee(e(route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'en', 'unit' => 'studio', 'viewing' => 1])), false);
    }

    public function test_staff_see_and_move_their_own_viewing_requests(): void
    {
        $this->postJson('/demo/apply/viewing', $this->viewing())->assertCreated();
        $viewing = ViewingRequest::firstOrFail();

        $this->actingAs($this->owner)->get(route('admin.viewings.index'))
            ->assertOk()
            ->assertSee($viewing->reference)
            ->assertSee('Ayu')
            ->assertSee('Mark scheduled')
            ->assertDontSee('Mark completed');

        $this->actingAs($this->owner)->patch(route('admin.viewings.status', $viewing), ['status' => 'scheduled'])->assertSessionHas('status');
        $this->assertSame(ViewingRequest::STATUS_SCHEDULED, $viewing->fresh()->status);

        $this->actingAs($this->owner)->patch(route('admin.viewings.status', $viewing), ['status' => 'requested'])->assertSessionHas('error');
        $this->actingAs($this->owner)->patch(route('admin.viewings.status', $viewing), ['status' => 'nonsense'])->assertSessionHasErrors('status');

        $this->actingAs($this->owner)->patch(route('admin.viewings.status', $viewing), ['status' => 'completed'])->assertSessionHas('status');
        $this->actingAs($this->owner)->patch(route('admin.viewings.status', $viewing), ['status' => 'cancelled'])->assertSessionHas('error');
        $this->assertSame(ViewingRequest::STATUS_COMPLETED, $viewing->fresh()->status);

        $this->actingAs($this->owner)->get(route('admin.viewings.index', ['status' => 'requested']))->assertSee('No viewing requests found');
    }

    public function test_viewings_need_a_login_and_stay_inside_their_own_apartment(): void
    {
        $this->postJson('/demo/apply/viewing', $this->viewing())->assertCreated();
        $viewing = ViewingRequest::firstOrFail();

        $this->get(route('admin.viewings.index'))->assertRedirect(route('admin.login'));
        $this->patch(route('admin.viewings.status', $viewing), ['status' => 'scheduled'])->assertRedirect(route('admin.login'));

        $other = Apartment::create(['name' => 'Other', 'slug' => 'other', 'public_status' => 'published']);
        $outsider = User::factory()->create();
        $other->users()->attach($outsider->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($outsider)->get(route('admin.viewings.index'))->assertOk()->assertDontSee($viewing->reference);
        $this->actingAs($outsider)->patch(route('admin.viewings.status', $viewing), ['status' => 'scheduled'])->assertNotFound();
        $this->assertSame(ViewingRequest::STATUS_REQUESTED, $viewing->fresh()->status);
    }
}
