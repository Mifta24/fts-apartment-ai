<?php

namespace Tests\Feature;

use App\Models\Apartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApartmentLobbyTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_screen_links_to_the_only_published_apartment(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->get('/?lang=en')
            ->assertOk()
            ->assertSee('FTS Apartment AI')
            ->assertSee('Enter Demo')
            ->assertSee('href="'.route('apartment.units', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('href="'.route('apartment.facilities', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('href="'.route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('href="'.route('apartment.staff', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertDontSee('Draft');
    }

    public function test_opening_screen_lists_every_published_apartment_when_there_are_several(): void
    {
        Apartment::create(['name' => 'First Apartment', 'slug' => 'first', 'public_status' => 'published']);
        Apartment::create(['name' => 'Second Apartment', 'slug' => 'second', 'public_status' => 'published']);
        Apartment::create(['name' => 'Hidden Apartment', 'slug' => 'hidden']);

        $this->get('/')->assertOk()->assertSee('First Apartment')->assertSee('Second Apartment')->assertDontSee('Hidden Apartment');
    }

    public function test_opening_screen_explains_when_no_apartment_is_published(): void
    {
        $this->get('/?lang=en')->assertOk()->assertSee('The virtual lobby is being prepared');
    }

    public function test_chat_has_separate_open_and_close_controls_without_submitting_the_composer(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('data-chat-launcher', false)
            ->assertSee('data-chat-composer', false)
            ->assertSee('type="button" data-chat-close', false)
            ->assertSee('aria-controls="concierge-chat-log"', false);
    }

    public function test_chat_composer_has_a_microphone_button_with_speech_language_and_copy_for_each_locale(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('type="button" data-chat-mic', false)
            ->assertSee('data-speech-lang="en-US"', false)
            ->assertSee('data-label-mic-start="Speak your message"', false);
        $this->get('/demo?lang=id')
            ->assertOk()
            ->assertSee('data-speech-lang="id-ID"', false)
            ->assertSee('data-label-mic-listening="Mendengarkan…"', false);
        $this->get('/demo?lang=ja')
            ->assertOk()
            ->assertSee('data-speech-lang="ja-JP"', false)
            ->assertSee('data-label-mic-start="音声で入力"', false);
    }

    public function test_every_floor_carries_the_centre_stage_showcase_with_copy_for_each_locale(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo/units?lang=en')
            ->assertOk()
            ->assertSee('data-showcase', false)
            ->assertSee('data-showcase-labels', false)
            ->assertSee('data-quote-url="'.route('reservation.quote', 'demo').'"', false)
            ->assertSee('data-reserve-url="'.route('reservation.store', 'demo').'"', false)
            ->assertSee('Send rental application');
        $this->get('/demo?lang=id')->assertOk()->assertSee('Kirim pengajuan sewa');
        $this->get('/demo/staff?lang=ja')->assertOk()->assertSee('入居申込を送信');
    }

    public function test_chat_exposes_consistent_status_draft_and_loading_copy_for_each_locale(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('data-label-status-sent="Message sent"', false)
            ->assertSee('data-label-status-waiting="Waiting for staff reply"', false)
            ->assertSee('data-label-status-replied="Staff has replied"', false)
            ->assertSee('data-label-draft-title="Unsaved message"', false)
            ->assertSee('data-label-draft-keep="Keep typing"', false)
            ->assertSee('data-label-draft-discard="Discard draft"', false)
            ->assertSee('data-thinking-indicator', false)
            ->assertSee('data-chat-status', false)
            ->assertSee('data-draft-dialog', false);

        $this->get('/demo?lang=ja')
            ->assertOk()
            ->assertSee('data-label-status-sent="メッセージを送信しました"', false)
            ->assertSee('data-label-status-waiting="スタッフの返信を待っています"', false)
            ->assertSee('data-label-status-replied="スタッフが返信しました"', false)
            ->assertSee('data-label-draft-title="未送信のメッセージ"', false)
            ->assertSee('data-label-draft-keep="入力を続ける"', false)
            ->assertSee('data-label-draft-discard="下書きを破棄"', false);

        $this->get('/demo?lang=id')
            ->assertOk()
            ->assertSee('data-label-status-sent="Pesan terkirim"', false)
            ->assertSee('data-label-status-waiting="Menunggu balasan staf"', false)
            ->assertSee('data-label-status-replied="Staf telah membalas"', false)
            ->assertSee('data-label-draft-title="Pesan belum dikirim"', false)
            ->assertSee('data-label-draft-keep="Lanjut mengetik"', false)
            ->assertSee('data-label-draft-discard="Buang draft"', false);
    }

    public function test_the_facilities_page_lists_only_active_facilities_of_the_current_apartment(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Garden pool', 'body' => 'Open until 8pm.', 'is_active' => true, 'image_url' => 'https://example.test/pool.jpg']);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Hidden spa', 'body' => 'Private.', 'is_active' => false]);
        $other = Apartment::create(['name' => 'Other', 'slug' => 'other', 'public_status' => 'published']);
        $other->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Other pool', 'body' => 'Other apartment.', 'is_active' => true]);

        $this->get('/demo/facilities')
            ->assertOk()
            ->assertSee('Garden pool')
            ->assertDontSee('Hidden spa')
            ->assertDontSee('Other pool')
            // photo cards replace the menu card, which would cover the right-hand panel
            ->assertSee('class="facility-card"', false)
            ->assertSee('https://example.test/pool.jpg', false)
            ->assertSee('class="lobby-content stage-panel-right', false)
            ->assertDontSee('class="lobby-navigation"', false);
        $this->get('/demo?lang=en')->assertOk()->assertSee('Welcome to your')->assertSee('virtual lobby');
        $this->get('/demo?lang=ja')->assertOk()->assertSee('バーチャルロビー');
    }

    public function test_unpublished_lobby_is_not_accessible(): void
    {
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);
        $this->get('/draft')->assertNotFound();
    }

    public function test_the_units_page_lists_only_active_units_of_the_current_apartment(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $first = $apartment->unitTypes()->create([
            'name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 1,
            'bed_config' => [['type' => 'king', 'count' => 1]], 'view_type' => 'garden', 'amenities' => ['wifi', 'full_kitchen'],
            'bedrooms' => 1, 'bathrooms' => 1, 'floor_range' => '10–20', 'is_active' => true, 'sort_order' => 0,
        ]);
        $first->images()->create(['image_url' => 'https://example.test/one.jpg', 'alt_text' => 'King bed', 'sort_order' => 0]);
        $first->images()->create(['image_url' => 'https://example.test/two.jpg', 'alt_text' => 'Bathroom', 'sort_order' => 1]);
        $apartment->unitTypes()->create(['name' => 'Family Suite', 'slug' => 'family-suite', 'monthly_price' => 20000000, 'max_adults' => 3, 'max_children' => 2, 'is_active' => true, 'sort_order' => 1]);
        $apartment->unitTypes()->create(['name' => 'Retired Unit', 'slug' => 'retired-unit', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => false]);
        $other = Apartment::create(['name' => 'Other', 'slug' => 'other', 'public_status' => 'published']);
        $other->unitTypes()->create(['name' => 'Foreign Unit', 'slug' => 'foreign-unit', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => true]);

        $this->get('/demo/units?lang=en')
            ->assertOk()
            ->assertSee('Deluxe King')
            ->assertSee('Family Suite')
            // the scene itself is the view now: the concierge greets over the
            // backdrop and the index on the right carries the units
            ->assertDontSee('data-unit-card', false)
            ->assertSee('narrator-on-stage', false)
            ->assertSee('class="unit-nav-thumb"', false)
            ->assertSee('href="'.route('apartment.unit', ['apartmentSlug' => 'demo', 'unitSlug' => 'deluxe-king', 'lang' => 'en']).'"', false)
            ->assertDontSee('Retired Unit')
            ->assertDontSee('Foreign Unit');
    }

    public function test_a_unit_page_shows_its_details_gallery_and_neighbouring_units(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $first = $apartment->unitTypes()->create([
            'name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'size_sqm' => 32, 'max_adults' => 2, 'max_children' => 1,
            'bed_config' => [['type' => 'king', 'count' => 1]], 'view_type' => 'garden', 'amenities' => ['wifi', 'full_kitchen'],
            'bedrooms' => 1, 'bathrooms' => 1, 'floor_range' => '10–20', 'is_active' => true, 'sort_order' => 0,
        ]);
        $first->images()->create(['image_url' => 'https://example.test/one.jpg', 'alt_text' => 'King bed', 'sort_order' => 0]);
        $first->images()->create(['image_url' => 'https://example.test/two.jpg', 'alt_text' => 'Bathroom', 'sort_order' => 1]);
        $apartment->unitTypes()->create(['name' => 'Family Suite', 'slug' => 'family-suite', 'monthly_price' => 20000000, 'max_adults' => 3, 'max_children' => 2, 'is_active' => true, 'sort_order' => 1]);

        $suiteUrl = route('apartment.unit', ['apartmentSlug' => 'demo', 'unitSlug' => 'family-suite', 'lang' => 'en']);

        $this->get('/demo/units/deluxe-king?lang=en')
            ->assertOk()
            ->assertSee('Unit type 01 / 02')
            ->assertSee('King bed')
            ->assertSee('Garden view')
            ->assertSee('1 bed · 1 bath')
            ->assertSee('10–20')
            ->assertSee('Full kitchen')
            ->assertSee('https://example.test/two.jpg', false)
            ->assertSee('Final availability and rates are confirmed by the apartment team.')
            // both neighbours wrap around to the only other unit
            ->assertSee('rel="prev"', false)
            ->assertSee($suiteUrl, false);

        $this->get('/demo/units/retired-or-unknown')->assertNotFound();
        $this->get('/demo/units/deluxe-king?lang=id')->assertOk()->assertSee('Pemandangan taman');
    }

    public function test_units_pages_follow_the_apartment_publication_and_unit_state(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $apartment->unitTypes()->create(['name' => 'Hidden', 'slug' => 'hidden', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => false]);
        Apartment::create(['name' => 'Draft', 'slug' => 'draft']);

        $this->get('/demo/units?lang=en')->assertOk()->assertSee('Unit information will be available soon.');
        $this->get('/demo/units/hidden')->assertNotFound();
        $this->get('/draft/units')->assertNotFound();
    }

    public function test_the_lobby_walks_the_guest_to_the_units_page(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->get('/demo?lang=id')
            ->assertOk()
            ->assertSee('href="'.route('apartment.units', ['apartmentSlug' => 'demo', 'lang' => 'id']).'"', false)
            ->assertSee('data-tour-line="Naik ke lantai hunian."', false)
            ->assertSee('href="'.route('apartment.facilities', ['apartmentSlug' => 'demo', 'lang' => 'id']).'"', false)
            ->assertSee('data-tour-line="Naik ke lantai fasilitas bersama."', false)
            ->assertDontSee('data-lobby-panel="units"', false)
            ->assertDontSee('data-lobby-panel="facilities"', false);

        $this->get('/demo/units?lang=id')
            ->assertOk()
            ->assertSee('data-tour-line="Turun kembali ke lobi."', false)
            ->assertSee('data-scene="units"', false)
            ->assertSee('data-floor="02"', false);
    }

    public function test_reservation_wizard_and_staff_channels_render_from_apartment_data(): void
    {
        $apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'whatsapp' => '+62 812-0000-1111',
            'phone' => '+62 361 000', 'email' => 'front@demo.test',
        ]);
        $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 1, 'is_active' => true]);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('href="'.route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('data-tour-line="Going to the leasing desk to apply for a unit."', false)
            ->assertSee('href="'.route('apartment.staff', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('data-tour-line="Going to the apartment team."', false);

        $this->get('/demo/apply?lang=en')
            ->assertOk()
            ->assertSee('data-scene="reservation"', false)
            ->assertSee('data-tour-line="Going back down to the lobby."', false)
            ->assertSee('data-step="5"', false)
            ->assertSee('value="deluxe-king"', false)
            ->assertSee('Step :current of :total', false)
            ->assertSee(route('reservation.store', 'demo'), false);

        $this->get('/demo/apply?lang=id')->assertOk()->assertSee('Langkah :current dari :total', false);

        $this->get('/demo/apply?lang=en&unit=deluxe-king')
            ->assertOk()
            ->assertSee('data-preselect-unit="deluxe-king"', false);

        $this->get('/demo/units/deluxe-king?lang=en')
            ->assertOk()
            ->assertSee('href="'.e(route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'en', 'unit' => 'deluxe-king'])).'"', false);

        $this->get('/demo/staff?lang=en')
            ->assertOk()
            ->assertSee('data-scene="staff"', false)
            ->assertSee('data-tour-line="Going back down to the lobby."', false)
            ->assertSee('https://wa.me/6281200001111?text=', false)
            ->assertSee('tel:+62361000', false)
            ->assertSee('mailto:front@demo.test', false);

        $this->get('/draft/staff')->assertNotFound();
        $this->get('/draft/apply')->assertNotFound();
    }

    public function test_every_floor_shows_the_lift_panel_with_its_own_button_lit(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        foreach (['' => 'L', '/units' => '02', '/facilities' => '03', '/info' => '04', '/apply' => '05', '/staff' => '06'] as $path => $floor) {
            $page = $this->get('/demo'.$path.'?lang=en')
                ->assertOk()
                ->assertSee('class="lift-panel"', false)
                ->assertSee('data-floor="'.$floor.'"', false)
                ->assertSee('<span class="floor-display-code" data-floor-code>'.$floor.'</span>', false)
                ->getContent();

            $panel = Str::between($page, 'class="lift-panel"', '</nav>');
            $this->assertSame(1, substr_count($panel, 'aria-current="page"'));
            $this->assertMatchesRegularExpression('/aria-current="page"\s*>\s*<span class="lift-key" aria-hidden="true">'.$floor.'<\/span>/', $panel);
        }
    }

    public function test_building_information_and_team_floors_use_narrow_sheets(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo/staff?lang=en')->assertOk()->assertSee('class="lobby-content staff-panel', false);
        $this->get('/demo/info?lang=en')->assertOk()->assertSee('class="lobby-content info-panel', false);
    }

    public function test_every_stage_scene_uses_the_same_chat_controls_and_dock_contract(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Pool', 'body' => 'Open all day.', 'is_active' => true]);

        foreach (['/demo', '/demo/info', '/demo/units', '/demo/facilities', '/demo/apply', '/demo/staff'] as $path) {
            $this->get($path.'?lang=en')
                ->assertOk()
                ->assertSee('data-chat-dock="', false)
                ->assertSee('data-chat-launcher', false)
                ->assertSee('data-chat-composer', false)
                ->assertSee('aria-controls="concierge-chat-log"', false);
        }
    }

    public function test_each_facility_has_its_own_page_with_neighbours_and_an_index(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $pool = $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Garden pool', 'body' => 'Open 07:00 to 20:00.', 'is_active' => true, 'sort_order' => 0]);
        $gym = $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Gym', 'body' => 'Open 24 hours.', 'is_active' => true, 'sort_order' => 1]);
        $hidden = $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Hidden spa', 'body' => 'Private.', 'is_active' => false]);

        $url = fn ($item) => route('apartment.facility', ['apartmentSlug' => 'demo', 'facilityId' => $item->id, 'lang' => 'en']);

        $this->get('/demo/facilities?lang=en')
            ->assertOk()
            ->assertSee('data-scene="facilities"', false)
            ->assertSee($url($pool), false)
            ->assertSee($url($gym), false)
            ->assertDontSee($url($hidden), false);

        $this->get($url($pool))
            ->assertOk()
            ->assertSee('Facility 1 / 2')
            ->assertSee('Open 07:00 to 20:00.')
            ->assertSee('Ask about this facility')
            // both neighbours wrap around to the only other facility
            ->assertSee('rel="prev"', false)
            ->assertSee($url($gym), false);

        $this->get($url($hidden))->assertNotFound();
        $this->get('/demo/facilities/999999')->assertNotFound();
        $this->get('/draft/facilities')->assertNotFound();
        $this->get('/draft/info')->assertNotFound();
    }

    public function test_the_apartment_information_scene_lists_only_approved_about_policy_and_faq_entries(): void
    {
        $apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'address' => 'Jl. Pantai 8', 'city' => 'Bali', 'country' => 'Indonesia',
            'description' => 'A beachfront resort.', 'latitude' => -8.8, 'longitude' => 115.23,
        ]);
        $apartment->knowledgeItems()->create(['category' => 'policies', 'title' => 'Cancellation', 'body' => 'Free until 48 hours before arrival.', 'is_active' => true]);
        $apartment->knowledgeItems()->create(['category' => 'faq', 'title' => 'Is parking free?', 'body' => 'Yes, for guests.', 'is_active' => true]);
        $apartment->knowledgeItems()->create(['category' => 'policies', 'title' => 'Draft policy', 'body' => 'Not approved.', 'is_active' => false]);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Pool', 'body' => 'Belongs to facilities.', 'is_active' => true]);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('href="'.route('apartment.info', ['apartmentSlug' => 'demo', 'lang' => 'en']).'"', false)
            ->assertSee('data-tour-line="Going to the building information desk."', false)
            ->assertDontSee('data-lobby-panel="info"', false)
            ->assertDontSee('Free until 48 hours before arrival.');

        $this->get('/demo/info?lang=en')
            ->assertOk()
            ->assertSee('data-scene="info"', false)
            ->assertSee('data-tour-line="Going back down to the lobby."', false)
            ->assertSee('Free until 48 hours before arrival.')
            ->assertSee('Is parking free?')
            ->assertDontSee('info-stay-time', false)
            ->assertSee('https://www.google.com/maps?q=-8.8000000,115.2300000', false)
            ->assertDontSee('Not approved.');
    }

    public function test_the_lobby_shows_a_localised_loader_and_the_opening_screen_too(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo?lang=id')->assertOk()->assertSee('data-stage-loader', false)->assertSee('Memanggil lift…');
        $this->get('/?lang=en')->assertOk()->assertSee('data-stage-exit', false)->assertSee('Calling the lift…');
    }

    public function test_the_concierge_introduces_units_using_only_stored_apartment_data(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create([
            'name' => 'Two Bedroom', 'slug' => 'two-bedroom', 'description' => 'A calm unit with a park outlook', 'monthly_price' => 12000000, 'size_sqm' => 82,
            'bedrooms' => 2, 'bathrooms' => 2, 'floor_range' => '15–26', 'max_adults' => 4, 'max_children' => 2, 'view_type' => 'park', 'is_active' => true, 'sort_order' => 0,
        ]);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 8000000, 'bedrooms' => 0, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true, 'sort_order' => 1]);

        $this->get('/demo/units?lang=en')
            ->assertOk()
            ->assertSee('data-narrator', false)
            ->assertSee('We have 2 unit types, from IDR 8.000.000 per month.');

        $this->get('/demo/units/two-bedroom?lang=en')
            ->assertOk()
            ->assertSee('Two Bedroom. A calm unit with a park outlook. It is a 2-bedroom, 2-bathroom layout with 82 m² for up to 6 residents. You will find it on floors 15–26. Park view. Rent starts from IDR 12.000.000 per month.');

        $this->get('/demo/units/studio?lang=en')
            ->assertOk()
            ->assertSee('Studio. It is a studio for up to 2 residents. Rent starts from IDR 8.000.000 per month. Final availability and rates are confirmed by our team.');

        $this->get('/demo/units?lang=id')->assertOk()->assertSee('Ada 2 tipe unit, mulai dari IDR 8.000.000 per bulan.');
    }

    public function test_no_units_narration_is_shown_when_the_apartment_has_no_units(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo/units?lang=en')->assertOk()->assertDontSee('data-key="units"', false)->assertDontSee('This is the residences floor');
    }

    public function test_the_concierge_introduces_facilities_and_apartment_information_from_stored_data(): void
    {
        $apartment = Apartment::create([
            'name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'city' => 'Bali', 'country' => 'Indonesia',
        ]);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Garden pool', 'body' => "Open 07:00 to 20:00.\nTowels included.", 'is_active' => true, 'sort_order' => 0]);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Gym', 'body' => 'Open 24 hours.', 'is_active' => true, 'sort_order' => 1]);
        $apartment->knowledgeItems()->create(['category' => 'facilities', 'title' => 'Hidden spa', 'body' => 'Private.', 'is_active' => false, 'sort_order' => 2]);

        $pool = $apartment->knowledgeItems()->where('title', 'Garden pool')->firstOrFail();

        $this->get('/demo/facilities?lang=en')
            ->assertOk()
            ->assertSee('Residents share 2 facilities and services, including Garden pool; Gym.')
            ->assertDontSee('including Garden pool; Gym; Hidden spa');

        $this->get(route('apartment.facility', ['apartmentSlug' => 'demo', 'facilityId' => $pool->id, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('data-key="facility-', false)
            ->assertSee('data-text="Open 07:00 to 20:00.', false);

        $this->get('/demo/info?lang=en')
            ->assertOk()
            ->assertSee('Welcome to Demo. The building is in Bali, Indonesia. Below you will find the address');

        $this->get('/demo/info?lang=id')->assertOk()->assertSee('Selamat datang di Demo. Gedung kami berada di Bali, Indonesia.');
    }

    public function test_apartment_information_narration_skips_missing_data(): void
    {
        Apartment::create(['name' => 'Bare', 'slug' => 'bare', 'public_status' => 'published']);

        $this->get('/bare/info?lang=en')
            ->assertOk()
            ->assertSee('Welcome to Bare. Below you will find the address')
            ->assertDontSee('The building is in');
    }

    public function test_the_sound_toggle_is_available_on_the_opening_screen_and_the_lobby_in_every_language(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/?lang=en')->assertOk()->assertSee('data-sound-toggle', false)->assertSee('Sound on')->assertSee('Sound off');
        $this->get('/?lang=id')->assertOk()->assertSee('Suara aktif')->assertSee('Suara mati');
        $this->get('/demo?lang=en')->assertOk()->assertSee('data-sound-toggle', false)->assertSee('data-label-off="Sound off"', false);
        $this->get('/demo/info?lang=ja')
            ->assertOk()
            ->assertSee('サウンドオン')
            ->assertSee('data-lang="ja-JP"', false)
            ->assertSee('data-voice-preference="female"', false);
    }

    public function test_the_units_floor_shows_a_directory_of_unit_types(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true, 'sort_order' => 0]);
        $apartment->unitTypes()->create(['name' => 'Family Suite', 'slug' => 'family-suite', 'monthly_price' => 25000000, 'max_adults' => 4, 'max_children' => 0, 'is_active' => true, 'sort_order' => 1]);
        $apartment->unitTypes()->create(['name' => 'Retired Unit', 'slug' => 'retired', 'monthly_price' => 1, 'max_adults' => 1, 'max_children' => 0, 'is_active' => false]);

        $this->get('/demo/units/family-suite?lang=id')
            ->assertOk()
            ->assertSee('class="unit-directory"', false)
            ->assertSee('class="unit-nav"', false)
            ->assertSee("Sewa mulai IDR\u{00A0}12.000.000 / bulan", false)
            ->assertDontSee('Retired Unit')
            // the lift panel still takes the guest back down to the lobby
            ->assertSee('href="'.route('apartment.show', ['apartmentSlug' => 'demo', 'lang' => 'id']).'" class="lift-button"', false);

        // the open unit is marked as current in the index, the others are not
        $index = $this->get('/demo/units/family-suite?lang=id')->getContent();
        $navigation = Str::between($index, '<nav class="unit-nav">', '</nav>');
        $this->assertStringContainsString('aria-current="page"', Str::after($navigation, 'unitSlug=family-suite') ?: $navigation);
        $this->assertSame(1, substr_count($navigation, 'aria-current'));

        // the lobby keeps the ordinary menu
        $this->get('/demo?lang=id')
            ->assertOk()
            ->assertSee('href="'.route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'id']).'"', false)
            ->assertDontSee('class="unit-nav"', false);
    }

    public function test_each_floor_opens_onto_its_own_photograph_with_the_concierge_posed_in_front(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Deluxe King', 'slug' => 'deluxe-king', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->get('/demo')
            ->assertOk()
            ->assertSee('images/apartment-lobby.jpg', false)
            ->assertSee('class="stage-character" data-pose="welcoming"', false);
        $this->get('/demo/units')
            ->assertOk()
            ->assertSee('photo-1600607687939-ce8a6c25118c', false)
            ->assertSee('class="stage-character" data-pose="presenting"', false);
        $this->get('/demo/info')
            ->assertOk()
            ->assertSee('photo-1555899434-94d1368aa7af', false)
            ->assertSee('class="stage-character" data-pose="standing"', false)
            ->assertSee('images/character/character avatar.jpg', false);
        $this->get('/demo/facilities')
            ->assertOk()
            ->assertSee('photo-1571896349842-33c89424de2d', false)
            ->assertSee('class="stage-character" data-pose="welcoming"', false)
            ->assertSee('images/character/character welcoming.png', false);
        $this->get('/demo/staff')->assertOk()->assertSee('class="stage-character" data-pose="consulting"', false);
        $this->get('/demo/apply')->assertOk()->assertSee('class="stage-character" data-pose="consulting"', false);

        // the unit page is too full to share with her, so only her avatar stays
        $this->get('/demo/units/deluxe-king')
            ->assertOk()
            ->assertSee('photo-1600607687939-ce8a6c25118c', false)
            ->assertSee('images/character/character avatar.jpg', false)
            ->assertDontSee('class="stage-character"', false);
    }

    public function test_facility_icons_follow_what_the_entry_is_about(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);
        $icon = fn (string $title, array $tags = []) => $apartment->knowledgeItems()->make(['category' => 'facilities', 'title' => $title, 'body' => '-', 'tags' => $tags])->iconName();

        $this->assertSame('pool', $icon('Kolam renang'));
        $this->assertSame('gym', $icon('Fitness centre'));
        $this->assertSame('parking', $icon('Parkir dan Wi-Fi'));
        $this->assertSame('dining', $icon('Breakfast'));
        $this->assertSame('transport', $icon('Antar-jemput bandara'));
        $this->assertSame('place', $icon('Atraksi terdekat'));
        $this->assertSame('wifi', $icon('Internet', ['wifi']));
        $this->assertSame('laundry', $icon('Laundry dan housekeeping'));
        $this->assertSame('work', $icon('Co-working lounge'));
        $this->assertSame('transit', $icon('MRT dan transportasi'));
        $this->assertSame('security', $icon('Kartu akses', ['access card']));
        $this->assertSame('star', $icon('Kids club'));
    }

    public function test_every_lift_button_leads_to_its_floor_with_a_topic_for_the_concierge(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $scenes = [
            'apartment.units' => 'Saya ingin melihat pilihan tipe unit yang tersedia.',
            'apartment.facilities' => 'Fasilitas apa saja yang bisa dipakai penghuni?',
            'apartment.info' => 'Apa aturan gedung, proses pindah masuk, dan kebijakan deposit?',
            'apartment.reservation' => 'Saya ingin menyewa unit. Bantu saya cek ketersediaan.',
            'apartment.staff' => 'Saya ingin bicara dengan tim apartemen.',
        ];

        $response = $this->get('/demo?lang=id')->assertOk();

        foreach ($scenes as $route => $topic) {
            $url = route($route, ['apartmentSlug' => 'demo', 'lang' => 'id']);

            $response->assertSee('href="'.$url.'"', false)
                ->assertSeeInOrder(['class="lift-panel"', 'href="'.$url.'"', 'data-stage-exit', 'data-topic="'.$topic.'"'], false);
            $this->get($url)->assertOk();
        }
    }

    public function test_lobby_offers_the_residences_and_a_rental_application_as_the_two_main_actions_in_the_guest_language(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 8000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->get('/demo?lang=id')
            ->assertOk()
            ->assertSee('class="lobby-action lobby-action-signal" data-stage-exit', false)
            ->assertSee('href="'.route('apartment.units', ['apartmentSlug' => 'demo', 'lang' => 'id']).'"', false)
            ->assertSee('Lihat unit')
            ->assertSee('href="'.route('apartment.reservation', ['apartmentSlug' => 'demo', 'lang' => 'id']).'"', false)
            ->assertSee('Ajukan sewa');

        $this->get('/demo?lang=en')->assertOk()->assertSee('See the residences')->assertSee('Apply to rent');
        $this->get('/demo?lang=ja')->assertOk()->assertSee('お部屋を見る');
    }

    public function test_lobby_without_any_unit_type_shows_no_actions_that_lead_nowhere(): void
    {
        Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published']);

        $this->get('/demo?lang=en')->assertOk()->assertDontSee('lobby-cta', false)->assertDontSee('See the residences');
    }

    public function test_lobby_stats_show_the_monthly_rent_from_and_no_hotel_check_in_time(): void
    {
        $apartment = Apartment::create(['name' => 'Demo', 'slug' => 'demo', 'public_status' => 'published', 'currency' => 'IDR']);
        $apartment->unitTypes()->create(['name' => 'Studio', 'slug' => 'studio', 'monthly_price' => 8000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);
        $apartment->unitTypes()->create(['name' => 'Loft', 'slug' => 'loft', 'monthly_price' => 12000000, 'max_adults' => 2, 'max_children' => 0, 'is_active' => true]);

        $this->get('/demo?lang=en')
            ->assertOk()
            ->assertSee('Monthly rent from')
            ->assertSee('IDR 8.000.000')
            ->assertDontSee('Nightly')
            ->assertDontSee('Check-in');
    }
}
