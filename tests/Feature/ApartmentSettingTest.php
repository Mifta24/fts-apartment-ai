<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApartmentSettingTest extends TestCase
{
    use RefreshDatabase;

    private Apartment $apartment;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR',
            'security_deposit_months' => 2, 'min_monthly_lease_months' => 1,
        ]);

        $this->owner = User::factory()->create();
        $this->staff = User::factory()->create();
        $this->apartment->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'active']);
        $this->apartment->users()->attach($this->staff->id, ['role' => 'staff', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return [
            'name' => 'Demo Residence', 'description' => 'Deskripsi', 'translations' => ['en' => ['description' => 'Description'], 'ja' => ['description' => '説明']],
            'address' => 'Jl. Senopati 1', 'city' => 'Jakarta', 'country' => 'Indonesia', 'phone' => '+62 21 555 0100', 'whatsapp' => '+62 812 0000 1111',
            'email' => 'front@demo.test', 'timezone' => 'Asia/Makassar', 'default_locale' => 'en', 'security_deposit_months' => 3, 'min_monthly_lease_months' => 2, 'public_status' => 'published',
            ...$overrides,
        ];
    }

    private function save(array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->put(route('admin.settings.update'), $payload);
    }

    public function test_visitors_are_sent_to_the_login_page(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('admin.login'));
        $this->put(route('admin.settings.update'), $this->settings())->assertRedirect(route('admin.login'));
    }

    public function test_the_owner_sees_the_current_values_and_a_settings_link(): void
    {
        $this->actingAs($this->owner)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('value="Demo"', false)
            ->assertSee('name="security_deposit_months" value="2"', false)
            ->assertSee(route('admin.settings.edit'), false);
    }

    public function test_the_owner_saves_contact_details_and_lease_rules(): void
    {
        $this->save($this->settings())->assertRedirect(route('admin.settings.edit'))->assertSessionHas('status');

        $apartment = $this->apartment->fresh();
        $this->assertSame('Demo Residence', $apartment->name);
        $this->assertSame('front@demo.test', $apartment->email);
        $this->assertSame('Asia/Makassar', $apartment->timezone);
        $this->assertSame('en', $apartment->default_locale);
        $this->assertSame('説明', $apartment->translations['ja']['description']);
        $this->assertSame('demo', $apartment->slug);
        $this->assertSame('IDR', $apartment->currency);
        $this->assertSame(3, $apartment->security_deposit_months);
        $this->assertSame(2, $apartment->min_monthly_lease_months);
    }

    public function test_saving_keeps_translations_other_than_the_description(): void
    {
        $this->apartment->update(['translations' => ['en' => ['tagline' => 'Live above the city'], 'ja' => []]]);

        $this->save($this->settings());

        $this->assertSame('Live above the city', $this->apartment->fresh()->translations['en']['tagline']);
    }

    public function test_a_draft_apartment_disappears_from_the_public_site(): void
    {
        $this->get('/demo')->assertOk();

        $this->save($this->settings(['public_status' => 'draft']));

        $this->get('/demo')->assertNotFound();
    }

    public function test_staff_cannot_open_or_change_the_settings_and_do_not_see_the_link(): void
    {
        $this->actingAs($this->staff)->get(route('admin.settings.edit'))->assertForbidden();
        $this->save($this->settings(['name' => 'Hacked']), $this->staff)->assertForbidden();
        $this->actingAs($this->staff)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.settings.edit'), false);

        $this->assertSame('Demo', $this->apartment->fresh()->name);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'unknown time zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
            'unsupported language' => [['default_locale' => 'fr'], 'default_locale'],
            'deposit over the limit' => [['security_deposit_months' => 13], 'security_deposit_months'],
            'negative deposit' => [['security_deposit_months' => -1], 'security_deposit_months'],
            'shortest lease below one month' => [['min_monthly_lease_months' => 0], 'min_monthly_lease_months'],
            'unknown status' => [['public_status' => 'archived'], 'public_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected_and_nothing_is_saved(array $overrides, string $field): void
    {
        $this->save($this->settings($overrides))->assertSessionHasErrors($field);

        $this->assertSame('Demo', $this->apartment->fresh()->name);
    }
}
