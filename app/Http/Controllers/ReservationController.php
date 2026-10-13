<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\UnitInventory;
use App\Models\UnitType;
use App\Services\Reservation\ReservationHandover;
use App\Services\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    private const SUPPORTED_LOCALES = ['id', 'en', 'ja'];

    /** How far ahead the date picker looks for open nights. */
    private const AVAILABILITY_DAYS = 366;

    private const PHONE_PATTERN = '/^\+?[0-9\s\-().]{6,20}$/';

    /**
     * Guest-facing validation messages, keyed by locale.
     *
     * @var array<string, array<string, string>>
     */
    public const MESSAGES = [
        'en' => [
            'invalid' => 'Please check this field.',
            'check_in_past' => 'Check-in cannot be in the past.',
            'check_out_after' => 'Check-out must be after check-in.',
            'too_long' => 'Online requests cover up to :max nights. For a longer lease, please contact our leasing team.',
            'unit_unknown' => 'Please choose a unit type.',
            'capacity' => 'This unit does not fit that many residents. Choose a larger unit or add another unit.',
            'unavailable' => 'This unit type is not available for those dates.',
            'contact_email' => 'Please enter a valid email address.',
            'contact_phone' => 'Please enter a valid phone or WhatsApp number.',
            'chat_received' => 'Your stay request :reference is in. The apartment team will confirm it shortly.',
            'rental_not_offered' => 'This unit is not offered for this kind of rental. Choose another unit or rental type.',
            'lease_term_monthly' => 'Monthly rentals run from :min to :max months. For a year or longer, choose a yearly rental.',
            'lease_term_yearly' => 'Yearly rentals cover whole years, from 1 to :max years.',
        ],
        'id' => [
            'invalid' => 'Mohon periksa kolom ini.',
            'check_in_past' => 'Tanggal check-in tidak boleh di masa lalu.',
            'check_out_after' => 'Tanggal check-out harus setelah check-in.',
            'too_long' => 'Permintaan online maksimal :max malam. Untuk sewa lebih lama, hubungi tim leasing kami.',
            'unit_unknown' => 'Silakan pilih tipe unit.',
            'capacity' => 'Unit ini tidak cukup untuk jumlah penghuni tersebut. Pilih unit yang lebih besar atau tambah unit.',
            'unavailable' => 'Tipe unit ini tidak tersedia pada tanggal tersebut.',
            'contact_email' => 'Masukkan alamat email yang valid.',
            'contact_phone' => 'Masukkan nomor telepon atau WhatsApp yang valid.',
            'chat_received' => 'Permintaan sewa :reference sudah masuk. Tim apartemen akan segera mengonfirmasinya.',
            'rental_not_offered' => 'Unit ini tidak disewakan dengan jenis sewa tersebut. Pilih unit atau jenis sewa lain.',
            'lease_term_monthly' => 'Sewa bulanan berlaku :min sampai :max bulan. Untuk satu tahun atau lebih, pilih sewa tahunan.',
            'lease_term_yearly' => 'Sewa tahunan dihitung per tahun penuh, mulai 1 sampai :max tahun.',
        ],
        'ja' => [
            'invalid' => 'この項目をご確認ください。',
            'check_in_past' => '入居日は過去にできません。',
            'check_out_after' => '退去日は入居日より後にしてください。',
            'too_long' => 'オンラインでのご依頼は最大:max泊までです。それ以上の長期契約はリーシングチームにご相談ください。',
            'unit_unknown' => 'お部屋タイプを選択してください。',
            'capacity' => 'このお部屋では人数に対応できません。広いお部屋を選ぶか、戸数を増やしてください。',
            'unavailable' => 'この日程では、このお部屋タイプはご利用いただけません。',
            'contact_email' => '有効なメールアドレスを入力してください。',
            'contact_phone' => '有効な電話番号またはWhatsApp番号を入力してください。',
            'chat_received' => '滞在リクエスト :reference を受け付けました。まもなくスタッフが確認いたします。',
            'rental_not_offered' => 'このお部屋はその契約形態ではご利用いただけません。他のお部屋または契約形態をお選びください。',
            'lease_term_monthly' => '月契約は:min〜:maxか月です。1年以上は年契約をお選びください。',
            'lease_term_yearly' => '年契約は1〜:max年の整数年でご利用いただけます。',
        ],
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationHandover $handover,
    ) {}

    /**
     * The nights on which at least one unit type still has a free unit, so
     * the date picker can grey out the days that are fully booked.
     */
    public function availability(string $apartmentSlug): JsonResponse
    {
        $apartment = $this->publishedApartment($apartmentSlug);
        $today = CarbonImmutable::now($apartment->timezone)->startOfDay();
        $until = $today->addDays(self::AVAILABILITY_DAYS);

        $dates = UnitInventory::whereIn('unit_type_id', $apartment->unitTypes()->where('is_active', true)->select('id'))
            ->whereRaw('total_units > booked_units')
            ->whereDate('stay_date', '>=', $today->toDateString())
            ->whereDate('stay_date', '<=', $until->toDateString())
            ->distinct()
            ->orderBy('stay_date')
            ->get(['stay_date'])
            ->map(fn (UnitInventory $night) => $night->stay_date->toDateString())
            ->unique()
            ->values();

        return response()->json([
            'today' => $today->toDateString(),
            'until' => $until->toDateString(),
            'dates' => $dates,
        ]);
    }

    public function quote(Request $request, string $apartmentSlug): JsonResponse
    {
        $apartment = $this->publishedApartment($apartmentSlug);
        $locale = $this->locale($request, $apartment);

        $data = $request->validate($this->stayRules($apartment, $request), $this->validationMessages($locale));
        $stay = $this->resolveStay($apartment, $data, $locale);

        $quote = $this->quoteStay($stay);

        if (! $quote) {
            return $this->unavailable($apartment, $stay['unitType'], $data, $locale);
        }

        return response()->json([
            'available' => true,
            'rental_type' => $stay['rentalType'],
            'lease_months' => $stay['leaseMonths'],
            'check_in' => $stay['checkIn']->toDateString(),
            'check_out' => $stay['checkOut']->toDateString(),
            'rate' => $quote['rate'] ?? null,
            'rate_period' => $quote['rate_period'] ?? null,
            'monthly_rate' => $quote['monthly_rate'] ?? null,
            'deposit_months' => $quote['deposit_months'] ?? null,
            'deposit_total' => $quote['deposit_total'] ?? null,
            'nights' => $quote['nights'],
            'unit_total' => $quote['unit_total'],
            'extra_bed_total' => $quote['extra_bed_total'],
            'subtotal' => $quote['subtotal'],
            'discount_percent' => $quote['discount_percent'],
            'discount_total' => $quote['discount_total'],
            'grand_total' => $quote['grand_total'],
            'currency' => $apartment->currency,
        ]);
    }

    public function store(Request $request, string $apartmentSlug): JsonResponse
    {
        $apartment = $this->publishedApartment($apartmentSlug);
        $locale = $this->locale($request, $apartment);
        $messages = self::MESSAGES[$locale];

        $data = $request->validate([
            ...$this->stayRules($apartment, $request),
            'guest_name' => ['required', 'string', 'max:100'],
            'contact_type' => ['required', Rule::in(['whatsapp', 'phone', 'email'])],
            'contact_value' => ['required', 'string', 'max:120'],
            'special_request' => ['nullable', 'string', 'max:500'],
            'guest_token' => ['nullable', 'uuid'],
        ], $this->validationMessages($locale));

        $isEmail = $data['contact_type'] === 'email';

        if ($isEmail && ! filter_var($data['contact_value'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['contact_value' => $messages['contact_email']]);
        }

        if (! $isEmail && ! preg_match(self::PHONE_PATTERN, $data['contact_value'])) {
            throw ValidationException::withMessages(['contact_value' => $messages['contact_phone']]);
        }

        $stay = $this->resolveStay($apartment, $data, $locale);
        $unitType = $stay['unitType'];

        $conversation = isset($data['guest_token'])
            ? Conversation::where('apartment_id', $apartment->id)->where('guest_token', $data['guest_token'])->first()
            : null;

        $booking = $this->reservations->createRequest($apartment, $unitType, [
            'rental_type' => $stay['rentalType'],
            'lease_months' => $stay['leaseMonths'],
            'check_in' => $stay['checkIn'],
            'check_out' => $stay['checkOut'],
            'adults' => (int) $data['adults'],
            'children' => (int) ($data['children'] ?? 0),
            'units' => $stay['units'],
            'extra_bed' => $stay['extraBed'],
            'guest_name' => $data['guest_name'],
            'guest_email' => $isEmail ? $data['contact_value'] : null,
            'guest_phone' => $isEmail ? null : $data['contact_value'],
            'contact_type' => $data['contact_type'],
            'locale' => $locale,
            'notes' => $data['special_request'] ?? null,
        ], $conversation);

        if (! $booking) {
            return $this->unavailable($apartment, $unitType, $data, $locale);
        }

        if ($conversation) {
            $this->recordInConversation($conversation, $booking, $unitType, $apartment, $locale);
        }

        return response()->json([
            'reference' => $booking->reference,
            'status' => $booking->status,
            'total' => (float) $booking->total_price,
            'deposit_total' => $booking->deposit_total === null ? null : (float) $booking->deposit_total,
            'rental_type' => $booking->rental_type,
            'lease_months' => $booking->lease_months,
            'check_out' => $booking->check_out->toDateString(),
            'currency' => $apartment->currency,
            'handover' => $this->handover->forBooking($apartment, $booking, $locale),
        ], 201);
    }

    /**
     * Leaves the request in the guest's chat history, so the concierge knows it
     * was made and the guest sees the same confirmation when they come back.
     */
    private function recordInConversation(Conversation $conversation, Booking $booking, UnitType $unitType, Apartment $apartment, string $locale): void
    {
        $conversation->messages()->create([
            'role' => ConversationMessage::ROLE_ASSISTANT,
            'content' => str_replace(':reference', $booking->reference, self::MESSAGES[$locale]['chat_received']),
            'ui_payload' => [[
                'type' => 'booking_confirmation',
                'booking' => [
                    'booking_reference' => $booking->reference,
                    'unit_type_slug' => $unitType->slug,
                    'name' => $unitType->translatedName($locale),
                    'check_in' => $booking->check_in->toDateString(),
                    'check_out' => $booking->check_out->toDateString(),
                    'nights' => $booking->check_in->diffInDays($booking->check_out),
                    'rental_type' => $booking->rental_type,
                    'lease_months' => $booking->lease_months,
                    'deposit_total' => $booking->deposit_total === null ? null : (float) $booking->deposit_total,
                    'units' => $booking->unit_count,
                    'total_price' => (float) $booking->total_price,
                    'currency' => $apartment->currency,
                    'status' => 'pending_confirmation',
                ],
            ]],
        ]);

        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * A nightly stay is picked as a range of dates; a monthly or yearly rental
     * as a move-in date and a term in months, and ends on its own.
     *
     * @return array<string, mixed>
     */
    private function stayRules(Apartment $apartment, Request $request): array
    {
        $today = now($apartment->timezone)->toDateString();
        $isLease = in_array($request->input('rental_type'), [Booking::RENTAL_MONTHLY, Booking::RENTAL_YEARLY], true);

        return [
            'unit_type_slug' => ['required', 'string', 'max:120'],
            'rental_type' => ['nullable', Rule::in(Booking::RENTAL_TYPES)],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'check_out' => $isLease ? ['nullable'] : ['required', 'date_format:Y-m-d', 'after:check_in'],
            'lease_months' => $isLease ? ['required', 'integer', 'min:1', 'max:'.(12 * Apartment::MAX_YEARLY_LEASE_YEARS)] : ['nullable'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'units' => ['required', 'integer', 'min:1', 'max:10'],
            'extra_bed' => ['nullable', 'boolean'],
            'locale' => ['nullable', Rule::in(self::SUPPORTED_LOCALES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(string $locale): array
    {
        $messages = self::MESSAGES[$locale];

        return [
            'required' => $messages['invalid'],
            'date_format' => $messages['invalid'],
            'integer' => $messages['invalid'],
            'min' => $messages['invalid'],
            'max' => $messages['invalid'],
            'boolean' => $messages['invalid'],
            'in' => $messages['invalid'],
            'uuid' => $messages['invalid'],
            'after_or_equal' => $messages['check_in_past'],
            'after' => $messages['check_out_after'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{unitType: UnitType, rentalType: string, leaseMonths: ?int, checkIn: CarbonImmutable, checkOut: CarbonImmutable, units: int, extraBed: bool}
     */
    private function resolveStay(Apartment $apartment, array $data, string $locale): array
    {
        $messages = self::MESSAGES[$locale];

        $unitType = $apartment->unitTypes()->where('is_active', true)->where('slug', $data['unit_type_slug'])->first();

        if (! $unitType) {
            throw ValidationException::withMessages(['unit_type_slug' => $messages['unit_unknown']]);
        }

        $rentalType = $data['rental_type'] ?? Booking::RENTAL_DAILY;
        $checkIn = CarbonImmutable::parse($data['check_in'])->startOfDay();
        $units = (int) $data['units'];

        if (! $this->reservations->fitsOccupancy($unitType, (int) $data['adults'], (int) ($data['children'] ?? 0), $units)) {
            throw ValidationException::withMessages(['adults' => $messages['capacity']]);
        }

        if ($rentalType === Booking::RENTAL_DAILY) {
            $checkOut = CarbonImmutable::parse($data['check_out'])->startOfDay();

            if ($checkIn->diffInDays($checkOut) > ReservationService::MAX_NIGHTS) {
                throw ValidationException::withMessages([
                    'check_out' => str_replace(':max', (string) ReservationService::MAX_NIGHTS, $messages['too_long']),
                ]);
            }

            return [
                'unitType' => $unitType, 'rentalType' => $rentalType, 'leaseMonths' => null,
                'checkIn' => $checkIn, 'checkOut' => $checkOut, 'units' => $units, 'extraBed' => (bool) ($data['extra_bed'] ?? false),
            ];
        }

        if (! $unitType->offersRental($rentalType)) {
            throw ValidationException::withMessages(['unit_type_slug' => $messages['rental_not_offered']]);
        }

        $months = (int) $data['lease_months'];

        if (! $this->reservations->isValidLeaseTerm($apartment, $rentalType, $months)) {
            throw ValidationException::withMessages(['lease_months' => $rentalType === Booking::RENTAL_YEARLY
                ? str_replace(':max', (string) Apartment::MAX_YEARLY_LEASE_YEARS, $messages['lease_term_yearly'])
                : str_replace([':min', ':max'], [(string) max(1, $apartment->min_monthly_lease_months), (string) Apartment::MAX_MONTHLY_LEASE_MONTHS], $messages['lease_term_monthly'])]);
        }

        return [
            'unitType' => $unitType, 'rentalType' => $rentalType, 'leaseMonths' => $months,
            'checkIn' => $checkIn, 'checkOut' => $this->reservations->leaseEnd($checkIn, $months), 'units' => $units, 'extraBed' => false,
        ];
    }

    /**
     * @param  array{unitType: UnitType, rentalType: string, leaseMonths: ?int, checkIn: CarbonImmutable, checkOut: CarbonImmutable, units: int, extraBed: bool}  $stay
     * @return array<string, mixed>|null
     */
    private function quoteStay(array $stay): ?array
    {
        return $stay['rentalType'] === Booking::RENTAL_DAILY
            ? $this->reservations->quote($stay['unitType'], $stay['checkIn'], $stay['checkOut'], $stay['units'], $stay['extraBed'])
            : $this->reservations->quoteLease($stay['unitType'], $stay['rentalType'], $stay['checkIn'], $stay['leaseMonths'], $stay['units']);
    }

    /**
     * The unit cannot be booked for those nights: say so, and offer the unit
     * types that can host the same party on the same dates.
     *
     * @param  array<string, mixed>  $data
     */
    private function unavailable(Apartment $apartment, UnitType $requested, array $data, string $locale): JsonResponse
    {
        $checkIn = CarbonImmutable::parse($data['check_in'])->startOfDay();
        $rentalType = $data['rental_type'] ?? Booking::RENTAL_DAILY;
        $months = (int) ($data['lease_months'] ?? 0);
        $checkOut = $rentalType === Booking::RENTAL_DAILY
            ? CarbonImmutable::parse($data['check_out'])->startOfDay()
            : $this->reservations->leaseEnd($checkIn, $months);
        $units = (int) $data['units'];
        $adults = (int) $data['adults'];
        $children = (int) ($data['children'] ?? 0);

        $alternatives = $apartment->unitTypes()
            ->where('is_active', true)
            ->whereKeyNot($requested->id)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (UnitType $unitType) => $this->reservations->fitsOccupancy($unitType, $adults, $children, $units))
            ->map(function (UnitType $unitType) use ($checkIn, $checkOut, $units, $locale, $rentalType, $months) {
                $quote = $rentalType === Booking::RENTAL_DAILY
                    ? $this->reservations->quote($unitType, $checkIn, $checkOut, $units)
                    : $this->reservations->quoteLease($unitType, $rentalType, $checkIn, $months, $units);

                return $quote ? [
                    'slug' => $unitType->slug,
                    'name' => $unitType->translatedName($locale),
                    'total' => $quote['grand_total'],
                ] : null;
            })
            ->filter()
            ->values();

        $message = self::MESSAGES[$locale]['unavailable'];

        return response()->json([
            'message' => $message,
            'errors' => ['unit_type_slug' => [$message]],
            'alternatives' => $alternatives,
            'currency' => $apartment->currency,
        ], 422);
    }

    private function locale(Request $request, Apartment $apartment): string
    {
        return in_array($request->input('locale'), self::SUPPORTED_LOCALES, true)
            ? $request->input('locale')
            : $apartment->default_locale;
    }

    private function publishedApartment(string $apartmentSlug): Apartment
    {
        $apartment = Apartment::where('slug', $apartmentSlug)->first();

        abort_if(! $apartment || ! $apartment->isPublished(), 404);

        return $apartment;
    }
}
