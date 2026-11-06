<?php

namespace Database\Seeders;

use App\Models\Apartment;
use App\Models\ApartmentKnowledgeItem;
use App\Models\Unit;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoApartmentSeeder extends Seeder
{
    /** Yearly leases of up to three years need inventory for every day of the lease, so this reaches about three years ahead. */
    private const INVENTORY_DAYS = 1100;

    /** @var array<int, int> How many apartments have been numbered on each floor so far, shared by all unit types. */
    private array $unitsOnFloor = [];

    public function run(): void
    {
        $this->unitsOnFloor = [];

        $owner = User::firstOrCreate(
            ['email' => 'owner@ftsapartment.test'],
            [
                'name' => 'Owner',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $description = [
            'id' => 'Serviced apartment 32 lantai di Senopati, Jakarta Selatan. Unit berperabot lengkap dengan dapur dan Wi-Fi fiber, layanan kebersihan opsional — untuk disewa bulanan atau tahunan. 8 menit jalan kaki ke MRT ASEAN.',
            'en' => 'A 32-storey serviced apartment tower in Senopati, South Jakarta. Fully furnished units with kitchens and fibre Wi-Fi, plus optional cleaning — for monthly or yearly leases. An 8-minute walk to ASEAN MRT station.',
            'ja' => '南ジャカルタ・スノパティにある32階建てのサービスアパートメント。キッチンと光Wi-Fi付きの家具付きのお部屋（清掃は有料オプション）を、月契約・年契約でご利用いただけます。MRTアセアン駅まで徒歩8分。',
        ];

        $apartment = Apartment::updateOrCreate(
            ['name' => 'FTS Apartment AI'],
            [
                'slug' => Apartment::where('name', 'FTS Apartment AI')->value('slug') ?? Apartment::generateUniqueSlug('FTS Apartment AI'),
                'description' => $description['id'],
                'translations' => collect($description)->map(fn (string $text) => ['description' => $text])->all(),
                'address' => 'Jl. Senopati No. 88, Kebayoran Baru',
                'city' => 'Jakarta Selatan',
                'country' => 'Indonesia',
                'latitude' => -6.2297,
                'longitude' => 106.8090,
                'phone' => '+62 21 5098 8800',
                'whatsapp' => '6281234567890',
                'email' => 'leasing@ftsapartment.test',
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'default_locale' => 'id',
                'security_deposit_months' => 2,
                'min_monthly_lease_months' => 3,
                'cover_path' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=1920&q=80',
                'public_status' => 'published',
            ]
        );

        $apartment->users()->syncWithoutDetaching([
            $owner->id => ['role' => 'owner', 'status' => 'active'],
        ]);

        $sort = 0;
        foreach ($this->unitTypeDefinitions() as $definition) {
            $images = $definition['images'];
            $totalUnits = $definition['total_units'];
            unset($definition['images'], $definition['total_units']);

            $unitType = $apartment->unitTypes()->updateOrCreate(
                ['slug' => $definition['slug']],
                [...$definition, 'sort_order' => $sort++, 'is_active' => true]
            );

            $unitType->images()->delete();
            foreach ($images as $imageSort => $image) {
                $unitType->images()->create([
                    'image_url' => $image['url'],
                    'tags' => $image['tags'],
                    'alt_text' => $image['alt'],
                    'sort_order' => $imageSort,
                ]);
            }

            // Units that are only for sale are not rented, so they hold no inventory.
            if ($unitType->isForRent()) {
                $this->seedInventory($unitType, $totalUnits);
            }

            $this->seedUnits($apartment, $unitType, $totalUnits > 0 ? $totalUnits : 2);
        }

        $this->seedKnowledgeBase($apartment);

        $this->command?->info('Demo login: owner@ftsapartment.test — password: password');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unitTypeDefinitions(): array
    {
        $photo = fn (string $id) => "https://images.unsplash.com/photo-{$id}?auto=format&fit=crop&w=1200&q=80";

        return [
            [
                'slug' => 'studio-urban',
                'name' => 'Studio Urban',
                'description' => 'Studio 28 m² di lantai 6–14, sudah berperabot lengkap: tempat tidur queen, dapur kecil, meja kerja, AC, smart TV, dan Wi-Fi fiber. Jendelanya menghadap kota; paket kebersihan unit tersedia sebagai layanan opsional. Pas untuk profesional yang tinggal sendiri.',
                'translations' => [
                    'id' => ['name' => 'Studio Urban', 'description' => 'Studio 28 m² di lantai 6–14, sudah berperabot lengkap: tempat tidur queen, dapur kecil, meja kerja, AC, smart TV, dan Wi-Fi fiber. Jendelanya menghadap kota; paket kebersihan unit tersedia sebagai layanan opsional. Pas untuk profesional yang tinggal sendiri.'],
                    'en' => ['name' => 'Urban Studio', 'description' => 'A 28 m² studio on floors 6–14, fully furnished with a queen bed, kitchenette, work desk, air conditioning, smart TV and fibre Wi-Fi. City views, and a unit cleaning package is available as an option. Made for professionals living solo.'],
                    'ja' => ['name' => 'アーバン スタジオ', 'description' => '6〜14階の28㎡スタジオ。クイーンベッド、ミニキッチン、ワークデスク、エアコン、スマートTV、光Wi-Fiを備えた家具付き。眺望は街並みで、お部屋の清掃は有料オプションです。おひとりで暮らすビジネスパーソンに。'],
                ],
                'size_sqm' => 28,
                'bedrooms' => 0,
                'bathrooms' => 1,
                'floor_range' => '6–14',
                'max_adults' => 2,
                'max_children' => 0,
                'bed_config' => [['type' => 'queen', 'count' => 1]],
                'view_type' => 'city',
                'monthly_price' => 13500000,
                'yearly_price' => 150000000,
                'amenities' => ['air_conditioning', 'wifi', 'kitchenette', 'workspace', 'smart_tv', 'smart_lock', 'water_heater'],
                'total_units' => 24,
                'images' => [
                    ['url' => $photo('1531835551805-16d864c8d311'), 'tags' => ['bedroom'], 'alt' => 'Area tidur Studio Urban'],
                    ['url' => $photo('1484154218962-a197022b5858'), 'tags' => ['kitchen'], 'alt' => 'Dapur kecil Studio Urban'],
                    ['url' => $photo('1552321554-5fefe8c9ef14'), 'tags' => ['bathroom'], 'alt' => 'Kamar mandi Studio Urban'],
                ],
            ],
            [
                'slug' => 'one-bedroom-executive',
                'name' => '1 Bedroom Executive',
                'description' => 'Unit 48 m² di lantai 10–20 dengan kamar tidur terpisah (tempat tidur king), ruang tamu, dapur lengkap, mesin cuci, dan balkon menghadap skyline. Berperabot lengkap dan siap huni. Favorit pasangan dan tamu dinas jangka panjang.',
                'translations' => [
                    'id' => ['name' => '1 Bedroom Executive', 'description' => 'Unit 48 m² di lantai 10–20 dengan kamar tidur terpisah (tempat tidur king), ruang tamu, dapur lengkap, mesin cuci, dan balkon menghadap skyline. Berperabot lengkap dan siap huni. Favorit pasangan dan tamu dinas jangka panjang.'],
                    'en' => ['name' => '1 Bedroom Executive', 'description' => 'A 48 m² unit on floors 10–20 with a separate bedroom (king bed), a living room, a full kitchen, a washer and a balcony facing the skyline. Fully furnished and move-in ready. A favourite with couples and long-term business stays.'],
                    'ja' => ['name' => '1ベッドルーム エグゼクティブ', 'description' => '10〜20階の48㎡。独立したベッドルーム（キングベッド）、リビング、フルキッチン、洗濯機、スカイラインを望むバルコニー付きの家具付きルーム。ご夫婦や長期出張の方に人気です。'],
                ],
                'size_sqm' => 48,
                'bedrooms' => 1,
                'bathrooms' => 1,
                'floor_range' => '10–20',
                'max_adults' => 2,
                'max_children' => 1,
                'bed_config' => [['type' => 'king', 'count' => 1]],
                'view_type' => 'skyline',
                'monthly_price' => 21000000,
                'yearly_price' => 235000000,
                'amenities' => ['air_conditioning', 'wifi', 'full_kitchen', 'washer_dryer', 'workspace', 'living_room', 'balcony', 'smart_tv', 'smart_lock'],
                'total_units' => 16,
                'images' => [
                    ['url' => $photo('1595526114035-0d45ed16cfbf'), 'tags' => ['bedroom'], 'alt' => 'Kamar tidur 1 Bedroom Executive'],
                    ['url' => $photo('1493809842364-78817add7ffb'), 'tags' => ['living_room', 'view'], 'alt' => 'Ruang tamu dengan jendela besar'],
                    ['url' => $photo('1556911220-bff31c812dba'), 'tags' => ['kitchen'], 'alt' => 'Dapur lengkap 1 Bedroom Executive'],
                    ['url' => $photo('1584622650111-993a426fbf0a'), 'tags' => ['bathroom'], 'alt' => 'Kamar mandi dengan shower kaca'],
                ],
            ],
            [
                'slug' => 'two-bedroom-family',
                'name' => '2 Bedroom Family',
                'description' => 'Unit 82 m² di lantai 15–26 dengan dua kamar tidur, dua kamar mandi (satu dengan bak mandi), ruang makan, mesin cuci, mesin pencuci piring, dan balkon menghadap taman. Berperabot lengkap — ruang yang cukup untuk keluarga yang pindah tugas ke Jakarta.',
                'translations' => [
                    'id' => ['name' => '2 Bedroom Family', 'description' => 'Unit 82 m² di lantai 15–26 dengan dua kamar tidur, dua kamar mandi (satu dengan bak mandi), ruang makan, mesin cuci, mesin pencuci piring, dan balkon menghadap taman. Berperabot lengkap — ruang yang cukup untuk keluarga yang pindah tugas ke Jakarta.'],
                    'en' => ['name' => '2 Bedroom Family', 'description' => 'An 82 m² unit on floors 15–26 with two bedrooms, two bathrooms (one with a bathtub), a dining area, a washer, a dishwasher and a balcony facing the park. Fully furnished — room enough for a family relocating to Jakarta.'],
                    'ja' => ['name' => '2ベッドルーム ファミリー', 'description' => '15〜26階の82㎡。2ベッドルーム、バスルーム2室（1室はバスタブ付き）、ダイニング、洗濯機、食洗機、公園側のバルコニー付きの家具付きルーム。ジャカルタへ赴任されるご家族にも十分な広さです。'],
                ],
                'size_sqm' => 82,
                'bedrooms' => 2,
                'bathrooms' => 2,
                'floor_range' => '15–26',
                'max_adults' => 4,
                'max_children' => 2,
                'bed_config' => [['type' => 'king', 'count' => 1], ['type' => 'twin', 'count' => 2]],
                'view_type' => 'park',
                'monthly_price' => 34000000,
                'yearly_price' => 380000000,
                'amenities' => ['air_conditioning', 'wifi', 'full_kitchen', 'dishwasher', 'washer_dryer', 'dining_area', 'living_room', 'balcony', 'bathtub', 'smart_tv', 'smart_lock'],
                'total_units' => 10,
                'images' => [
                    ['url' => $photo('1560185893-a55cbc8c57e8'), 'tags' => ['bedroom'], 'alt' => 'Kamar tidur utama 2 Bedroom Family'],
                    ['url' => $photo('1560185127-6ed189bf02f4'), 'tags' => ['living_room'], 'alt' => 'Ruang keluarga 2 Bedroom Family'],
                    ['url' => $photo('1560185007-cde436f6a4d0'), 'tags' => ['dining_area', 'kitchen'], 'alt' => 'Ruang makan dan dapur'],
                    ['url' => $photo('1522708323590-d24dbb6b0267'), 'tags' => ['living_room', 'kitchen'], 'alt' => 'Ruang tamu terbuka ke dapur'],
                ],
            ],
            [
                'slug' => 'three-bedroom-penthouse',
                'name' => '3 Bedroom Penthouse',
                'description' => 'Penthouse 145 m² di lantai 30–32 dengan lobi lift pribadi, tiga kamar tidur, tiga kamar mandi, ruang makan, ruang kerja, dan jendela setinggi plafon menghadap skyline SCBD. Berperabot lengkap.',
                'translations' => [
                    'id' => ['name' => '3 Bedroom Penthouse', 'description' => 'Penthouse 145 m² di lantai 30–32 dengan lobi lift pribadi, tiga kamar tidur, tiga kamar mandi, ruang makan, ruang kerja, dan jendela setinggi plafon menghadap skyline SCBD. Berperabot lengkap.'],
                    'en' => ['name' => '3 Bedroom Penthouse', 'description' => 'A 145 m² penthouse on floors 30–32 with a private lift lobby, three bedrooms, three bathrooms, a dining area, a workspace and floor-to-ceiling windows facing the SCBD skyline. Fully furnished.'],
                    'ja' => ['name' => '3ベッドルーム ペントハウス', 'description' => '30〜32階の145㎡ペントハウス。専用エレベーターホール、3ベッドルーム、バスルーム3室、ダイニング、ワークスペース、SCBDのスカイラインを望む天井までの大きな窓。家具付き。'],
                ],
                'size_sqm' => 145,
                'bedrooms' => 3,
                'bathrooms' => 3,
                'floor_range' => '30–32',
                'max_adults' => 6,
                'max_children' => 2,
                'bed_config' => [['type' => 'king', 'count' => 1], ['type' => 'queen', 'count' => 1], ['type' => 'twin', 'count' => 2]],
                'view_type' => 'skyline',
                'monthly_price' => 62000000,
                'yearly_price' => 690000000,
                'amenities' => ['air_conditioning', 'wifi', 'full_kitchen', 'dishwasher', 'washer_dryer', 'dining_area', 'living_room', 'workspace', 'bathtub', 'smart_tv', 'smart_lock', 'private_lift'],
                'total_units' => 3,
                'images' => [
                    ['url' => $photo('1578683010236-d716f9a3f461'), 'tags' => ['bedroom', 'view'], 'alt' => 'Kamar tidur penthouse dengan pemandangan kota'],
                    ['url' => $photo('1600607687939-ce8a6c25118c'), 'tags' => ['living_room'], 'alt' => 'Ruang keluarga penthouse'],
                    ['url' => $photo('1617806118233-18e1de247200'), 'tags' => ['dining_area'], 'alt' => 'Ruang makan penthouse'],
                    ['url' => $photo('1616594039964-ae9021a400a0'), 'tags' => ['bedroom'], 'alt' => 'Kamar tidur kedua penthouse'],
                ],
            ],
            [
                'slug' => 'office-suite-senopati',
                'name' => 'Kantor Suite Senopati',
                'category' => UnitType::CATEGORY_OFFICE,
                'listing_type' => UnitType::LISTING_RENT,
                'description' => 'Ruang kantor siap pakai di lantai podium dengan ruang rapat kecil dan area kerja terbuka, disewakan bulanan atau tahunan.',
                'translations' => [
                    'id' => ['name' => 'Kantor Suite Senopati', 'description' => 'Ruang kantor siap pakai di lantai podium dengan ruang rapat kecil dan area kerja terbuka, disewakan bulanan atau tahunan.'],
                    'en' => ['name' => 'Senopati Office Suite', 'description' => 'A ready-to-use office on the podium floors with a small meeting room and open work area, rented monthly or yearly.'],
                    'ja' => ['name' => 'セノパティ オフィススイート', 'description' => '低層階にある、小会議室とオープンワークエリア付きの即入居可能なオフィス。月単位または年単位で賃貸します。'],
                ],
                'size_sqm' => 60,
                'bedrooms' => 0,
                'bathrooms' => 1,
                'floor_range' => '2–5',
                'max_adults' => 10,
                'max_children' => 0,
                'bed_config' => [],
                'view_type' => 'city',
                'monthly_price' => 38000000,
                'yearly_price' => 420000000,
                'amenities' => ['air_conditioning', 'wifi', 'workspace', 'smart_lock', 'water_heater'],
                'total_units' => 6,
                'images' => [
                    ['url' => $photo('1497366811353-6870744d04b2'), 'tags' => ['workspace'], 'alt' => 'Area kerja terbuka kantor'],
                    ['url' => $photo('1524758631624-e2822e304c36'), 'tags' => ['workspace'], 'alt' => 'Ruang rapat kantor'],
                ],
            ],
            [
                'slug' => 'retail-kiosk-podium',
                'name' => 'Kios Retail Podium',
                'category' => UnitType::CATEGORY_COMMERCIAL,
                'listing_type' => UnitType::LISTING_BOTH,
                'description' => 'Kios di lantai dasar podium yang menghadap lobi utama, cocok untuk kafe, klinik, atau toko kebutuhan harian. Bisa disewa atau dibeli.',
                'translations' => [
                    'id' => ['name' => 'Kios Retail Podium', 'description' => 'Kios di lantai dasar podium yang menghadap lobi utama, cocok untuk kafe, klinik, atau toko kebutuhan harian. Bisa disewa atau dibeli.'],
                    'en' => ['name' => 'Podium Retail Kiosk', 'description' => 'A ground-floor podium kiosk facing the main lobby, suited to a café, clinic or convenience shop. Available to rent or to buy.'],
                    'ja' => ['name' => 'ポディウム リテールキオスク', 'description' => 'メインロビーに面した1階の店舗区画。カフェ、クリニック、日用品店に最適です。賃貸・購入のどちらも可能です。'],
                ],
                'size_sqm' => 35,
                'bedrooms' => 0,
                'bathrooms' => 1,
                'floor_range' => 'G',
                'max_adults' => 8,
                'max_children' => 0,
                'bed_config' => [],
                'view_type' => null,
                'monthly_price' => 45000000,
                'yearly_price' => 500000000,
                'sale_price' => 4800000000,
                'amenities' => ['air_conditioning', 'wifi', 'water_heater'],
                'total_units' => 4,
                'images' => [
                    ['url' => $photo('1441986300917-64674bd600d8'), 'tags' => ['storefront'], 'alt' => 'Kios retail di podium'],
                ],
            ],
            [
                'slug' => 'two-bedroom-residence-for-sale',
                'name' => '2 Bedroom Residence (Dijual)',
                'category' => UnitType::CATEGORY_RESIDENTIAL,
                'listing_type' => UnitType::LISTING_SALE,
                'description' => 'Unit dua kamar tidur di lantai tinggi yang dijual lengkap dengan furnitur, siap dihuni atau disewakan kembali.',
                'translations' => [
                    'id' => ['name' => '2 Bedroom Residence (Dijual)', 'description' => 'Unit dua kamar tidur di lantai tinggi yang dijual lengkap dengan furnitur, siap dihuni atau disewakan kembali.'],
                    'en' => ['name' => '2 Bedroom Residence (For Sale)', 'description' => 'A high-floor two-bedroom unit sold fully furnished, ready to live in or to let out again.'],
                    'ja' => ['name' => '2ベッドルーム レジデンス（販売）', 'description' => '高層階の2ベッドルーム。家具付きで販売しており、すぐにお住まいいただくことも、賃貸に出すことも可能です。'],
                ],
                'size_sqm' => 88,
                'bedrooms' => 2,
                'bathrooms' => 2,
                'floor_range' => '24–28',
                'max_adults' => 4,
                'max_children' => 2,
                'bed_config' => [['type' => 'king', 'count' => 1], ['type' => 'queen', 'count' => 1]],
                'view_type' => 'skyline',
                'monthly_price' => null,
                'yearly_price' => null,
                'sale_price' => 7900000000,
                'amenities' => ['air_conditioning', 'wifi', 'full_kitchen', 'washer_dryer', 'living_room', 'dining_area', 'balcony', 'smart_lock'],
                'total_units' => 0,
                'images' => [
                    ['url' => $photo('1600607687939-ce8a6c25118c'), 'tags' => ['living_room'], 'alt' => 'Ruang tamu unit dijual'],
                    ['url' => $photo('1560185007-cde436f6a4d0'), 'tags' => ['dining_area', 'kitchen'], 'alt' => 'Ruang makan dan dapur unit dijual'],
                ],
            ],
        ];
    }

    /**
     * Gives a unit type its numbered apartments, spread over its floors and the
     * four facings. Some are taken, with a date on which they free up, so the
     * unit page shows a believable mix of "ready now" and "free from".
     */
    private function seedUnits(Apartment $apartment, UnitType $unitType, int $count): void
    {
        $range = (string) $unitType->floor_range;
        $floors = preg_match('/^(\d+)\D+(\d+)$/u', $range, $match) === 1
            ? range((int) $match[1], (int) $match[2])
            : [ctype_digit($range) ? (int) $range : 0];
        $today = now()->startOfDay();

        for ($i = 0; $i < $count; $i++) {
            $floor = $floors[$i % count($floors)];
            $this->unitsOnFloor[$floor] = ($this->unitsOnFloor[$floor] ?? 0) + 1;
            $number = $floor === 0 ? sprintf('G%02d', $this->unitsOnFloor[$floor]) : (string) ($floor * 100 + $this->unitsOnFloor[$floor]);
            $taken = ($i * 7 + $unitType->id) % 10 >= 6;

            Unit::updateOrCreate(
                ['apartment_id' => $apartment->id, 'unit_number' => $number],
                [
                    'unit_type_id' => $unitType->id,
                    'floor' => $floor,
                    'facing' => Unit::FACINGS[($i + $floor) % count(Unit::FACINGS)],
                    'status' => $taken ? Unit::STATUS_OCCUPIED : Unit::STATUS_AVAILABLE,
                    'available_from' => $taken ? $today->copy()->addDays(20 + (($i * 11) % 70)) : null,
                ]
            );
        }
    }

    private function seedInventory(UnitType $unitType, int $totalUnits): void
    {
        $start = now()->startOfDay();

        for ($i = 0; $i < self::INVENTORY_DAYS; $i++) {
            $date = $start->copy()->addDays($i);

            // Deterministic pseudo-occupancy so the demo shows realistic
            // partial availability instead of every date being wide open.
            $booked = min(($i * 5 + $unitType->id * 3) % ($totalUnits + 1), $totalUnits - 1);

            // Match on the same value the date cast stores, so re-seeding
            // updates the existing night instead of colliding with it.
            UnitInventory::updateOrCreate(
                ['unit_type_id' => $unitType->id, 'stay_date' => $date],
                [
                    'total_units' => $totalUnits,
                    'booked_units' => $booked,
                ]
            );
        }
    }

    private function seedKnowledgeBase(Apartment $apartment): void
    {
        $photo = fn (string $id) => "https://images.unsplash.com/photo-{$id}?auto=format&fit=crop&w=800&q=80";

        $items = [
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_GENERAL,
                'title' => 'Tentang FTS Apartment AI',
                'body' => 'FTS Apartment AI adalah serviced apartment 32 lantai di Senopati, Jakarta Selatan, dengan 53 unit siap huni dari studio hingga penthouse 3 kamar. Setiap unit sudah berisi perabot, peralatan dapur, dan Wi-Fi fiber, sehingga penghuni cukup datang membawa koper; layanan kebersihan unit tersedia sebagai opsi.',
                'translations' => [
                    'en' => ['title' => 'About FTS Apartment AI', 'body' => 'FTS Apartment AI is a 32-storey serviced apartment tower in Senopati, South Jakarta, with 53 move-in-ready units from studios to three-bedroom penthouses. Every unit comes furnished, with kitchenware and fibre Wi-Fi, so residents only need to bring a suitcase; a unit cleaning service is available as an option.'],
                    'ja' => ['title' => 'FTS Apartment AI について', 'body' => 'FTS Apartment AI は南ジャカルタ・スノパティにある32階建てのサービスアパートメントで、スタジオから3ベッドルームのペントハウスまで53戸をご用意しています。全戸に家具、キッチン用品、光Wi-Fiが付いており、スーツケースひとつで入居いただけます（お部屋の清掃は有料オプション）。'],
                ],
                'tags' => ['overview', 'introduction', 'serviced apartment'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Proses pindah masuk dan kartu akses',
                'body' => 'Setelah pengajuan disetujui, deposit dibayar, dan perjanjian sewa ditandatangani, penyewa menerima unit pada tanggal mulai sewa di meja concierge lobi yang buka 24 jam. Setiap penghuni dewasa menunjukkan KTP atau paspor dan menerima kartu akses untuk lift dan lantai unitnya. Kehilangan kartu akses dikenakan biaya penggantian Rp100.000.',
                'translations' => [
                    'en' => ['title' => 'Move-in process and access cards', 'body' => 'Once the application is approved, the deposit is paid and the lease is signed, the tenant receives the unit on the lease start date at the 24-hour lobby concierge desk. Every adult resident shows an ID card or passport and receives an access card for the lifts and their unit floor. A lost access card costs IDR 100,000 to replace.'],
                    'ja' => ['title' => '入居の流れとアクセスカード', 'body' => '入居申込が承認され、敷金のお支払いと賃貸借契約の締結が済むと、契約開始日に24時間対応のロビーコンシェルジュデスクでお部屋をお引き渡しします。大人の入居者全員の身分証またはパスポートを確認し、エレベーターとお部屋のフロア用のアクセスカードをお渡しします。紛失時の再発行は10万ルピアです。'],
                ],
                'tags' => ['move-in', 'access card', 'kartu akses', 'id', 'pindah masuk'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Proses menyewa unit',
                'body' => 'Lihat unit di situs ini atau jadwalkan survei ke gedung, lalu ajukan sewa dengan tanggal masuk dan lama sewa. Tim leasing memeriksa pengajuan Anda dan menyetujuinya; setelah itu Anda membayar deposit, menandatangani perjanjian sewa, dan pindah masuk pada tanggal mulai sewa. Untuk kontrak perusahaan, tim leasing akan membuatkan penawaran khusus.',
                'translations' => [
                    'en' => ['title' => 'How renting a unit works', 'body' => 'View a unit on this site or schedule a viewing at the building, then apply with your move-in date and lease length. The leasing team reviews and approves your application; after that you pay the deposit, sign the lease and move in on the lease start date. For corporate leases, our leasing team prepares a tailored quote.'],
                    'ja' => ['title' => 'お部屋を借りる流れ', 'body' => 'このサイトでお部屋をご覧いただくか、建物の内見をご予約のうえ、入居日と契約期間を指定してお申し込みください。リーシングチームが審査・承認したのち、敷金のお支払い、賃貸借契約の締結を経て、契約開始日に入居いただきます。法人契約はリーシングチームが個別にお見積もりいたします。'],
                ],
                'tags' => ['rent', 'apply', 'application', 'viewing', 'survei', 'sewa', 'corporate lease'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Sewa bulanan dan tahunan, deposit, dan pembayaran',
                'body' => 'Unit disewa bulanan (3 sampai 11 bulan) atau tahunan (1 sampai 3 tahun) dengan tarif sewa tersendiri; sewa tahunan umumnya lebih murah per bulan daripada sewa bulanan. Sewa dibayar di muka sesuai kesepakatan dengan tim leasing. Penyewa membayar deposit keamanan sebesar 2 bulan sewa yang dikembalikan saat masa sewa berakhir, setelah unit diperiksa dan tidak ada tagihan tertunggak. Pengajuan sewa di sistem ini belum menjadi kontrak: tim leasing akan menghubungi Anda untuk surat perjanjian sewa dan serah terima unit.',
                'translations' => [
                    'en' => ['title' => 'Monthly and yearly rentals, deposit and payment', 'body' => 'Units are rented by the month (3 to 11 months) or by the year (1 to 3 years) at their own rent; a yearly rental usually costs less per month than a monthly one. Rent is paid in advance as arranged with the leasing team. Tenants pay a security deposit of 2 months of rent, refunded when the lease ends after the unit is inspected and no bills are outstanding. An application made on this site is not a lease yet: the leasing team will contact you for the tenancy agreement and the unit handover.'],
                    'ja' => ['title' => '月契約・年契約、敷金とお支払い', 'body' => 'お部屋ごとの賃料で、月契約（3〜11か月）または年契約（1〜3年）をご利用いただけます。年契約は月契約より月あたりの賃料が低めです。賃料は前払いで、お支払い方法はリーシングチームとご相談のうえ決定します。敷金は賃料2か月分で、契約終了時にお部屋の確認と未払いがないことを確認のうえ返還します。このサイトからの入居申込はまだ契約ではなく、賃貸契約書の締結とお部屋のお引き渡しについてリーシングチームがご連絡します。'],
                ],
                'tags' => ['monthly', 'yearly', 'annual', 'lease', 'deposit', 'bulanan', 'tahunan', 'sewa', 'kontrak', 'deposit keamanan'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Listrik, air, dan biaya layanan',
                'body' => 'Sewa sudah termasuk service charge gedung, air, dan Wi-Fi. Listrik tidak termasuk: ditagihkan setiap bulan sesuai pemakaian meteran dengan tarif Rp1.700 per kWh. Parkir mobil pertama gratis, slot tambahan Rp750.000 per bulan. Kebersihan unit adalah layanan opsional (lihat Laundry dan layanan kebersihan).',
                'translations' => [
                    'en' => ['title' => 'Electricity, water and service charges', 'body' => 'Rent includes the building service charge, water and Wi-Fi. Electricity is not included: it is billed monthly by meter reading at IDR 1,700 per kWh. The first car park space is free, extra spaces are IDR 750,000 a month. Unit cleaning is an optional service (see Laundry and cleaning service).'],
                    'ja' => ['title' => '電気・水道・サービス料', 'body' => '賃料には建物のサービスチャージ、水道、Wi-Fiが含まれます。電気代は含まれず、メーターの使用量に基づき1kWhあたり1,700ルピアで毎月請求します。駐車場は1台目が無料、追加は月75万ルピアです。お部屋の清掃は有料オプションです（「ランドリーと清掃サービス」をご覧ください）。'],
                ],
                'tags' => ['electricity', 'listrik', 'utilities', 'water', 'service charge'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Aturan gedung',
                'body' => 'Jam tenang pukul 22:00–07:00. Dilarang merokok di dalam unit dan koridor; area merokok tersedia di lantai dasar. Tamu yang berkunjung wajib didaftarkan di concierge dan tidak boleh menginap tanpa didaftarkan sebagai penghuni.',
                'translations' => [
                    'en' => ['title' => 'House rules', 'body' => 'Quiet hours are 10:00 PM to 7:00 AM. No smoking inside units or corridors; a smoking area is on the ground floor. Visitors must be registered at the concierge and may not stay overnight unless registered as residents.'],
                    'ja' => ['title' => '館内ルール', 'body' => '22:00〜7:00は静粛時間です。お部屋と廊下は禁煙で、喫煙所は1階にございます。来訪者はコンシェルジュでの登録が必要で、入居者として登録されていない方の宿泊はできません。'],
                ],
                'tags' => ['house rules', 'quiet hours', 'smoking', 'visitors', 'aturan'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Pembatalan dan pengembalian deposit',
                'body' => 'Pengajuan sewa dapat dibatalkan tanpa biaya sebelum perjanjian sewa ditandatangani. Setelah deposit dibayar, deposit dikembalikan penuh bila pengajuan dibatalkan paling lambat 14 hari sebelum tanggal mulai sewa; setelahnya, ketentuan dalam perjanjian sewa berlaku.',
                'translations' => [
                    'en' => ['title' => 'Cancellation and deposit refunds', 'body' => 'An application can be cancelled free of charge before the lease is signed. Once the deposit is paid, it is refunded in full if the application is cancelled at least 14 days before the lease start date; after that, the terms of the lease apply.'],
                    'ja' => ['title' => 'キャンセルと敷金の返金', 'body' => '入居申込は賃貸借契約の締結前であれば無料でキャンセルできます。敷金のお支払い後は、契約開始日の14日前までにキャンセルされた場合は全額返金します。それ以降は賃貸借契約の定めに従います。'],
                ],
                'tags' => ['cancellation', 'refund', 'pembatalan'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FACILITIES,
                'title' => 'Kolam renang infinity rooftop',
                'image_url' => $photo('1576013551627-0cc20b96c2a7'),
                'body' => 'Kolam renang infinity di rooftop lantai 32 buka setiap hari pukul 06:00–21:00, khusus penghuni. Handuk tersedia di area kolam. Anak di bawah 12 tahun wajib didampingi orang dewasa.',
                'translations' => [
                    'en' => ['title' => 'Rooftop infinity pool', 'body' => 'The infinity pool on the 32nd-floor rooftop is open daily from 6:00 AM to 9:00 PM for residents only. Towels are provided at the pool. Children under 12 must be accompanied by an adult.'],
                    'ja' => ['title' => 'ルーフトップ インフィニティプール', 'body' => '32階ルーフトップのインフィニティプールは毎日6:00〜21:00、入居者専用です。タオルはプールにご用意しています。12歳未満のお子様は大人の同伴が必要です。'],
                ],
                'tags' => ['pool', 'swimming pool', 'rooftop', 'hours'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FACILITIES,
                'title' => 'Gym 24 jam',
                'image_url' => $photo('1540497077202-7c8a3999166f'),
                'body' => 'Gym di lantai 5 buka 24 jam dengan kartu akses penghuni, lengkap dengan treadmill, sepeda statis, dan area beban bebas. Kelas yoga gratis setiap Sabtu pukul 07:30.',
                'translations' => [
                    'en' => ['title' => '24-hour gym', 'body' => 'The 5th-floor gym is open 24 hours with your resident access card, with treadmills, bikes and a free-weights area. A free yoga class runs every Saturday at 7:30 AM.'],
                    'ja' => ['title' => '24時間ジム', 'body' => '5階のジムは入居者用アクセスカードで24時間ご利用いただけます。トレッドミル、バイク、フリーウェイトを完備。毎週土曜7:30に無料ヨガクラスがあります。'],
                ],
                'tags' => ['gym', 'fitness', 'yoga'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FACILITIES,
                'title' => 'Co-working lounge',
                'image_url' => $photo('1524758631624-e2822e304c36'),
                'body' => 'Co-working lounge di lantai 5 buka 07:00–23:00 dengan Wi-Fi 1 Gbps, meja kerja, dan dua ruang meeting kecil yang bisa dipesan gratis lewat concierge, maksimal 2 jam per hari.',
                'translations' => [
                    'en' => ['title' => 'Co-working lounge', 'body' => 'The 5th-floor co-working lounge is open 7:00 AM to 11:00 PM with 1 Gbps Wi-Fi, work desks and two small meeting rooms you can book free of charge through the concierge, up to 2 hours a day.'],
                    'ja' => ['title' => 'コワーキングラウンジ', 'body' => '5階のコワーキングラウンジは7:00〜23:00、1Gbps Wi-Fiとワークデスクを完備。小さな会議室2室をコンシェルジュ経由で1日2時間まで無料でご予約いただけます。'],
                ],
                'tags' => ['co-working', 'coworking', 'workspace', 'meeting room', 'wifi'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FACILITIES,
                'title' => 'Laundry dan layanan kebersihan',
                'image_url' => $photo('1626806787461-102c1bfaaea1'),
                'body' => 'Ruang laundry koin ada di lantai 3, dan layanan laundry kiloan bisa dipesan lewat concierge dengan selesai dalam 24 jam. Kebersihan unit adalah layanan opsional: Rp350.000 per kunjungan (sekitar 2 jam), dipesan lewat concierge; penggantian sprei dan handuk tidak termasuk. Area bersama dibersihkan setiap hari oleh pengelola gedung.',
                'translations' => [
                    'en' => ['title' => 'Laundry and cleaning service', 'body' => 'A coin laundry room is on the 3rd floor, and a 24-hour wash-and-fold service can be booked through the concierge. Unit cleaning is an optional service: IDR 350,000 per visit (about 2 hours), booked through the concierge; changing linen and towels is not included. Common areas are cleaned daily by building management.'],
                    'ja' => ['title' => 'ランドリーと清掃サービス', 'body' => '3階にコインランドリーがあり、24時間仕上げのランドリーサービスもコンシェルジュで承ります。お部屋の清掃は有料オプションで、1回（約2時間）35万ルピア、コンシェルジュでお申し込みください。リネン・タオルの交換は含まれません。共用部は建物管理会社が毎日清掃します。'],
                ],
                'tags' => ['laundry', 'cleaning', 'kebersihan', 'optional service'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FACILITIES,
                'title' => 'Parkir dan keamanan',
                'image_url' => $photo('1590674899484-d5640e854abe'),
                'body' => 'Setiap unit mendapat satu slot parkir mobil gratis di basement; slot tambahan Rp750.000 per bulan. Gedung dijaga keamanan 24 jam dengan CCTV di semua area bersama, dan lift hanya bisa dipakai dengan kartu akses.',
                'translations' => [
                    'en' => ['title' => 'Parking and security', 'body' => 'Each unit includes one free basement car park space; extra spaces are IDR 750,000 a month. The building has 24-hour security with CCTV in all shared areas, and the lifts only work with an access card.'],
                    'ja' => ['title' => '駐車場とセキュリティ', 'body' => '各戸に地下駐車場1台分が無料で付きます。追加は月75万ルピアです。建物は24時間警備、共用部はすべて防犯カメラ付きで、エレベーターはアクセスカードでのみご利用いただけます。'],
                ],
                'tags' => ['parking', 'car park', 'security', 'cctv'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_DINING,
                'title' => 'Kafe lobi dan minimarket 24 jam',
                'image_url' => $photo('1517248135467-4c7edcad34c4'),
                'body' => 'Kafe di lobi buka 06:30–22:00 dengan menu sarapan, kopi, dan makan siang; sarapan tidak termasuk tarif dan bisa dipesan per hari. Minimarket 24 jam ada di lantai dasar, dan kurir pesan-antar diterima di meja concierge.',
                'translations' => [
                    'en' => ['title' => 'Lobby café and 24-hour minimarket', 'body' => 'The lobby café is open 6:30 AM to 10:00 PM for breakfast, coffee and lunch; breakfast is not included in the rate and can be ordered by the day. A 24-hour minimarket is on the ground floor, and food delivery couriers are received at the concierge desk.'],
                    'ja' => ['title' => 'ロビーカフェと24時間ミニマーケット', 'body' => 'ロビーのカフェは6:30〜22:00、朝食・コーヒー・ランチをご用意しています。朝食は料金に含まれず、日ごとにご注文いただけます。1階に24時間営業のミニマーケットがあり、フードデリバリーはコンシェルジュデスクでお受け取りいただけます。'],
                ],
                'tags' => ['cafe', 'breakfast', 'minimarket', 'delivery', 'dining'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_TRANSPORT,
                'title' => 'MRT dan transportasi',
                'image_url' => $photo('1519501025264-65ba15a82390'),
                'body' => 'Stasiun MRT ASEAN berjarak 8 menit jalan kaki, dan dari sana hanya 2 stasiun ke Senayan atau 5 stasiun ke Bundaran HI. SCBD sekitar 5 menit berkendara. Taksi dan ojek online bisa menjemput di drop-off lobi.',
                'translations' => [
                    'en' => ['title' => 'MRT and getting around', 'body' => 'ASEAN MRT station is an 8-minute walk, and from there it is 2 stops to Senayan or 5 stops to Bundaran HI. SCBD is about a 5-minute drive. Taxis and ride-hailing pick up at the lobby drop-off.'],
                    'ja' => ['title' => 'MRTとアクセス', 'body' => 'MRTアセアン駅まで徒歩8分。そこからスナヤンまで2駅、ブンダランHIまで5駅です。SCBDまでは車で約5分。タクシーや配車サービスはロビーの車寄せからご乗車いただけます。'],
                ],
                'tags' => ['mrt', 'train', 'transit', 'scbd', 'taxi'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_TRANSPORT,
                'title' => 'Antar-jemput bandara',
                'image_url' => $photo('1436491865332-7a61a109cc05'),
                'body' => 'Antar-jemput dari Bandara Soekarno-Hatta tersedia dengan biaya Rp450.000 sekali jalan (sekitar 45–75 menit tergantung lalu lintas), atau dari Halim Perdanakusuma Rp300.000. Pesan minimal 24 jam sebelumnya dengan nomor penerbangan.',
                'translations' => [
                    'en' => ['title' => 'Airport transfer', 'body' => 'Transfers from Soekarno-Hatta Airport cost IDR 450,000 one way (about 45–75 minutes depending on traffic), or IDR 300,000 from Halim Perdanakusuma. Please book at least 24 hours ahead with your flight number.'],
                    'ja' => ['title' => '空港送迎', 'body' => 'スカルノ・ハッタ空港からの送迎は片道45万ルピア（交通状況により約45〜75分）、ハリム・ペルダナクスマ空港からは30万ルピアです。24時間前までにフライト番号とあわせてご予約ください。'],
                ],
                'tags' => ['airport transfer', 'soekarno-hatta', 'halim', 'bandara'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Apakah boleh membawa hewan peliharaan?',
                'body' => 'Kucing dan anjing kecil (maksimal 10 kg) diperbolehkan di unit 1 Bedroom Executive dan 2 Bedroom Family dengan deposit kebersihan Rp1.500.000 yang dikembalikan saat masa sewa berakhir. Hewan peliharaan tidak boleh masuk area kolam, gym, dan co-working.',
                'translations' => [
                    'en' => ['title' => 'Can I bring a pet?', 'body' => 'Cats and small dogs (up to 10 kg) are allowed in 1 Bedroom Executive and 2 Bedroom Family units with a refundable cleaning deposit of IDR 1,500,000 returned when the lease ends. Pets are not allowed in the pool, gym or co-working areas.'],
                    'ja' => ['title' => 'ペットは同伴できますか？', 'body' => '1ベッドルーム エグゼクティブと2ベッドルーム ファミリーに限り、猫と小型犬（10kgまで）の同伴が可能です。退去時に返金されるクリーニングデポジット150万ルピアを頂戴します。プール・ジム・コワーキングへの立ち入りはできません。'],
                ],
                'tags' => ['pets', 'cat', 'dog', 'hewan', 'faq'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Apakah ada deposit?',
                'body' => 'Ya. Penyewa membayar deposit keamanan sebesar 2 bulan sewa (untuk sewa bulanan maupun tahunan) setelah pengajuan disetujui, dan deposit dikembalikan paling lambat 7 hari kerja setelah masa sewa berakhir, dikurangi tagihan listrik dan kerusakan jika ada. Jumlahnya tertera pada rincian sewa unit.',
                'translations' => [
                    'en' => ['title' => 'Is there a deposit?', 'body' => 'Yes. Tenants pay a security deposit of 2 months of rent (for monthly and yearly leases alike) after the application is approved, refunded within 7 working days after the lease ends, minus the electricity bill and any damage. The amount is shown in the unit\'s rent details.'],
                    'ja' => ['title' => 'デポジットは必要ですか？', 'body' => 'はい。入居申込の承認後に、月契約・年契約とも賃料2か月分の敷金をお預かりし、契約終了後7営業日以内に電気代と損傷があればその分を差し引いて返金します。金額はお部屋の賃料詳細に表示されます。'],
                ],
                'tags' => ['deposit', 'payment', 'faq'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Apakah ada antar-jemput bandara?',
                'body' => 'Ya. Antar-jemput dari Bandara Soekarno-Hatta Rp450.000 sekali jalan, atau dari Halim Perdanakusuma Rp300.000. Pesan minimal 24 jam sebelumnya lewat concierge dengan nomor penerbangan.',
                'translations' => [
                    'en' => ['title' => 'Is there an airport transfer?', 'body' => 'Yes. Transfers cost IDR 450,000 one way from Soekarno-Hatta Airport or IDR 300,000 from Halim Perdanakusuma. Book at least 24 hours ahead through the concierge with your flight number.'],
                    'ja' => ['title' => '空港送迎はありますか？', 'body' => 'はい。スカルノ・ハッタ空港からは片道45万ルピア、ハリム・ペルダナクスマ空港からは30万ルピアです。24時間前までに、フライト番号とあわせてコンシェルジュでご予約ください。'],
                ],
                'tags' => ['airport transfer', 'soekarno-hatta', 'halim', 'bandara', 'antar-jemput', 'faq'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Dokumen persyaratan sewa',
                'body' => 'Penyewa perorangan WNI: KTP, bukti penghasilan (slip gaji atau surat keterangan kerja), dan NPWP bila ada. Penyewa WNA: paspor, KITAS/KITAP, dan surat jaminan dari perusahaan atau sponsor. Penyewa perusahaan: NIB atau akta perusahaan, NPWP, dan surat kuasa perwakilan. Setiap penghuni dewasa wajib menunjukkan identitas saat serah terima. Dokumen tidak diunggah di situs ini; tim leasing akan meminta salinannya lewat WhatsApp atau email setelah pengajuan masuk.',
                'translations' => [
                    'en' => ['title' => 'Documents required to rent', 'body' => 'Individual tenants (Indonesian citizens): ID card (KTP), proof of income (payslip or employer letter) and tax number (NPWP) if available. Foreign tenants: passport, KITAS/KITAP residence permit and a guarantee letter from a company or sponsor. Company tenants: business registration (NIB) or deed of establishment, tax number and an authorisation letter for the representative. Every adult resident shows ID at handover. Documents are not uploaded on this site; the leasing team asks for copies by WhatsApp or email once your application is in.'],
                    'ja' => ['title' => 'お申し込みに必要な書類', 'body' => '個人（インドネシア国籍）：身分証（KTP）、収入証明（給与明細または在職証明）、お持ちであれば納税者番号（NPWP）。外国籍の方：パスポート、滞在許可証（KITAS/KITAP）、会社または保証人の保証書。法人：事業登録（NIB）または設立証書、納税者番号、代表者の委任状。大人の入居者全員がお引き渡し時に身分証を提示します。書類はこのサイトでは受け付けず、お申し込み後にリーシングチームがWhatsAppまたはメールでコピーをお願いします。'],
                ],
                'tags' => ['documents', 'dokumen', 'ktp', 'passport', 'kitas', 'npwp', 'requirements', 'persyaratan'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Biaya awal sewa',
                'body' => 'Biaya awal dibayar setelah pengajuan disetujui dan sebelum perjanjian ditandatangani: deposit keamanan 2 bulan sewa ditambah sewa periode pertama dibayar di muka (sewa bulanan: 1 bulan; sewa tahunan: sesuai kesepakatan dengan tim leasing, umumnya 6 atau 12 bulan). Tidak ada biaya admin pengajuan. Contoh Studio Urban sewa bulanan: deposit Rp27.000.000 + sewa bulan pertama Rp13.500.000 = Rp40.500.000. Tarif final dikonfirmasi tim apartemen.',
                'translations' => [
                    'en' => ['title' => 'Move-in costs', 'body' => 'Move-in costs are paid after the application is approved and before the lease is signed: a security deposit of 2 months of rent plus the first rent period in advance (monthly lease: 1 month; yearly lease: as agreed with the leasing team, usually 6 or 12 months). There is no application fee. Example, Urban Studio on a monthly lease: deposit IDR 27,000,000 + first month IDR 13,500,000 = IDR 40,500,000. The apartment team confirms the final amounts.'],
                    'ja' => ['title' => '入居時にかかる費用', 'body' => '入居時費用は、申込の承認後、賃貸借契約の締結前にお支払いいただきます。内訳は賃料2か月分の敷金と、最初の賃料期間分の前払いです（月契約は1か月分、年契約はリーシングチームとの合意により通常6か月または12か月分）。申込手数料はありません。例：アーバン スタジオの月契約は、敷金2,700万ルピア＋初月賃料1,350万ルピア＝4,050万ルピア。最終金額はアパートメントチームが確認します。'],
                ],
                'tags' => ['move-in costs', 'biaya awal', 'deposit', 'first month', 'down payment', 'payment'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_POLICIES,
                'title' => 'Perpanjangan dan pemutusan sewa lebih awal',
                'body' => 'Untuk memperpanjang, beri tahu tim leasing paling lambat 60 hari sebelum masa sewa berakhir; tarif perpanjangan mengikuti tarif sewa yang berlaku saat itu. Untuk berhenti lebih awal, penyewa memberi pemberitahuan tertulis minimal 30 hari sebelumnya. Sewa yang sudah dibayar untuk sisa masa sewa tidak dikembalikan, kecuali ada penyewa pengganti yang disetujui tim leasing. Deposit dikembalikan sesuai ketentuan deposit setelah unit diperiksa.',
                'translations' => [
                    'en' => ['title' => 'Renewals and early termination', 'body' => 'To renew, tell the leasing team at least 60 days before the lease ends; the renewal rent follows the rate in force at that time. To leave early, the tenant gives at least 30 days\' written notice. Rent already paid for the rest of the term is not refunded unless the leasing team approves a replacement tenant. The deposit is returned under the deposit terms after the unit is inspected.'],
                    'ja' => ['title' => '契約更新と中途解約', 'body' => '更新をご希望の場合は、契約終了の60日前までにリーシングチームへご連絡ください。更新後の賃料はその時点の賃料に準じます。中途解約は30日前までの書面通知が必要です。残りの期間分として支払済みの賃料は、リーシングチームが承認した後任の入居者がいる場合を除き返金されません。敷金はお部屋の確認後、敷金に関する規定に従って返還します。'],
                ],
                'tags' => ['renewal', 'extend', 'perpanjang', 'terminate', 'early termination', 'berhenti', 'pemutusan'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Dokumen apa yang harus disiapkan untuk menyewa?',
                'body' => 'WNI: KTP dan bukti penghasilan. WNA: paspor, KITAS/KITAP, dan surat jaminan perusahaan atau sponsor. Perusahaan: NIB atau akta, NPWP, dan surat kuasa. Dokumen dikirim ke tim leasing lewat WhatsApp atau email setelah pengajuan masuk, bukan diunggah di situs.',
                'translations' => [
                    'en' => ['title' => 'Which documents do I need to rent?', 'body' => 'Indonesian citizens: ID card (KTP) and proof of income. Foreigners: passport, KITAS/KITAP and a company or sponsor guarantee letter. Companies: NIB or deed, tax number and an authorisation letter. Send them to the leasing team by WhatsApp or email once your application is in; they are not uploaded on the site.'],
                    'ja' => ['title' => '申し込みにはどんな書類が必要ですか？', 'body' => 'インドネシア国籍の方：KTPと収入証明。外国籍の方：パスポート、KITAS/KITAP、会社または保証人の保証書。法人：NIBまたは設立証書、納税者番号、委任状。お申し込み後にリーシングチームへWhatsAppまたはメールでお送りください（サイトへのアップロードは不要です）。'],
                ],
                'tags' => ['documents', 'dokumen', 'requirements', 'faq'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Berapa biaya awal untuk menyewa?',
                'body' => 'Deposit 2 bulan sewa ditambah sewa periode pertama di muka, tanpa biaya admin pengajuan. Contoh Studio Urban bulanan: Rp27.000.000 deposit + Rp13.500.000 sewa bulan pertama = Rp40.500.000. Listrik ditagih terpisah setiap bulan.',
                'translations' => [
                    'en' => ['title' => 'How much do I pay to move in?', 'body' => 'A deposit of 2 months of rent plus the first rent period in advance, with no application fee. Example, Urban Studio monthly: IDR 27,000,000 deposit + IDR 13,500,000 first month = IDR 40,500,000. Electricity is billed separately each month.'],
                    'ja' => ['title' => '入居時にいくら必要ですか？', 'body' => '賃料2か月分の敷金と、最初の賃料期間分の前払いです。申込手数料はありません。例：アーバン スタジオの月契約は、敷金2,700万ルピア＋初月賃料1,350万ルピア＝4,050万ルピア。電気代は毎月別途請求します。'],
                ],
                'tags' => ['move-in costs', 'biaya awal', 'faq', 'deposit'],
            ],
            [
                'category' => ApartmentKnowledgeItem::CATEGORY_FAQ,
                'title' => 'Bagaimana jika ingin memperpanjang atau berhenti lebih awal?',
                'body' => 'Perpanjangan: beri tahu tim leasing 60 hari sebelum sewa berakhir. Berhenti lebih awal: pemberitahuan tertulis minimal 30 hari; sewa yang sudah dibayar tidak dikembalikan kecuali ada penyewa pengganti yang disetujui.',
                'translations' => [
                    'en' => ['title' => 'Can I renew or leave early?', 'body' => 'Renewal: tell the leasing team 60 days before the lease ends. Leaving early: at least 30 days\' written notice; rent already paid is not refunded unless an approved replacement tenant takes over.'],
                    'ja' => ['title' => '更新や中途解約はできますか？', 'body' => '更新：契約終了の60日前までにリーシングチームへご連絡ください。中途解約：30日前までの書面通知が必要です。支払済みの賃料は、承認された後任の入居者がいる場合を除き返金されません。'],
                ],
                'tags' => ['renewal', 'terminate', 'perpanjang', 'faq'],
            ],
        ];

        // Entries are matched by title, so a renamed or dropped entry (e.g. one
        // from the hotel era) would otherwise stay live next to the new ones and
        // contradict them on the info page and in the concierge's answers.
        $apartment->knowledgeItems()
            ->whereNotIn('title', array_column($items, 'title'))
            ->update(['is_active' => false]);

        $sort = 0;
        foreach ($items as $item) {
            $apartment->knowledgeItems()->updateOrCreate(
                ['title' => $item['title']],
                [
                    'category' => $item['category'],
                    'body' => $item['body'],
                    'translations' => $item['translations'],
                    'tags' => $item['tags'],
                    'image_url' => $item['image_url'] ?? null,
                    'is_active' => true,
                    'sort_order' => $sort++,
                ]
            );
        }
    }
}
