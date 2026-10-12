<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentKnowledgeItem;
use App\Models\UnitType;
use App\Services\Reservation\ReservationHandover;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ApartmentPageController extends Controller
{
    private const SUPPORTED_LOCALES = ['id', 'en', 'ja'];

    /** The concierge's face for the chat and narrator avatars. */
    private const AVATAR_IMAGE = 'images/character/character avatar.jpg';

    /** The lobby is the front-desk picture on its own: no concierge cut-out is laid over it. */
    private const LOBBY_DESK_IMAGE = 'images/apartment-lobby.jpg';

    /**
     * The concierge's poses, transparent cut-outs shown in front of the scene photo.
     *
     * @var array<string, string>
     */
    private const CHARACTER_POSES = [
        'standing' => 'images/character/character standing.png',
        'presenting' => 'images/character/character presenting.png',
        'consulting' => 'images/character/character consulting.png',
    ];

    /**
     * Which pose she takes on each floor.
     *
     * @var array<string, string>
     */
    private const SCENE_POSES = [
        'units' => 'presenting',
        'reservation' => 'consulting',
        'staff' => 'consulting',
    ];

    /** Nights used to turn a nightly rate into the indicative monthly rate shown on unit pages. */
    private const NIGHTS_PER_MONTH = 30;

    /**
     * City and building photography behind each scene, so every floor of the
     * site opens onto the residence itself.
     *
     * @var array<string, array{image: string, focus: string, focusMobile: string}>
     */
    private const SCENE_PHOTOS = [
        'lobby' => ['image' => self::LOBBY_DESK_IMAGE, 'focus' => 'center 40%', 'focusMobile' => '59% 50%'],
        'units' => ['image' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=2000&q=80', 'focus' => 'center 50%', 'focusMobile' => '40% 50%'],
        'facilities' => ['image' => 'https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=2000&q=80', 'focus' => 'center 55%', 'focusMobile' => '50% 55%'],
        'info' => ['image' => 'https://images.unsplash.com/photo-1555899434-94d1368aa7af?auto=format&fit=crop&w=2000&q=80', 'focus' => 'center 45%', 'focusMobile' => '50% 45%'],
        'staff' => ['image' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=2000&q=80', 'focus' => 'center 50%', 'focusMobile' => '50% 50%'],
        'reservation' => ['image' => 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=2000&q=80', 'focus' => 'center 50%', 'focusMobile' => '30% 50%'],
    ];

    /**
     * Each section of the site is a floor of the building, reached from the
     * elevator panel. The code is what the panel and floor display show.
     *
     * @var array<string, string>
     */
    public const FLOORS = [
        'lobby' => 'L',
        'units' => '02',
        'facilities' => '03',
        'info' => '04',
        'reservation' => '05',
        'staff' => '06',
    ];

    public function __construct(private readonly ReservationHandover $handover) {}

    /**
     * What the AI concierge says when the guest steps onto a floor.
     * Every sentence is built from stored apartment data, never generated.
     *
     * @var array<string, array<string, string>>
     */
    private const NARRATION = [
        'en' => [
            'units' => 'This is the residences floor. We have :count unit types, from :price per night. Open any of them and I will walk you through the layout, or ask me anything.',
            'units_one' => 'This is the residences floor. We have one unit type, from :price per night. Open it and I will walk you through the layout, or ask me anything.',
            'studio' => 'It is a studio',
            'bedrooms' => 'It is a :count-bedroom, :baths-bathroom layout',
            'size_guests' => 'with :size m² for up to :guests residents.',
            'guests' => 'for up to :guests residents.',
            'floors' => 'You will find it on floors :floors.',
            'price' => 'Rates start from :price per night, and longer stays get a lower rate. Final availability and rates are confirmed by our team.',
            'facilities' => 'Residents share :count facilities and services, including :examples. Choose one to read the details, or ask me anything.',
            'facilities_one' => 'Residents can use :examples. Open it to read the details, or ask me anything.',
            'info_welcome' => 'Welcome to :apartment.',
            'info_place' => 'The building is in :location.',
            'info_hours' => 'Check-in is from :in and check-out is at :out.',
            'info_more' => 'Below you will find the address, house rules and frequently asked questions — or just ask me.',
            'tour_units' => 'Going up to the residences floor.',
            'tour_facilities' => 'Going up to the shared facilities.',
            'tour_info' => 'Going to the building information desk.',
            'tour_staff' => 'Going to the apartment team.',
            'tour_reservation' => 'Going to the leasing desk to plan your stay.',
            'tour_lobby' => 'Going back down to the lobby.',
            'listen' => 'Listen', 'stop' => 'Stop', 'skip' => 'Skip', 'speaks' => 'is speaking',
        ],
        'id' => [
            'units' => 'Ini lantai hunian. Ada :count tipe unit, mulai dari :price per malam. Buka salah satu, nanti saya jelaskan denahnya — atau tanyakan apa saja kepada saya.',
            'units_one' => 'Ini lantai hunian. Ada satu tipe unit, mulai dari :price per malam. Buka unitnya, nanti saya jelaskan denahnya — atau tanyakan apa saja kepada saya.',
            'studio' => 'Unit ini bertipe studio',
            'bedrooms' => 'Unit ini punya :count kamar tidur dan :baths kamar mandi',
            'size_guests' => 'dengan luas :size m² untuk maksimal :guests penghuni.',
            'guests' => 'untuk maksimal :guests penghuni.',
            'floors' => 'Lokasinya di lantai :floors.',
            'price' => 'Tarif mulai dari :price per malam, dan makin lama menginap makin hemat. Ketersediaan dan tarif final dikonfirmasi oleh tim kami.',
            'facilities' => 'Penghuni bisa memakai :count fasilitas dan layanan, di antaranya :examples. Pilih salah satu untuk membaca detailnya, atau tanyakan apa saja kepada saya.',
            'facilities_one' => 'Penghuni bisa memakai :examples. Buka untuk membaca detailnya, atau tanyakan apa saja kepada saya.',
            'info_welcome' => 'Selamat datang di :apartment.',
            'info_place' => 'Gedung kami berada di :location.',
            'info_hours' => 'Check-in mulai pukul :in dan check-out pukul :out.',
            'info_more' => 'Di bawah ini ada alamat, aturan gedung, dan pertanyaan yang sering diajukan — atau tanyakan langsung kepada saya.',
            'tour_units' => 'Naik ke lantai hunian.',
            'tour_facilities' => 'Naik ke lantai fasilitas bersama.',
            'tour_info' => 'Menuju meja informasi gedung.',
            'tour_staff' => 'Menuju ruang tim apartemen.',
            'tour_reservation' => 'Menuju meja leasing untuk merencanakan menginap Anda.',
            'tour_lobby' => 'Turun kembali ke lobi.',
            'listen' => 'Dengarkan', 'stop' => 'Berhenti', 'skip' => 'Lewati', 'speaks' => 'sedang berbicara',
        ],
        'ja' => [
            'units' => 'こちらはレジデンスフロアです。:count タイプのお部屋を、1泊 :price からご用意しています。お部屋を開いていただければ、間取りをご案内します。ご質問もお気軽にどうぞ。',
            'units_one' => 'こちらはレジデンスフロアです。1タイプのお部屋を、1泊 :price からご用意しています。お部屋を開いていただければ、間取りをご案内します。ご質問もお気軽にどうぞ。',
            'studio' => 'スタジオタイプのお部屋で',
            'bedrooms' => ':count ベッドルーム・:baths バスルームの間取りで',
            'size_guests' => '広さは:size m²、最大:guests名様までご入居いただけます。',
            'guests' => '最大:guests名様までご入居いただけます。',
            'floors' => ':floors階にございます。',
            'price' => '料金は1泊 :price から。長期滞在ほどお得になります。空室状況と料金は、スタッフが最終確認いたします。',
            'facilities' => '入居者の方は:count件の施設・サービスをご利用いただけます。:examples などです。選ぶと詳細をご覧いただけます。',
            'facilities_one' => '入居者の方は:examples をご利用いただけます。詳細をご覧ください。',
            'info_welcome' => ':apartment へようこそ。',
            'info_place' => '建物は:locationにございます。',
            'info_hours' => 'チェックインは:in以降、チェックアウトは:outまでです。',
            'info_more' => '以下に、所在地、館内ルール、よくあるご質問をご案内しています。お気軽にお尋ねください。',
            'tour_units' => 'レジデンスフロアへ上がります。',
            'tour_facilities' => '共用施設のフロアへ上がります。',
            'tour_info' => '建物のインフォメーションへご案内します。',
            'tour_staff' => 'スタッフのもとへご案内します。',
            'tour_reservation' => 'リーシングデスクで、ご滞在の計画をお手伝いします。',
            'tour_lobby' => 'ロビーへ戻ります。',
            'listen' => '音声で聞く', 'stop' => '停止', 'skip' => 'スキップ', 'speaks' => '話しています',
        ],
    ];

    /**
     * Guest-facing names for the coded unit values stored in the database.
     *
     * @var array<string, array{bed: array<string, string>, view: array<string, string>, amenity: array<string, string>}>
     */
    private const UNIT_TERMS = [
        'en' => [
            'bed' => ['king' => 'King bed', 'queen' => 'Queen bed', 'twin' => 'Twin bed', 'single' => 'Single bed', 'double' => 'Double bed', 'sofa_bed' => 'Sofa bed'],
            'view' => ['city' => 'City view', 'skyline' => 'Skyline view', 'park' => 'Park view', 'pool' => 'Pool view', 'garden' => 'Garden view'],
            'amenity' => ['air_conditioning' => 'Air conditioning', 'wifi' => 'Fibre Wi-Fi', 'kitchenette' => 'Kitchenette', 'full_kitchen' => 'Full kitchen', 'washer_dryer' => 'Washer-dryer', 'dishwasher' => 'Dishwasher', 'workspace' => 'Work desk', 'smart_tv' => 'Smart TV', 'smart_lock' => 'Smart lock', 'water_heater' => 'Water heater', 'balcony' => 'Balcony', 'bathtub' => 'Bathtub', 'living_room' => 'Living room', 'dining_area' => 'Dining area', 'safe_deposit_box' => 'Safe', 'private_lift' => 'Private lift lobby'],
        ],
        'id' => [
            'bed' => ['king' => 'Tempat tidur king', 'queen' => 'Tempat tidur queen', 'twin' => 'Tempat tidur twin', 'single' => 'Tempat tidur single', 'double' => 'Tempat tidur double', 'sofa_bed' => 'Sofa bed'],
            'view' => ['city' => 'Pemandangan kota', 'skyline' => 'Pemandangan skyline', 'park' => 'Pemandangan taman', 'pool' => 'Pemandangan kolam', 'garden' => 'Pemandangan taman'],
            'amenity' => ['air_conditioning' => 'AC', 'wifi' => 'Wi-Fi fiber', 'kitchenette' => 'Dapur kecil', 'full_kitchen' => 'Dapur lengkap', 'washer_dryer' => 'Mesin cuci & pengering', 'dishwasher' => 'Mesin cuci piring', 'workspace' => 'Meja kerja', 'smart_tv' => 'Smart TV', 'smart_lock' => 'Kunci pintar', 'water_heater' => 'Pemanas air', 'balcony' => 'Balkon', 'bathtub' => 'Bathtub', 'living_room' => 'Ruang tamu', 'dining_area' => 'Ruang makan', 'safe_deposit_box' => 'Brankas', 'private_lift' => 'Lobi lift pribadi'],
        ],
        'ja' => [
            'bed' => ['king' => 'キングベッド', 'queen' => 'クイーンベッド', 'twin' => 'ツインベッド', 'single' => 'シングルベッド', 'double' => 'ダブルベッド', 'sofa_bed' => 'ソファベッド'],
            'view' => ['city' => 'シティビュー', 'skyline' => 'スカイラインビュー', 'park' => 'パークビュー', 'pool' => 'プールビュー', 'garden' => 'ガーデンビュー'],
            'amenity' => ['air_conditioning' => 'エアコン', 'wifi' => '光Wi-Fi', 'kitchenette' => 'ミニキッチン', 'full_kitchen' => 'フルキッチン', 'washer_dryer' => '洗濯乾燥機', 'dishwasher' => '食洗機', 'workspace' => 'ワークデスク', 'smart_tv' => 'スマートTV', 'smart_lock' => 'スマートロック', 'water_heater' => '給湯器', 'balcony' => 'バルコニー', 'bathtub' => 'バスタブ', 'living_room' => 'リビングルーム', 'dining_area' => 'ダイニング', 'safe_deposit_box' => 'セーフティボックス', 'private_lift' => '専用エレベーターホール'],
        ],
    ];

    public function index(Request $request): View
    {
        $apartments = Apartment::where('public_status', 'published')->orderBy('name')->get();

        $locale = in_array($request->query('lang'), self::SUPPORTED_LOCALES, true)
            ? $request->query('lang')
            : ($apartments->first()?->default_locale ?? 'id');

        return view('opening', [
            'apartments' => $apartments,
            'locale' => $locale,
            'supportedLocales' => self::SUPPORTED_LOCALES,
            'opening' => $this->openingLabels($locale),
            'openingImage' => 'https://images.unsplash.com/photo-1444723121867-7a241cacace9?auto=format&fit=crop&w=2000&q=80',
            'floors' => self::FLOORS,
        ]);
    }

    public function show(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.show', $this->stageData($apartment, $locale, 'lobby'));
    }

    public function units(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.units-index', $this->stageData($apartment, $locale, 'units'));
    }

    public function unit(Request $request, string $apartmentSlug, string $unitSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        $data = $this->stageData($apartment, $locale, 'unit');
        $units = $data['unitTypes'];
        $index = $units->search(fn (UnitType $unitType) => $unitType->slug === $unitSlug);

        abort_if($index === false, 404);

        return view('apartment.unit-detail', [
            ...$data,
            'unitType' => $units[$index],
            'unitIndex' => $index,
            'previousUnit' => $units[($index - 1 + $units->count()) % $units->count()],
            'nextUnit' => $units[($index + 1) % $units->count()],
        ]);
    }

    public function facilities(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.facilities-index', $this->stageData($apartment, $locale, 'facilities'));
    }

    public function facility(Request $request, string $apartmentSlug, int $facilityId): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        $data = $this->stageData($apartment, $locale, 'facility');
        $facilities = $data['facilities'];
        $index = $facilities->search(fn (ApartmentKnowledgeItem $item) => $item->id === $facilityId);

        abort_if($index === false, 404);

        return view('apartment.facility-detail', [
            ...$data,
            'facility' => $facilities[$index],
            'facilityIndex' => $index,
            'previousFacility' => $facilities[($index - 1 + $facilities->count()) % $facilities->count()],
            'nextFacility' => $facilities[($index + 1) % $facilities->count()],
        ]);
    }

    public function info(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.info-index', $this->stageData($apartment, $locale, 'info'));
    }

    public function staff(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.staff-index', $this->stageData($apartment, $locale, 'staff'));
    }

    public function reservationScene(Request $request, string $apartmentSlug): View
    {
        [$apartment, $locale] = $this->resolveStage($request, $apartmentSlug);

        return view('apartment.reservation-index', [
            ...$this->stageData($apartment, $locale, 'reservation'),
            'preselectedUnit' => (string) $request->query('unit', ''),
        ]);
    }

    /**
     * @return array{0: Apartment, 1: string}
     */
    private function resolveStage(Request $request, string $apartmentSlug): array
    {
        $apartment = Apartment::where('slug', $apartmentSlug)->firstOrFail();

        abort_if(! $apartment->isPublished(), 404);

        $locale = in_array($request->query('lang'), self::SUPPORTED_LOCALES, true)
            ? $request->query('lang')
            : $apartment->default_locale;

        return [$apartment, $locale];
    }

    /**
     * Everything the shared stage shell needs, for whichever floor the guest
     * has stepped onto.
     *
     * @return array<string, mixed>
     */
    private function stageData(Apartment $apartment, string $locale, string $scene): array
    {
        $unitTypes = $apartment->unitTypes()
            ->where('is_active', true)
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->values();

        $facilities = $apartment->knowledgeItems()->where('is_active', true)
            ->whereIn('category', ['facilities', 'dining', 'transport'])
            ->orderBy('sort_order')->get()->values();

        $labels = $this->labels($locale);
        $lobby = $this->lobbyLabels($locale);

        return [
            'apartment' => $apartment,
            'unitTypes' => $unitTypes,
            'locale' => $locale,
            'scene' => $scene,
            'floor' => self::FLOORS[['unit' => 'units', 'facility' => 'facilities'][$scene] ?? $scene] ?? self::FLOORS['lobby'],
            'backdrop' => $this->sceneBackdrop($scene),
            'menuItems' => $this->stageMenu($apartment, $locale, $labels, $lobby),
            'supportedLocales' => self::SUPPORTED_LOCALES,
            'labels' => $labels,
            'lobby' => $lobby,
            'unitTerms' => self::UNIT_TERMS[$locale],
            'wizard' => $this->wizardLabels($apartment, $locale),
            'narration' => self::NARRATION[$locale],
            'unitNarrations' => $this->unitNarrations($apartment, $unitTypes, $locale),
            'staffLinks' => $this->handover->forStaff($apartment, $locale),
            'today' => now($apartment->timezone)->toDateString(),
            'infoItems' => $apartment->knowledgeItems()->where('is_active', true)
                ->whereIn('category', ['general', 'policies', 'faq'])
                ->orderBy('sort_order')->get()->groupBy('category'),
            'facilities' => $facilities,
            'sceneNarrations' => $this->sceneNarrations($apartment, $facilities, $locale),
            'nightsPerMonth' => self::NIGHTS_PER_MONTH,
        ];
    }

    /**
     * The photograph behind a scene, the concierge posed in front of it,
     * plus the crop of her face used by the chat and narrator avatars.
     *
     * @return array{image: string, focus: string, focusMobile: string, character: ?string, pose: string, avatarImage: string, avatarZoom: string, avatarFocus: string}
     */
    private function sceneBackdrop(string $scene): array
    {
        $photo = self::SCENE_PHOTOS[['unit' => 'units', 'facility' => 'facilities'][$scene] ?? $scene] ?? self::SCENE_PHOTOS['lobby'];

        $pose = self::SCENE_POSES[['unit' => 'units'][$scene] ?? $scene] ?? 'standing';

        if ($scene === 'lobby') {
            $photo['image'] = asset($photo['image']);
        }

        return [
            ...$photo,
            'character' => $scene === 'lobby' ? null : asset(self::CHARACTER_POSES[$pose]),
            'pose' => $pose,
            'avatarImage' => asset(self::AVATAR_IMAGE),
            'avatarZoom' => 'cover',
            'avatarFocus' => '50% 50%',
        ];
    }

    /**
     * The elevator panel. Every section is its own floor, so choosing one
     * eases the stage out as if the guest were riding up to it.
     *
     * @param  array<string, string|array<string, string>>  $labels
     * @param  array<string, string>  $lobby
     * @return list<array{key: string, floor: string, label: string, href: string, exit: bool, tour: ?string, topic: string}>
     */
    private function stageMenu(Apartment $apartment, string $locale, array $labels, array $lobby): array
    {
        $tour = self::NARRATION[$locale];
        $url = fn (string $name) => route("apartment.{$name}", ['apartmentSlug' => $apartment->slug, 'lang' => $locale]);

        return [
            ['key' => 'units', 'floor' => self::FLOORS['units'], 'label' => $labels['units_heading'], 'href' => $url('units'), 'exit' => true, 'tour' => $tour['tour_units'], 'topic' => $labels['menu_units_q']],
            ['key' => 'facilities', 'floor' => self::FLOORS['facilities'], 'label' => $labels['menu_facilities'], 'href' => $url('facilities'), 'exit' => true, 'tour' => $tour['tour_facilities'], 'topic' => $labels['menu_facilities_q']],
            ['key' => 'info', 'floor' => self::FLOORS['info'], 'label' => $lobby['menu_info'], 'href' => $url('info'), 'exit' => true, 'tour' => $tour['tour_info'], 'topic' => $labels['menu_policies_q']],
            ['key' => 'reservation', 'floor' => self::FLOORS['reservation'], 'label' => $lobby['reservation'], 'href' => $url('reservation'), 'exit' => true, 'tour' => $tour['tour_reservation'], 'topic' => $lobby['reservation_q']],
            ['key' => 'staff', 'floor' => self::FLOORS['staff'], 'label' => $labels['menu_staff'], 'href' => $url('staff'), 'exit' => true, 'tour' => $tour['tour_staff'], 'topic' => $labels['menu_staff_q']],
        ];
    }

    /**
     * Intros for the facilities floor and the building information scene,
     * built only from stored apartment data.
     *
     * @param  Collection<int, ApartmentKnowledgeItem>  $facilities
     * @return array{facilities: ?string, info: string}
     */
    private function sceneNarrations(Apartment $apartment, Collection $facilities, string $locale): array
    {
        $templates = self::NARRATION[$locale];
        $separator = $locale === 'ja' ? '、' : '; ';
        $time = fn (?string $value) => $value ? substr($value, 0, 5) : null;

        $intro = null;

        if ($facilities->isNotEmpty()) {
            $examples = $facilities->take(3)->map(fn ($item) => $item->translatedTitle($locale))->implode($separator);
            $intro = str_replace(
                [':count', ':examples'],
                [(string) $facilities->count(), $examples],
                $templates[$facilities->count() === 1 ? 'facilities_one' : 'facilities']
            );
        }

        $location = collect([$apartment->city, $apartment->country])->filter()->implode($locale === 'ja' ? '、' : ', ');
        $parts = [str_replace(':apartment', $apartment->name, $templates['info_welcome'])];

        if ($location !== '') {
            $parts[] = str_replace(':location', $location, $templates['info_place']);
        }

        if ($time($apartment->check_in_time) && $time($apartment->check_out_time)) {
            $parts[] = str_replace([':in', ':out'], [$time($apartment->check_in_time), $time($apartment->check_out_time)], $templates['info_hours']);
        }

        $parts[] = $templates['info_more'];

        return ['facilities' => $intro, 'info' => implode(' ', $parts)];
    }

    /**
     * @param  Collection<int, UnitType>  $unitTypes
     * @return array{units: ?string, unit: array<string, string>}
     */
    private function unitNarrations(Apartment $apartment, Collection $unitTypes, string $locale): array
    {
        $templates = self::NARRATION[$locale];
        $terms = self::UNIT_TERMS[$locale];
        $money = fn ($value) => $apartment->currency.' '.number_format((float) $value, 0, ',', '.');
        $sentence = fn (string $text) => preg_match('/[.!?。！？]$/u', $text) ? $text : $text.($locale === 'ja' ? '。' : '.');
        $join = $locale === 'ja' ? '、' : ' ';

        if ($unitTypes->isEmpty()) {
            return ['units' => null, 'unit' => []];
        }

        $intro = str_replace(
            [':count', ':price'],
            [(string) $unitTypes->count(), $money($unitTypes->min('base_price'))],
            $templates[$unitTypes->count() === 1 ? 'units_one' : 'units']
        );

        $units = $unitTypes->mapWithKeys(function (UnitType $unitType) use ($templates, $terms, $money, $sentence, $locale, $join) {
            $parts = [$unitType->translatedName($locale).'.', filled($unitType->translatedDescription($locale)) ? $sentence(trim($unitType->translatedDescription($locale))) : null];

            $layout = $unitType->isStudio()
                ? $templates['studio']
                : str_replace([':count', ':baths'], [(string) $unitType->bedrooms, (string) $unitType->bathrooms], $templates['bedrooms']);

            $capacity = $unitType->size_sqm
                ? str_replace([':size', ':guests'], [(string) $unitType->size_sqm, (string) $unitType->maxOccupancy()], $templates['size_guests'])
                : str_replace(':guests', (string) $unitType->maxOccupancy(), $templates['guests']);

            $parts[] = $layout.$join.$capacity;

            if ($unitType->floor_range) {
                $parts[] = str_replace(':floors', $unitType->floor_range, $templates['floors']);
            }

            if ($unitType->view_type) {
                $parts[] = $sentence($terms['view'][$unitType->view_type] ?? Str::headline($unitType->view_type));
            }

            $parts[] = str_replace(':price', $money($unitType->base_price), $templates['price']);

            return [$unitType->slug => implode(' ', array_filter($parts))];
        })->all();

        return ['units' => $intro, 'unit' => $units];
    }

    /**
     * @return array<string, string>
     */
    private function openingLabels(string $locale): array
    {
        return match ($locale) {
            'en' => [
                'eyebrow' => 'Serviced apartments · AI concierge', 'welcome' => 'City living,', 'welcome_em' => 'one floor at a time.',
                'tagline' => 'Ride up through the building with an AI concierge that knows every unit, rate and house rule — for a weekend or a few months.',
                'enter' => 'Enter :name', 'empty' => 'The virtual lobby is being prepared. Please come back soon.',
                'loading' => 'Calling the lift…', 'sound_on' => 'Sound on', 'sound_off' => 'Sound off', 'directory' => 'Building directory',
                'units' => 'Residences', 'facilities' => 'Shared facilities', 'reservation' => 'Plan a stay', 'staff' => 'Apartment team',
            ],
            'ja' => [
                'eyebrow' => 'サービスアパートメント · AIコンシェルジュ', 'welcome' => '都市の暮らしを、', 'welcome_em' => 'ワンフロアずつ。',
                'tagline' => 'すべてのお部屋・料金・館内ルールを知るAIコンシェルジュと、建物の中をご案内します。週末の滞在から数か月の長期滞在まで。',
                'enter' => ':name に入る', 'empty' => 'バーチャルロビーは準備中です。しばらくしてからお越しください。',
                'loading' => 'エレベーターを呼んでいます…', 'sound_on' => 'サウンドオン', 'sound_off' => 'サウンドオフ', 'directory' => 'フロアガイド',
                'units' => 'レジデンス', 'facilities' => '共用施設', 'reservation' => '滞在を計画する', 'staff' => 'スタッフに相談',
            ],
            default => [
                'eyebrow' => 'Serviced apartment · AI concierge', 'welcome' => 'Hidup di kota,', 'welcome_em' => 'satu lantai demi satu.',
                'tagline' => 'Naik menjelajahi gedung bersama AI Concierge yang hafal setiap unit, tarif, dan aturan gedung — untuk akhir pekan maupun beberapa bulan.',
                'enter' => 'Masuk ke :name', 'empty' => 'Lobi virtual sedang dipersiapkan. Silakan kembali lagi nanti.',
                'loading' => 'Memanggil lift…', 'sound_on' => 'Suara aktif', 'sound_off' => 'Suara mati', 'directory' => 'Direktori gedung',
                'units' => 'Unit hunian', 'facilities' => 'Fasilitas bersama', 'reservation' => 'Rencanakan menginap', 'staff' => 'Tim apartemen',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardLabels(Apartment $apartment, string $locale): array
    {
        $longStay = str_replace(
            [':weekly_nights', ':weekly', ':monthly_nights', ':monthly'],
            [(string) Apartment::WEEKLY_STAY_NIGHTS, (string) $apartment->weekly_discount_percent, (string) Apartment::MONTHLY_STAY_NIGHTS, (string) $apartment->monthly_discount_percent],
            match ($locale) {
                'en' => 'Long-stay rates: :weekly% off from :weekly_nights nights, :monthly% off from :monthly_nights nights.',
                'ja' => '長期滞在割引：:weekly_nights泊以上で:weekly%オフ、:monthly_nights泊以上で:monthly%オフ。',
                default => 'Tarif menginap lama: hemat :weekly% mulai :weekly_nights malam, hemat :monthly% mulai :monthly_nights malam.',
            }
        );

        return [...ReservationController::MESSAGES[$locale], 'long_stay_hint' => $apartment->weekly_discount_percent > 0 || $apartment->monthly_discount_percent > 0 ? $longStay : '', ...match ($locale) {
            'en' => [
                'title' => 'Stay request', 'intro' => 'A few quick steps. Final availability is confirmed by our leasing team.',
                'step_of' => 'Step :current of :total', 'steps' => ['Dates', 'Residents', 'Unit', 'Your details', 'Summary'],
                'cal_prev' => 'Previous month', 'cal_next' => 'Next month', 'cal_pick_in' => 'Pick your move-in date.', 'cal_pick_out' => 'Now pick your move-out date.', 'cal_none' => 'No dates are open online right now. Ask the concierge or the apartment team.', 'cal_full' => 'Fully booked', 'cal_choose' => 'Choose a date',
                'check_in' => 'Move-in', 'check_out' => 'Move-out', 'nights' => 'night(s)', 'adults' => 'Adults', 'children' => 'Children', 'units' => 'Units',
                'choose_unit' => 'Choose a unit type', 'fits' => 'Up to :count residents per unit', 'too_small' => 'Too small for your household',
                'extra_bed' => 'Add a rollaway bed', 'name' => 'Full name', 'contact_method' => 'How should we contact you?',
                'whatsapp' => 'WhatsApp', 'phone' => 'Phone', 'email' => 'Email', 'contact_value' => 'Number or email address',
                'special' => 'Special request (optional)', 'special_placeholder' => 'e.g. high floor, parking space, moving in with a pet',
                'next' => 'Next', 'back' => 'Back', 'edit' => 'Edit', 'submit' => 'Submit stay request', 'sending' => 'Sending…',
                'checking' => 'Checking availability…', 'estimated_total' => 'Estimated total', 'subtotal' => 'Subtotal', 'discount' => 'Long-stay discount',
                'guests' => 'Residents', 'unit' => 'Unit', 'dates' => 'Dates',
                'contact' => 'Contact', 'disclaimer' => 'This is a stay request, not a lease. Final availability and rates are confirmed by our leasing team. No payment is taken now.',
                'available_instead' => 'Available for your dates instead:', 'done_title' => 'Request received', 'reference' => 'Your reference',
                'awaiting' => 'Awaiting confirmation from the leasing team', 'done_hint' => 'Send your request to our team to speed up confirmation.',
                'send_whatsapp' => 'Send to WhatsApp', 'call_apartment' => 'Call the leasing office', 'email_apartment' => 'Email the leasing office', 'new_request' => 'New request',
                'error_network' => 'Connection problem. Your details are saved — please try again or contact the apartment team.',
                'error_generic' => 'Something went wrong. Please try again or contact the apartment team.', 'select_unit' => 'Please choose a unit type.',
                'contact_staff' => 'Contact the apartment team',
            ],
            'ja' => [
                'title' => '滞在リクエスト', 'intro' => 'かんたんな手順です。空室状況はリーシングチームが最終確認いたします。',
                'step_of' => 'ステップ :current / :total', 'steps' => ['日程', '入居人数', 'お部屋', 'ご連絡先', '確認'],
                'cal_prev' => '前の月', 'cal_next' => '次の月', 'cal_pick_in' => '入居日を選んでください。', 'cal_pick_out' => '続いて退去日を選んでください。', 'cal_none' => '現在オンラインで受付中の日程はありません。コンシェルジュまたはスタッフにご相談ください。', 'cal_full' => '満室', 'cal_choose' => '日付を選択',
                'check_in' => '入居日', 'check_out' => '退去日', 'nights' => '泊', 'adults' => '大人', 'children' => '子ども', 'units' => '戸数',
                'choose_unit' => 'お部屋タイプを選ぶ', 'fits' => '1戸あたり最大:count名', 'too_small' => '人数に対応できません',
                'extra_bed' => '簡易ベッドを追加', 'name' => 'お名前', 'contact_method' => 'ご連絡方法',
                'whatsapp' => 'WhatsApp', 'phone' => '電話', 'email' => 'メール', 'contact_value' => '番号またはメールアドレス',
                'special' => 'ご要望（任意）', 'special_placeholder' => '例：高層階、駐車スペース、ペット同伴',
                'next' => '次へ', 'back' => '戻る', 'edit' => '編集', 'submit' => '滞在リクエストを送信', 'sending' => '送信中…',
                'checking' => '空室を確認中…', 'estimated_total' => '概算合計', 'subtotal' => '小計', 'discount' => '長期滞在割引',
                'guests' => '入居人数', 'unit' => 'お部屋', 'dates' => '日程',
                'contact' => 'ご連絡先', 'disclaimer' => 'これは滞在のリクエストであり、賃貸契約ではありません。空室状況と料金はリーシングチームが最終確認します。現時点でお支払いは発生しません。',
                'available_instead' => 'ご希望の日程で空いているお部屋：', 'done_title' => 'リクエストを受け付けました', 'reference' => '受付番号',
                'awaiting' => 'リーシングチームの確認待ち', 'done_hint' => 'リクエストをスタッフに送ると、確認がスムーズです。',
                'send_whatsapp' => 'WhatsAppで送る', 'call_apartment' => 'リーシングオフィスに電話', 'email_apartment' => 'リーシングオフィスにメール', 'new_request' => '新しいリクエスト',
                'error_network' => '接続に問題があります。入力内容は保存されています。もう一度お試しいただくか、スタッフにご連絡ください。',
                'error_generic' => 'エラーが発生しました。もう一度お試しいただくか、スタッフにご連絡ください。', 'select_unit' => 'お部屋タイプを選択してください。',
                'contact_staff' => 'スタッフに連絡',
            ],
            default => [
                'title' => 'Permintaan sewa', 'intro' => 'Hanya beberapa langkah singkat. Ketersediaan final dikonfirmasi oleh tim leasing kami.',
                'step_of' => 'Langkah :current dari :total', 'steps' => ['Tanggal', 'Penghuni', 'Unit', 'Data Anda', 'Ringkasan'],
                'cal_prev' => 'Bulan sebelumnya', 'cal_next' => 'Bulan berikutnya', 'cal_pick_in' => 'Pilih tanggal check-in.', 'cal_pick_out' => 'Sekarang pilih tanggal check-out.', 'cal_none' => 'Belum ada tanggal yang dibuka untuk permintaan online. Tanyakan ke concierge atau tim apartemen.', 'cal_full' => 'Penuh', 'cal_choose' => 'Pilih tanggal',
                'check_in' => 'Check-in', 'check_out' => 'Check-out', 'nights' => 'malam', 'adults' => 'Dewasa', 'children' => 'Anak', 'units' => 'Jumlah unit',
                'choose_unit' => 'Pilih tipe unit', 'fits' => 'Maks. :count penghuni per unit', 'too_small' => 'Tidak cukup untuk jumlah penghuni Anda',
                'extra_bed' => 'Tambah kasur lipat', 'name' => 'Nama lengkap', 'contact_method' => 'Bagaimana kami menghubungi Anda?',
                'whatsapp' => 'WhatsApp', 'phone' => 'Telepon', 'email' => 'Email', 'contact_value' => 'Nomor atau alamat email',
                'special' => 'Permintaan khusus (opsional)', 'special_placeholder' => 'mis. lantai tinggi, slot parkir, membawa hewan peliharaan',
                'next' => 'Lanjut', 'back' => 'Kembali', 'edit' => 'Ubah', 'submit' => 'Kirim permintaan sewa', 'sending' => 'Mengirim…',
                'checking' => 'Memeriksa ketersediaan…', 'estimated_total' => 'Perkiraan total', 'subtotal' => 'Subtotal', 'discount' => 'Diskon menginap lama',
                'guests' => 'Penghuni', 'unit' => 'Unit', 'dates' => 'Tanggal',
                'contact' => 'Kontak', 'disclaimer' => 'Ini adalah permintaan sewa, bukan kontrak. Ketersediaan dan tarif final dikonfirmasi oleh tim leasing. Belum ada pembayaran yang diambil.',
                'available_instead' => 'Tersedia di tanggal Anda:', 'done_title' => 'Permintaan diterima', 'reference' => 'Nomor referensi',
                'awaiting' => 'Menunggu konfirmasi tim leasing', 'done_hint' => 'Kirim permintaan ke tim kami agar konfirmasi lebih cepat.',
                'send_whatsapp' => 'Kirim ke WhatsApp', 'call_apartment' => 'Telepon kantor leasing', 'email_apartment' => 'Email kantor leasing', 'new_request' => 'Permintaan baru',
                'error_network' => 'Koneksi bermasalah. Data Anda tersimpan — coba lagi atau hubungi tim apartemen.',
                'error_generic' => 'Terjadi kesalahan. Coba lagi atau hubungi tim apartemen.', 'select_unit' => 'Silakan pilih tipe unit.',
                'contact_staff' => 'Hubungi tim apartemen',
            ],
        }];
    }

    /**
     * @return array<string, string>
     */
    private function lobbyLabels(string $locale): array
    {
        return match ($locale) {
            'en' => [
                'welcome' => 'Welcome to your', 'lobby' => 'virtual lobby',
                'intro' => 'Fully furnished city apartments for a week, a month or longer. Take the lift to any floor — your AI concierge rides along.',
                'home' => 'Lobby', 'explore' => 'Building directory', 'reservation' => 'Plan a stay', 'floor' => 'Floor', 'elevator' => 'Lift',
                'reservation_intro' => 'Tell your AI Concierge your dates and how many people are moving in. We will help you find a unit and submit a stay request.',
                'reservation_q' => 'I would like to rent a unit. Please help me check availability.',
                'start_booking' => 'Plan my stay', 'available' => 'Concierge on duty, 24/7',
                'assistant' => 'Your resident concierge', 'illustration' => 'AI illustration',
                'staff_intro' => 'Need a person? Message us and the apartment team — leasing, building management and housekeeping — will pick it up.',
                'empty' => 'Ask your AI Concierge for more information.', 'back' => 'Back to the lobby',
                'check_in' => 'Check-in', 'check_out' => 'Check-out', 'location' => 'Find the building',
                'connection_error' => 'Chat could not connect. Please reload to try again.',
                'units_empty' => 'Unit information will be available soon. Please ask our team.',
                'menu_info' => 'Building information', 'info_about' => 'About the building', 'info_policies' => 'House rules', 'info_faq' => 'Frequently asked questions', 'info_tab_about' => 'About', 'info_tab_faq' => 'FAQ',
                'info_address' => 'Address', 'info_hours' => 'Check-in / check-out', 'info_contact' => 'Contact', 'info_map' => 'Open in Maps', 'info_ask' => 'Ask about the building',
                'facility_counter' => 'Facility', 'prev_facility' => 'Previous', 'next_facility' => 'Next', 'ask_facility' => 'Ask about this facility', 'back_facilities' => 'All facilities', 'open_facility' => 'Read more', 'ask_facility_q' => 'Tell me more about :name.',
                'loading' => 'Calling the lift…',
                'sound_on' => 'Sound on', 'sound_off' => 'Sound off',
                'unit_scene' => 'Unit details', 'unit_counter' => 'Unit type', 'gallery' => 'Photo gallery', 'photo' => 'Photo',
                'no_photo' => 'Photos coming soon', 'prev_unit' => 'Previous', 'next_unit' => 'Next',
                'ask_unit' => 'Ask about this unit', 'reserve_unit' => 'Request this unit',
                'size' => 'Size', 'bed' => 'Beds', 'guests' => 'Residents', 'view' => 'View', 'extra_bed' => 'Rollaway bed', 'layout' => 'Layout', 'floors' => 'Floors',
                'studio' => 'Studio', 'bedrooms' => ':count bed · :baths bath',
                'extra_bed_yes' => 'Available', 'extra_bed_no' => 'Not available', 'amenities' => 'In the unit',
                'availability_note' => 'Final availability and rates are confirmed by the apartment team.',
                'monthly_estimate' => 'Monthly stay from about :price / month',
                'ask_unit_q' => 'Tell me more about the :name.',
                'cta_units' => 'See the residences', 'stat_from' => 'Nightly from', 'stat_monthly' => 'Monthly stays',
                'stat_monthly_value' => ':percent% off', 'stat_checkin' => 'Check-in',
            ],
            'ja' => [
                'welcome' => 'ようこそ', 'lobby' => 'バーチャルロビーへ',
                'intro' => '家具付きの都市型アパートメントを、1週間から1か月、それ以上でも。エレベーターでどのフロアへも — AIコンシェルジュがご一緒します。',
                'home' => 'ロビー', 'explore' => 'フロアガイド', 'reservation' => '滞在を計画する', 'floor' => 'フロア', 'elevator' => 'エレベーター',
                'reservation_intro' => 'ご希望の日程と入居人数をAIコンシェルジュにお伝えください。お部屋探しと滞在リクエストをお手伝いします。',
                'reservation_q' => 'お部屋を借りたいです。空室を確認してください。',
                'start_booking' => '滞在を計画する', 'available' => 'コンシェルジュ 24時間対応',
                'assistant' => 'レジデントコンシェルジュ', 'illustration' => 'AIイラスト',
                'staff_intro' => 'スタッフと話したい場合は、メッセージをお送りください。リーシング・管理・ハウスキーピングのチームが対応します。',
                'empty' => '詳しくはAIコンシェルジュにお尋ねください。', 'back' => 'ロビーに戻る',
                'check_in' => 'チェックイン', 'check_out' => 'チェックアウト', 'location' => 'アクセス',
                'connection_error' => 'チャットに接続できませんでした。再読み込みしてください。',
                'units_empty' => 'お部屋の情報は準備中です。スタッフにお尋ねください。',
                'menu_info' => '建物のご案内', 'info_about' => '建物について', 'info_policies' => '館内ルール', 'info_faq' => 'よくあるご質問', 'info_tab_about' => '概要', 'info_tab_faq' => 'FAQ',
                'info_address' => '所在地', 'info_hours' => 'チェックイン / チェックアウト', 'info_contact' => 'お問い合わせ', 'info_map' => '地図で開く', 'info_ask' => '建物について聞く',
                'facility_counter' => '施設', 'prev_facility' => '前へ', 'next_facility' => '次へ', 'ask_facility' => 'この施設について聞く', 'back_facilities' => '施設一覧', 'open_facility' => '詳しく見る', 'ask_facility_q' => ':name について詳しく教えてください。',
                'loading' => 'エレベーターを呼んでいます…',
                'sound_on' => 'サウンドオン', 'sound_off' => 'サウンドオフ',
                'unit_scene' => 'お部屋のご案内', 'unit_counter' => 'タイプ', 'gallery' => 'フォトギャラリー', 'photo' => '写真',
                'no_photo' => '写真は準備中です', 'prev_unit' => '前へ', 'next_unit' => '次へ',
                'ask_unit' => 'このお部屋について聞く', 'reserve_unit' => 'このお部屋をリクエスト',
                'size' => '広さ', 'bed' => 'ベッド', 'guests' => '定員', 'view' => '眺望', 'extra_bed' => '簡易ベッド', 'layout' => '間取り', 'floors' => 'フロア',
                'studio' => 'スタジオ', 'bedrooms' => ':count ベッドルーム · :baths バスルーム',
                'extra_bed_yes' => '利用可', 'extra_bed_no' => '利用不可', 'amenities' => 'お部屋の設備',
                'availability_note' => '空室状況と料金は、スタッフが最終確認いたします。',
                'monthly_estimate' => '月単位の滞在は約 :price / 月から',
                'ask_unit_q' => ':name について詳しく教えてください。',
                'cta_units' => 'お部屋を見る', 'stat_from' => '1泊あたり', 'stat_monthly' => '月単位の滞在',
                'stat_monthly_value' => ':percent%オフ', 'stat_checkin' => 'チェックイン',
            ],
            default => [
                'welcome' => 'Selamat datang di', 'lobby' => 'lobi virtual Anda',
                'intro' => 'Apartemen kota siap huni untuk seminggu, sebulan, atau lebih. Naik lift ke lantai mana pun — AI Concierge ikut menemani.',
                'home' => 'Lobi', 'explore' => 'Direktori gedung', 'reservation' => 'Rencanakan menginap', 'floor' => 'Lantai', 'elevator' => 'Lift',
                'reservation_intro' => 'Ceritakan tanggal dan jumlah penghuni kepada AI Concierge. Kami bantu carikan unit hingga pengajuan permintaan sewa.',
                'reservation_q' => 'Saya ingin menyewa unit. Bantu saya cek ketersediaan.',
                'start_booking' => 'Rencanakan menginap', 'available' => 'Concierge siaga 24 jam',
                'assistant' => 'Concierge penghuni Anda', 'illustration' => 'Ilustrasi AI',
                'staff_intro' => 'Butuh bicara dengan orang? Kirim pesan dan tim apartemen — leasing, pengelola gedung, dan housekeeping — akan menindaklanjuti.',
                'empty' => 'Tanyakan informasi selengkapnya kepada AI Concierge.', 'back' => 'Kembali ke lobi',
                'check_in' => 'Check-in', 'check_out' => 'Check-out', 'location' => 'Lokasi gedung',
                'connection_error' => 'Chat belum tersambung. Muat ulang halaman untuk mencoba lagi.',
                'units_empty' => 'Informasi unit segera tersedia. Silakan tanyakan kepada tim kami.',
                'menu_info' => 'Informasi gedung', 'info_about' => 'Tentang gedung', 'info_policies' => 'Aturan gedung', 'info_faq' => 'Pertanyaan yang sering diajukan', 'info_tab_about' => 'Tentang', 'info_tab_faq' => 'FAQ',
                'info_address' => 'Alamat', 'info_hours' => 'Check-in / check-out', 'info_contact' => 'Kontak', 'info_map' => 'Buka di Maps', 'info_ask' => 'Tanya tentang gedung',
                'facility_counter' => 'Fasilitas', 'prev_facility' => 'Sebelumnya', 'next_facility' => 'Berikutnya', 'ask_facility' => 'Tanya tentang fasilitas ini', 'back_facilities' => 'Semua fasilitas', 'open_facility' => 'Selengkapnya', 'ask_facility_q' => 'Ceritakan lebih banyak tentang :name.',
                'loading' => 'Memanggil lift…',
                'sound_on' => 'Suara aktif', 'sound_off' => 'Suara mati',
                'unit_scene' => 'Detail unit', 'unit_counter' => 'Tipe unit', 'gallery' => 'Galeri foto', 'photo' => 'Foto',
                'no_photo' => 'Foto segera tersedia', 'prev_unit' => 'Sebelumnya', 'next_unit' => 'Berikutnya',
                'ask_unit' => 'Tanya tentang unit ini', 'reserve_unit' => 'Ajukan sewa unit ini',
                'size' => 'Luas', 'bed' => 'Tempat tidur', 'guests' => 'Kapasitas', 'view' => 'Pemandangan', 'extra_bed' => 'Kasur lipat', 'layout' => 'Denah', 'floors' => 'Lantai',
                'studio' => 'Studio', 'bedrooms' => ':count kamar tidur · :baths kamar mandi',
                'extra_bed_yes' => 'Tersedia', 'extra_bed_no' => 'Tidak tersedia', 'amenities' => 'Di dalam unit',
                'availability_note' => 'Ketersediaan dan tarif final dikonfirmasi oleh tim apartemen.',
                'monthly_estimate' => 'Sewa bulanan mulai sekitar :price / bulan',
                'ask_unit_q' => 'Ceritakan lebih banyak tentang :name.',
                'cta_units' => 'Lihat unit', 'stat_from' => 'Per malam mulai', 'stat_monthly' => 'Sewa bulanan',
                'stat_monthly_value' => 'hemat :percent%', 'stat_checkin' => 'Check-in',
            ],
        };
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private function labels(string $locale): array
    {
        return [...match ($locale) {
            'en' => [
                'from' => 'from',
                'per_night' => '/ night',
                'ask_ai' => 'Ask the AI Concierge',
                'units_heading' => 'Residences',
                'chat_heading' => 'AI Concierge',
                'chat_subtitle' => 'Ask about units, long stays or the building — on duty 24/7.',
                'chat_placeholder' => 'Ask about a unit, a rate or a house rule…',
                'chat_send' => 'Send',
                'chat_mic_start' => 'Speak your message', 'chat_mic_stop' => 'Stop listening', 'chat_mic_listening' => 'Listening…', 'chat_mic_denied' => 'Microphone access is blocked. Allow it in your browser settings to speak.', 'chat_mic_error' => 'Could not hear you. Please try again.',
                'chat_open' => 'Talk to the AI Concierge',
                'chat_close' => 'Close conversation',
                'chat_intro' => "Hi! I'm the AI Concierge of this building. Ask me about units, long-stay rates, facilities or anything about living here.",
                'breakfast_included' => 'Breakfast included',
                'max_guests' => 'residents',
                'chat_unit_only' => 'Unit only',
                'chat_night' => 'night(s)',
                'chat_studio' => 'Studio',
                'chat_bedrooms' => 'BR',
                'chat_discount' => 'long-stay discount',
                'chat_no_availability' => 'No availability for those dates.',
                'chat_booking_received' => 'Stay request received',
                'chat_reference' => 'Ref',
                'chat_view_suffix' => 'view',
                'handed_over' => 'A member of the apartment team has joined this conversation and will reply shortly.',
                'chat_status_sent' => 'Message sent',
                'chat_status_waiting' => 'Waiting for staff reply',
                'chat_status_replied' => 'Staff has replied',
                'chat_draft_title' => 'Unsaved message',
                'chat_draft_body' => 'This message has not been sent. Keep it as a draft?',
                'chat_draft_keep' => 'Keep typing',
                'chat_draft_discard' => 'Discard draft',
                'thinking' => 'Thinking…',
                'chat_error' => "I'm having trouble responding right now. Please try again or contact the apartment team.", 'chat_slow' => 'You are sending messages very quickly. Please wait a moment and try again.', 'chat_retry' => 'Retry',
                'view_details' => 'View unit',
                'unit_details_question' => 'Show me more details and photos of :unit',
                'book_now' => 'Request this unit',
                'menu_heading' => 'Start here',
                'menu_units' => 'Find a unit',
                'menu_units_q' => 'I would like to see the available unit types.',
                'menu_facilities' => 'Shared facilities',
                'menu_facilities_q' => 'What facilities can residents use?',
                'menu_policies' => 'House rules & FAQ',
                'menu_policies_q' => 'What are the check-in, house rules and cancellation policies?',
                'menu_staff' => 'Talk to the team',
                'menu_staff_q' => 'I would like to speak with the apartment team.',
            ],
            'ja' => [
                'from' => '',
                'per_night' => '〜 / 泊',
                'ask_ai' => 'AIコンシェルジュに聞く',
                'units_heading' => 'レジデンス',
                'chat_heading' => 'AIコンシェルジュ',
                'chat_subtitle' => 'お部屋・長期滞在・建物について24時間いつでもどうぞ。',
                'chat_placeholder' => 'お部屋、料金、館内ルールについて質問…',
                'chat_send' => '送信',
                'chat_mic_start' => '音声で入力', 'chat_mic_stop' => '音声入力を停止', 'chat_mic_listening' => '聞き取り中…', 'chat_mic_denied' => 'マイクへのアクセスがブロックされています。ブラウザの設定で許可してください。', 'chat_mic_error' => '聞き取れませんでした。もう一度お試しください。',
                'chat_open' => 'AIコンシェルジュに相談',
                'chat_close' => '会話を閉じる',
                'chat_intro' => 'こんにちは。この建物のAIコンシェルジュです。お部屋、長期滞在の料金、共用施設など、暮らしについて何でもお尋ねください。',
                'breakfast_included' => '朝食付き',
                'max_guests' => '名まで',
                'chat_unit_only' => '食事なし',
                'chat_night' => '泊',
                'chat_studio' => 'スタジオ',
                'chat_bedrooms' => 'BR',
                'chat_discount' => '長期滞在割引',
                'chat_no_availability' => 'ご希望の日程には空室がありません。',
                'chat_booking_received' => '滞在リクエストを受け付けました',
                'chat_reference' => '受付番号',
                'chat_view_suffix' => 'の眺望',
                'handed_over' => 'スタッフがこの会話に参加しました。まもなく返信いたします。',
                'chat_status_sent' => 'メッセージを送信しました',
                'chat_status_waiting' => 'スタッフの返信を待っています',
                'chat_status_replied' => 'スタッフが返信しました',
                'chat_draft_title' => '未送信のメッセージ',
                'chat_draft_body' => 'このメッセージはまだ送信されていません。下書きとして残しますか？',
                'chat_draft_keep' => '入力を続ける',
                'chat_draft_discard' => '下書きを破棄',
                'thinking' => '入力中…',
                'chat_error' => '現在うまくお答えできません。もう一度お試しいただくか、スタッフにご連絡ください。', 'chat_slow' => 'メッセージが多すぎます。少し待ってからもう一度お試しください。', 'chat_retry' => '再試行',
                'view_details' => 'お部屋を見る',
                'unit_details_question' => ':unit の詳細と写真を見せてください',
                'book_now' => 'このお部屋をリクエスト',
                'menu_heading' => 'ここから始める',
                'menu_units' => 'お部屋を探す',
                'menu_units_q' => '空いているお部屋タイプを見せてください。',
                'menu_facilities' => '共用施設',
                'menu_facilities_q' => '入居者が使える施設を教えてください。',
                'menu_policies' => '館内ルール・FAQ',
                'menu_policies_q' => 'チェックイン、館内ルール、キャンセルのポリシーを教えてください。',
                'menu_staff' => 'スタッフに相談',
                'menu_staff_q' => 'アパートメントのスタッフと話したいです。',
            ],
            default => [
                'from' => 'mulai dari',
                'per_night' => '/ malam',
                'ask_ai' => 'Tanya AI Concierge',
                'units_heading' => 'Unit Hunian',
                'chat_heading' => 'AI Concierge',
                'chat_subtitle' => 'Tanyakan unit, sewa jangka panjang, atau gedung — siaga 24 jam.',
                'chat_placeholder' => 'Tanya soal unit, tarif, atau aturan gedung…',
                'chat_send' => 'Kirim',
                'chat_mic_start' => 'Ucapkan pesan', 'chat_mic_stop' => 'Berhenti mendengarkan', 'chat_mic_listening' => 'Mendengarkan…', 'chat_mic_denied' => 'Akses mikrofon diblokir. Izinkan di pengaturan browser untuk berbicara.', 'chat_mic_error' => 'Suara tidak terdengar. Silakan coba lagi.',
                'chat_open' => 'Ngobrol dengan AI Concierge',
                'chat_close' => 'Tutup percakapan',
                'chat_intro' => 'Halo! Saya AI Concierge gedung ini. Tanyakan apa saja soal unit, tarif sewa jangka panjang, fasilitas, atau kehidupan di sini.',
                'breakfast_included' => 'Termasuk sarapan',
                'max_guests' => 'penghuni',
                'chat_unit_only' => 'Tanpa sarapan',
                'chat_night' => 'malam',
                'chat_studio' => 'Studio',
                'chat_bedrooms' => 'KT',
                'chat_discount' => 'diskon menginap lama',
                'chat_no_availability' => 'Tidak ada unit tersedia untuk tanggal tersebut.',
                'chat_booking_received' => 'Permintaan sewa diterima',
                'chat_reference' => 'Ref',
                'chat_view_suffix' => 'pemandangan',
                'handed_over' => 'Tim apartemen telah bergabung dalam percakapan ini dan akan segera membalas.',
                'chat_status_sent' => 'Pesan terkirim',
                'chat_status_waiting' => 'Menunggu balasan staf',
                'chat_status_replied' => 'Staf telah membalas',
                'chat_draft_title' => 'Pesan belum dikirim',
                'chat_draft_body' => 'Pesan ini belum dikirim. Simpan sebagai draft?',
                'chat_draft_keep' => 'Lanjut mengetik',
                'chat_draft_discard' => 'Buang draft',
                'thinking' => 'Sedang mengetik…',
                'chat_error' => 'Saya sedang kesulitan menjawab. Silakan coba lagi atau hubungi tim apartemen.', 'chat_slow' => 'Pesan terlalu cepat. Mohon tunggu sebentar lalu coba lagi.', 'chat_retry' => 'Coba lagi',
                'view_details' => 'Lihat unit',
                'unit_details_question' => 'Tunjukkan detail dan foto lengkap :unit',
                'book_now' => 'Ajukan sewa unit ini',
                'menu_heading' => 'Mulai dari sini',
                'menu_units' => 'Cari unit',
                'menu_units_q' => 'Saya ingin melihat pilihan tipe unit yang tersedia.',
                'menu_facilities' => 'Fasilitas bersama',
                'menu_facilities_q' => 'Fasilitas apa saja yang bisa dipakai penghuni?',
                'menu_policies' => 'Aturan & FAQ',
                'menu_policies_q' => 'Apa kebijakan check-in, aturan gedung, dan pembatalan?',
                'menu_staff' => 'Bicara dengan tim',
                'menu_staff_q' => 'Saya ingin bicara dengan tim apartemen.',
            ],
        }, 'showcase' => $this->showcaseLabels($locale)];
    }

    /**
     * Wording for the concierge's centre-stage showcase: units presented to
     * the guest and the stay request form that opens from the chat.
     *
     * @return array<string, string>
     */
    private function showcaseLabels(string $locale): array
    {
        return match ($locale) {
            'en' => [
                'presenting' => 'AI Concierge', 'presenting_hint' => 'is showing you',
                'close' => 'Close', 'prev' => 'Previous photo', 'next' => 'Next photo', 'photo' => 'Photo :n of :total', 'no_photo' => 'No photo yet',
                'results_title' => 'Picked for you', 'results_dates' => ':in → :out · :nights night(s)', 'results_empty' => 'No units to show.', 'scroll_prev' => 'Previous', 'scroll_next' => 'Next',
                'view' => 'See this unit', 'reserve' => 'Request this unit', 'ask' => 'Ask about it', 'ask_question' => 'I have a question about :unit', 'show_again' => 'Show on stage', 'reopen_form' => 'Open request form',
                'from' => 'from', 'per_night' => '/ night', 'total_for' => ':nights night(s) in total', 'discount' => 'long-stay discount',
                'studio' => 'Studio', 'bedrooms' => ':count bedroom(s)', 'bathrooms' => ':count bath', 'floor' => 'Floors :range', 'residents' => 'Up to :count residents', 'view_label' => ':view view', 'breakfast' => 'Breakfast included', 'size' => 'Size', 'beds' => 'Beds', 'layout' => 'Layout', 'guests' => 'Residents', 'view_title' => 'View', 'floors' => 'Floors',
                'amenities' => 'What is inside', 'long_stay' => 'Long stays: :weekly% off from :weekly_nights nights, :monthly% off from :monthly_nights nights.',
                'form_title' => 'Stay request', 'form_intro' => 'Fill in your details — no payment now. The leasing team confirms availability.',
                'check_in' => 'Move-in', 'check_out' => 'Move-out', 'adults' => 'Adults', 'children' => 'Children', 'units' => 'Units', 'nights' => 'night(s)',
                'name' => 'Full name', 'unit' => 'Unit', 'extra_bed' => 'Add a rollaway bed (+:price / night)', 'contact_method' => 'Contact me via', 'whatsapp' => 'WhatsApp', 'phone' => 'Phone', 'email' => 'Email', 'contact_value' => 'Number or email address',
                'special' => 'Special request (optional)', 'special_placeholder' => 'e.g. high floor, parking space',
                'estimated_total' => 'Estimated total', 'pick_dates' => 'Pick your dates to see the price.', 'need_dates' => 'Please choose your move-in and move-out dates.', 'need_name' => 'Please enter your name.', 'need_contact' => 'Please enter how we can reach you.', 'checking' => 'Checking availability…', 'subtotal' => 'Subtotal',
                'disclaimer' => 'This is a stay request, not a lease. No payment is taken now.', 'submit' => 'Send stay request', 'sending' => 'Sending…', 'back' => 'Back',
                'available_instead' => 'Available for your dates instead:', 'error_network' => 'Connection problem. Your details are kept — please try again.', 'error_generic' => 'Something went wrong. Please try again or contact the apartment team.',
                'done_title' => 'Request received', 'done_line' => 'Thank you, :name. We will confirm shortly.', 'reference' => 'Your reference', 'awaiting' => 'Awaiting confirmation from the leasing team',
                'done_hint' => 'Send it to our team to speed things up.', 'send_whatsapp' => 'Send to WhatsApp', 'call_team' => 'Call', 'email_team' => 'Email', 'back_to_chat' => 'Back to chat',
            ],
            'ja' => [
                'presenting' => 'AIコンシェルジュ', 'presenting_hint' => 'がご案内します',
                'close' => '閉じる', 'prev' => '前の写真', 'next' => '次の写真', 'photo' => '写真 :n / :total', 'no_photo' => '写真は準備中です',
                'results_title' => 'おすすめのお部屋', 'results_dates' => ':in → :out · :nights泊', 'results_empty' => '表示できるお部屋がありません。', 'scroll_prev' => '前へ', 'scroll_next' => '次へ',
                'view' => 'このお部屋を見る', 'reserve' => 'このお部屋をリクエスト', 'ask' => '質問する', 'ask_question' => ':unit について質問があります', 'show_again' => 'もう一度表示', 'reopen_form' => 'リクエストフォームを開く',
                'from' => '', 'per_night' => '〜 / 泊', 'total_for' => ':nights泊の合計', 'discount' => '長期滞在割引',
                'studio' => 'スタジオ', 'bedrooms' => ':count ベッドルーム', 'bathrooms' => 'バス:count', 'floor' => ':range階', 'residents' => '最大:count名', 'view_label' => ':viewの眺望', 'breakfast' => '朝食付き', 'size' => '広さ', 'beds' => 'ベッド', 'layout' => '間取り', 'guests' => '入居人数', 'view_title' => '眺望', 'floors' => '階数',
                'amenities' => '設備・備品', 'long_stay' => '長期滞在：:weekly_nights泊以上で:weekly%オフ、:monthly_nights泊以上で:monthly%オフ。',
                'form_title' => '滞在リクエスト', 'form_intro' => 'ご情報を入力してください。お支払いは不要です。空室はリーシングチームが確認します。',
                'check_in' => '入居日', 'check_out' => '退去日', 'adults' => '大人', 'children' => '子ども', 'units' => '戸数', 'nights' => '泊',
                'name' => 'お名前', 'unit' => 'お部屋', 'extra_bed' => '簡易ベッドを追加（+:price / 泊）', 'contact_method' => 'ご連絡方法', 'whatsapp' => 'WhatsApp', 'phone' => '電話', 'email' => 'メール', 'contact_value' => '番号またはメールアドレス',
                'special' => 'ご要望（任意）', 'special_placeholder' => '例：高層階、駐車スペース',
                'estimated_total' => '概算合計', 'pick_dates' => '日程を選ぶと料金が表示されます。', 'need_dates' => '入居日と退去日を選択してください。', 'need_name' => 'お名前を入力してください。', 'need_contact' => 'ご連絡先を入力してください。', 'checking' => '空室を確認中…', 'subtotal' => '小計',
                'disclaimer' => 'これは滞在のリクエストであり、賃貸契約ではありません。現時点でお支払いは発生しません。', 'submit' => '滞在リクエストを送信', 'sending' => '送信中…', 'back' => '戻る',
                'available_instead' => 'ご希望の日程で空いているお部屋：', 'error_network' => '接続に問題があります。入力内容は保持されています。もう一度お試しください。', 'error_generic' => 'エラーが発生しました。もう一度お試しいただくか、スタッフにご連絡ください。',
                'done_title' => 'リクエストを受け付けました', 'done_line' => ':nameさま、ありがとうございます。まもなくご連絡します。', 'reference' => '受付番号', 'awaiting' => 'リーシングチームの確認待ち',
                'done_hint' => 'スタッフに送ると、確認がスムーズです。', 'send_whatsapp' => 'WhatsAppで送る', 'call_team' => '電話', 'email_team' => 'メール', 'back_to_chat' => 'チャットに戻る',
            ],
            default => [
                'presenting' => 'AI Concierge', 'presenting_hint' => 'sedang menunjukkan untuk Anda',
                'close' => 'Tutup', 'prev' => 'Foto sebelumnya', 'next' => 'Foto berikutnya', 'photo' => 'Foto :n dari :total', 'no_photo' => 'Belum ada foto',
                'results_title' => 'Pilihan untuk Anda', 'results_dates' => ':in → :out · :nights malam', 'results_empty' => 'Tidak ada unit untuk ditampilkan.', 'scroll_prev' => 'Sebelumnya', 'scroll_next' => 'Berikutnya',
                'view' => 'Lihat unit ini', 'reserve' => 'Ajukan sewa unit ini', 'ask' => 'Tanya soal unit ini', 'ask_question' => 'Saya ingin bertanya soal :unit', 'show_again' => 'Tampilkan di layar', 'reopen_form' => 'Buka formulir sewa',
                'from' => 'mulai', 'per_night' => '/ malam', 'total_for' => 'total :nights malam', 'discount' => 'diskon menginap lama',
                'studio' => 'Studio', 'bedrooms' => ':count kamar tidur', 'bathrooms' => ':count kamar mandi', 'floor' => 'Lantai :range', 'residents' => 'Maks. :count penghuni', 'view_label' => 'Pemandangan :view', 'breakfast' => 'Termasuk sarapan', 'size' => 'Luas', 'beds' => 'Tempat tidur', 'layout' => 'Tata letak', 'guests' => 'Penghuni', 'view_title' => 'Pemandangan', 'floors' => 'Lantai',
                'amenities' => 'Isi unit', 'long_stay' => 'Sewa lama: hemat :weekly% mulai :weekly_nights malam, hemat :monthly% mulai :monthly_nights malam.',
                'form_title' => 'Permintaan sewa', 'form_intro' => 'Lengkapi data Anda — belum ada pembayaran. Tim leasing yang mengonfirmasi ketersediaan.',
                'check_in' => 'Check-in', 'check_out' => 'Check-out', 'adults' => 'Dewasa', 'children' => 'Anak', 'units' => 'Jumlah unit', 'nights' => 'malam',
                'name' => 'Nama lengkap', 'unit' => 'Unit', 'extra_bed' => 'Tambah kasur lipat (+:price / malam)', 'contact_method' => 'Hubungi saya lewat', 'whatsapp' => 'WhatsApp', 'phone' => 'Telepon', 'email' => 'Email', 'contact_value' => 'Nomor atau alamat email',
                'special' => 'Permintaan khusus (opsional)', 'special_placeholder' => 'mis. lantai tinggi, slot parkir',
                'estimated_total' => 'Perkiraan total', 'pick_dates' => 'Pilih tanggal untuk melihat harga.', 'need_dates' => 'Silakan pilih tanggal check-in dan check-out.', 'need_name' => 'Silakan isi nama Anda.', 'need_contact' => 'Silakan isi kontak yang bisa kami hubungi.', 'checking' => 'Memeriksa ketersediaan…', 'subtotal' => 'Subtotal',
                'disclaimer' => 'Ini permintaan sewa, bukan kontrak. Belum ada pembayaran yang diambil.', 'submit' => 'Kirim permintaan sewa', 'sending' => 'Mengirim…', 'back' => 'Kembali',
                'available_instead' => 'Tersedia di tanggal Anda:', 'error_network' => 'Koneksi bermasalah. Data Anda tersimpan — coba lagi.', 'error_generic' => 'Terjadi kesalahan. Coba lagi atau hubungi tim apartemen.',
                'done_title' => 'Permintaan diterima', 'done_line' => 'Terima kasih, :name. Kami segera mengonfirmasi.', 'reference' => 'Nomor referensi', 'awaiting' => 'Menunggu konfirmasi tim leasing',
                'done_hint' => 'Kirim ke tim kami agar konfirmasi lebih cepat.', 'send_whatsapp' => 'Kirim ke WhatsApp', 'call_team' => 'Telepon', 'email_team' => 'Email', 'back_to_chat' => 'Kembali ke chat',
            ],
        };
    }
}
