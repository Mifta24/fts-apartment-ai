<?php

namespace App\Services\Concierge;

use App\Models\Apartment;
use App\Models\ApartmentKnowledgeItem;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\HandoverRequest;
use App\Models\UnitType;
use App\Notifications\NewHandoverRequest;
use App\Services\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Executes the AI Concierge's tools against this apartment's controlled data.
 * Every apartment fact, unit, price and availability answer must pass through
 * here — the model itself is never trusted to hold any of it.
 */
class ApartmentConciergeTools
{
    public function __construct(
        private readonly Apartment $apartment,
        private readonly Conversation $conversation,
        private readonly string $locale,
        private readonly ReservationService $reservations = new ReservationService,
    ) {}

    /**
     * OpenAI-compatible function-calling schema (used by the local LM Studio
     * / Ollama endpoint via ConciergeService — see topic in that class).
     */
    public static function definitions(): array
    {
        return array_map(
            fn (array $tool) => ['type' => 'function', 'function' => $tool],
            [
                [
                    'name' => 'search_knowledge',
                    'description' => 'Search the apartment building\'s approved knowledge base: house rules and policies, shared facilities (pool, gym, co-working, laundry, parking), building services (housekeeping, utilities, access cards, deliveries), dining and daily needs, transport, the neighbourhood, and FAQs. Always use this instead of answering apartment-fact questions from memory. Returns up to 5 matching entries.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Keywords from the guest question, e.g. "housekeeping", "electricity" or "pets"'],
                            'category' => [
                                'type' => 'string',
                                'enum' => ['general', 'facilities', 'policies', 'dining', 'transport', 'faq'],
                                'description' => 'Optional category filter',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name' => 'search_units',
                    'description' => 'Search apartment unit types (studio, 1, 2 or 3 bedrooms). With dates and household it checks real-time availability and returns the total price, long-stay discount already applied. Without dates it simply lists the building\'s unit types with their per-night, monthly and yearly starting prices. For monthly or yearly rentals pass rental_type and lease_months with the move-in date. Use this whenever a guest wants to see, browse or compare units, or describes what they want (dates, residents, bedrooms, view, budget) rather than naming one unit by name — never wait for dates before showing the units.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'check_in' => ['type' => 'string', 'description' => 'Optional check-in (or move-in) date, YYYY-MM-DD; leave out when the guest has not given dates'],
                            'check_out' => ['type' => 'string', 'description' => 'Optional check-out date, YYYY-MM-DD; only for daily rentals'],
                            'rental_type' => ['type' => 'string', 'enum' => ['daily', 'monthly', 'yearly'], 'description' => 'How the guest wants to rent: daily (by the night, default), monthly (a lease of 1–11 months) or yearly (a lease of 1–3 whole years)'],
                            'lease_months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Lease length in months for monthly (1–11) or yearly rentals (12, 24 or 36). Monthly and yearly rentals need check_in as the move-in date and lease_months; they do not use check_out'],
                            'adults' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional number of adults'],
                            'children' => ['type' => 'integer', 'minimum' => 0],
                            'view_type' => ['type' => 'string', 'description' => 'e.g. city, skyline, park, pool — optional preference'],
                            'bedrooms' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Optional minimum number of bedrooms; 0 means a studio is fine'],
                        ],
                        'required' => [],
                    ],
                ],
                [
                    'name' => 'get_unit_detail',
                    'description' => 'Get full details and photos for one unit type by its slug (from a previous search_units result): layout, bedrooms, bathrooms, floors, furnishing, and the long-stay discounts. Use this when the guest asks to see more about, or see photos of, a specific unit.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'image_tag' => ['type' => 'string', 'description' => 'Optional filter, e.g. "bedroom", "kitchen", "living_room", "bathroom", "view"'],
                        ],
                        'required' => ['unit_type_slug'],
                    ],
                ],
                [
                    'name' => 'check_availability',
                    'description' => 'Get the current, real-time availability and exact total price for one unit type over specific dates, including any weekly or monthly long-stay discount for nightly stays, or the monthly/yearly rent and security deposit for a lease (pass rental_type and lease_months). ALWAYS call this before confirming a price or telling a guest a unit is available — never state a price or availability from memory.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'check_in' => ['type' => 'string', 'description' => 'Check-in or move-in date, YYYY-MM-DD'],
                            'check_out' => ['type' => 'string', 'description' => 'Check-out date, YYYY-MM-DD; only for daily rentals'],
                            'rental_type' => ['type' => 'string', 'enum' => ['daily', 'monthly', 'yearly'], 'description' => 'How the guest wants to rent: daily (by the night, default), monthly (a lease of 1–11 months) or yearly (a lease of 1–3 whole years)'],
                            'lease_months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Lease length in months for monthly (1–11) or yearly rentals (12, 24 or 36). Monthly and yearly rentals need check_in as the move-in date and lease_months; they do not use check_out'],
                            'extra_bed' => ['type' => 'boolean', 'description' => 'Daily rentals only'],
                        ],
                        'required' => ['unit_type_slug', 'check_in'],
                    ],
                ],
                [
                    'name' => 'start_reservation',
                    'description' => 'Open the stay request form on the guest\'s screen, pre-filled with whatever you already know. Use this as soon as a guest says they want to book, reserve, rent or request a unit — the guest fills in their name, contact and any missing details in the form themselves, so do NOT ask for name or phone in chat first. Only the unit is required; pass dates and household size when the guest already gave them.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'check_in' => ['type' => 'string', 'description' => 'Optional, YYYY-MM-DD'],
                            'check_out' => ['type' => 'string', 'description' => 'Optional, YYYY-MM-DD; only for daily rentals'],
                            'rental_type' => ['type' => 'string', 'enum' => ['daily', 'monthly', 'yearly'], 'description' => 'How the guest wants to rent: daily (by the night, default), monthly (a lease of 1–11 months) or yearly (a lease of 1–3 whole years)'],
                            'lease_months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Lease length in months for monthly (1–11) or yearly rentals (12, 24 or 36). Monthly and yearly rentals need check_in as the move-in date and lease_months; they do not use check_out'],
                            'adults' => ['type' => 'integer', 'minimum' => 1],
                            'children' => ['type' => 'integer', 'minimum' => 0],
                            'units' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of units, default 1'],
                        ],
                        'required' => ['unit_type_slug'],
                    ],
                ],
                [
                    'name' => 'create_booking_request',
                    'description' => 'Create a stay request directly in the chat, only when the guest has already typed the unit, exact dates (or move-in date and lease length), household size, their name and phone in the conversation. Otherwise use start_reservation instead. This re-checks availability before booking. It does not charge payment, take the security deposit or sign a lease — it creates a pending request for the apartment team to confirm.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'check_in' => ['type' => 'string', 'description' => 'Check-in or move-in date, YYYY-MM-DD'],
                            'check_out' => ['type' => 'string', 'description' => 'Check-out date, YYYY-MM-DD; only for daily rentals'],
                            'rental_type' => ['type' => 'string', 'enum' => ['daily', 'monthly', 'yearly'], 'description' => 'How the guest wants to rent: daily (by the night, default), monthly (a lease of 1–11 months) or yearly (a lease of 1–3 whole years)'],
                            'lease_months' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Lease length in months for monthly (1–11) or yearly rentals (12, 24 or 36). Monthly and yearly rentals need check_in as the move-in date and lease_months; they do not use check_out'],
                            'adults' => ['type' => 'integer'],
                            'children' => ['type' => 'integer'],
                            'units' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of units, default 1'],
                            'extra_bed' => ['type' => 'boolean'],
                            'guest_name' => ['type' => 'string'],
                            'guest_email' => ['type' => 'string'],
                            'guest_phone' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['unit_type_slug', 'check_in', 'adults', 'guest_name', 'guest_phone'],
                    ],
                ],
                [
                    'name' => 'request_human_handover',
                    'description' => 'Hand this conversation over to a human member of the apartment team. Use this for special requests, complaints, maintenance issues, corporate or group leases, negotiated rates, stays longer than the online limit, unusual cancellations, payment problems, or any question you cannot answer confidently from the available tools. Always write a clear summary so staff do not need to ask the guest to repeat themselves.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'enum' => ['special_request', 'complaint', 'group_booking', 'negotiated_rate', 'unusual_cancellation', 'payment_issue', 'low_confidence'],
                            ],
                            'summary' => ['type' => 'string', 'description' => 'What the guest wants and the relevant context gathered so far, written for a staff member who has not seen this conversation'],
                            'guest_email' => ['type' => 'string', 'description' => 'Optional: the email address the guest gave, so the team can answer even after they leave the chat'],
                        ],
                        'required' => ['reason', 'summary'],
                    ],
                ],
            ]
        );
    }

    /**
     * Arguments each tool cannot run without. The model sometimes leaves one
     * out, and it should hear that as a tool result rather than crash the turn.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_INPUT = [
        'search_knowledge' => ['query'],
        'get_unit_detail' => ['unit_type_slug'],
        'check_availability' => ['unit_type_slug', 'check_in'],
        'start_reservation' => ['unit_type_slug'],
        'create_booking_request' => ['unit_type_slug', 'check_in', 'adults', 'guest_name', 'guest_phone'],
        'request_human_handover' => ['reason', 'summary'],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array{text: string, ui: array|null}
     */
    public function dispatch(string $name, array $input): array
    {
        $missing = array_filter(
            self::REQUIRED_INPUT[$name] ?? [],
            fn (string $key) => ! is_scalar($input[$key] ?? null) || ! filled($input[$key]),
        );

        if ($missing !== []) {
            return ['text' => 'Missing or invalid argument(s): '.implode(', ', $missing).'. Call the tool again with them, or ask the guest for what you still need.', 'ui' => null];
        }

        return match ($name) {
            'search_knowledge' => $this->searchKnowledge($input),
            'search_units' => $this->searchUnits($input),
            'get_unit_detail' => $this->getUnitDetail($input),
            'check_availability' => $this->checkAvailability($input),
            'start_reservation' => $this->startReservation($input),
            'create_booking_request' => $this->createBookingRequest($input),
            'request_human_handover' => $this->requestHumanHandover($input),
            default => ['text' => "Unknown tool: {$name}", 'ui' => null],
        };
    }

    private function searchKnowledge(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        $category = $input['category'] ?? null;
        $words = collect(preg_split('/\s+/', Str::lower($query)))->filter();

        $items = $this->apartment->knowledgeItems()
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->get()
            ->filter(function (ApartmentKnowledgeItem $item) use ($query, $words) {
                if ($query === '') {
                    return true;
                }

                $haystack = Str::lower($item->title.' '.$item->body.' '.implode(' ', $item->tags ?? []));

                return Str::contains($haystack, Str::lower($query))
                    || $words->contains(fn ($word) => Str::contains($haystack, $word));
            })
            ->take(5);

        if ($items->isEmpty()) {
            return [
                'text' => 'No matching knowledge base entry was found. Do not guess the answer — tell the guest you will check with the team, or call request_human_handover.',
                'ui' => null,
            ];
        }

        $results = $items->map(fn (ApartmentKnowledgeItem $item) => [
            'category' => $item->category,
            'title' => $item->translatedTitle($this->locale),
            'body' => $item->translatedBody($this->locale),
        ])->values()->all();

        return ['text' => json_encode($results, JSON_UNESCAPED_UNICODE), 'ui' => null];
    }

    private function searchUnits(array $input): array
    {
        $rentalType = $this->rentalType($input);
        $isLease = $rentalType !== Booking::RENTAL_DAILY;

        if (empty($input['check_in']) || ($isLease ? empty($input['lease_months']) : empty($input['check_out']))) {
            return $this->browseUnits($input);
        }

        $stay = $this->stayFromInput($input);

        if (is_string($stay)) {
            return ['text' => $stay, 'ui' => null];
        }

        ['check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => $nights] = $stay;
        $adults = max(1, (int) ($input['adults'] ?? 1));
        $children = (int) ($input['children'] ?? 0);
        $viewType = $input['view_type'] ?? null;
        $bedrooms = isset($input['bedrooms']) ? (int) $input['bedrooms'] : null;

        $candidates = $this->apartment->unitTypes()
            ->where('is_active', true)
            ->with('images')
            ->get()
            ->filter(fn (UnitType $rt) => $rt->offersRental($rentalType))
            ->filter(fn (UnitType $rt) => $this->reservations->fitsOccupancy($rt, $adults, $children, 1))
            ->when($viewType, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->view_type === $viewType))
            ->when($bedrooms !== null, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->bedrooms >= $bedrooms));

        $matches = [];
        foreach ($candidates as $unitType) {
            $quote = $this->quoteFor($unitType, $stay);
            if (! $quote) {
                continue;
            }

            $thumbnail = $unitType->images->first();

            $matches[] = [
                'unit_type_slug' => $unitType->slug,
                'name' => $unitType->translatedName($this->locale),
                'size_sqm' => $unitType->size_sqm,
                'bedrooms' => $unitType->bedrooms,
                'bathrooms' => $unitType->bathrooms,
                'floor_range' => $unitType->floor_range,
                'view_type' => $unitType->view_type,
                'max_adults' => $unitType->max_adults,
                'max_children' => $unitType->max_children,
                'breakfast_included' => $unitType->breakfast_included,
                'rental_type' => $rentalType,
                'lease_months' => $stay['lease_months'],
                'nights' => $nights,
                'discount_percent' => $quote['discount_percent'],
                'total_price' => $quote['grand_total'],
                ...($isLease ? ['rate' => $quote['rate'], 'rate_period' => $quote['rate_period'], 'deposit_total' => $quote['deposit_total']] : []),
                'currency' => $this->apartment->currency,
                'min_available_units' => $quote['min_available_units'],
                'thumbnail_url' => $thumbnail?->image_source,
            ];
        }

        if (empty($matches)) {
            return [
                'text' => 'No unit type offers that rental with availability for every night of that stay for this household. Tell the guest honestly and offer to check different dates or another layout, or call request_human_handover if they want to join the waiting list.',
                'ui' => null,
            ];
        }

        usort($matches, fn ($a, $b) => $a['total_price'] <=> $b['total_price']);

        $showcase = array_map(
            fn (array $match) => [...$this->unitDetail($candidates->firstWhere('slug', $match['unit_type_slug'])), ...$match],
            $matches,
        );

        return [
            'text' => json_encode($matches, JSON_UNESCAPED_UNICODE),
            'ui' => [
                'type' => 'unit_results',
                'rental_type' => $rentalType,
                'lease_months' => $stay['lease_months'],
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'adults' => $adults,
                'children' => $children,
                'units' => $showcase,
            ],
        ];
    }

    /**
     * Lists the building's active unit types with their per-night starting
     * price when the guest has not given dates yet.
     *
     * @param  array<string, mixed>  $input
     * @return array{text: string, ui: ?array<string, mixed>}
     */
    private function browseUnits(array $input): array
    {
        $viewType = $input['view_type'] ?? null;
        $bedrooms = isset($input['bedrooms']) ? (int) $input['bedrooms'] : null;

        $unitTypes = $this->apartment->unitTypes()
            ->where('is_active', true)
            ->with('images')
            ->get()
            ->when($viewType, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->view_type === $viewType))
            ->when($bedrooms !== null, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->bedrooms >= $bedrooms))
            ->sortBy('base_price')
            ->values();

        if ($unitTypes->isEmpty()) {
            return ['text' => 'No unit type matches. Tell the guest honestly, or call request_human_handover.', 'ui' => null];
        }

        $units = $unitTypes->map(fn (UnitType $unitType) => [
            ...$this->unitDetail($unitType),
            'thumbnail_url' => $unitType->images->first()?->image_source,
        ])->all();

        $summary = array_map(fn (array $unit) => [
            'unit_type_slug' => $unit['unit_type_slug'],
            'name' => $unit['name'],
            'size_sqm' => $unit['size_sqm'],
            'bedrooms' => $unit['bedrooms'],
            'max_adults' => $unit['max_adults'],
            'max_children' => $unit['max_children'],
            'starting_price_per_night' => $unit['starting_price'],
            'monthly_price' => $unit['monthly_price'],
            'yearly_price' => $unit['yearly_price'],
            'currency' => $unit['currency'],
        ], $units);

        return [
            'text' => json_encode([
                'units' => $summary,
                'note' => 'No dates were given, so these are indicative starting prices only (per night, and the monthly or yearly rent where the unit is rented that way). The units are now shown on the guest\'s screen; ask for dates and household size to check real availability and the exact price.',
            ], JSON_UNESCAPED_UNICODE),
            'ui' => [
                'type' => 'unit_results',
                'rental_type' => null,
                'lease_months' => null,
                'check_in' => null,
                'check_out' => null,
                'adults' => null,
                'children' => null,
                'units' => $units,
            ],
        ];
    }

    private function getUnitDetail(array $input): array
    {
        $unitType = $this->findUnitType($input['unit_type_slug']);
        if (! $unitType) {
            return ['text' => 'Unit type not found.', 'ui' => null];
        }

        $detail = $this->unitDetail($unitType, $input['image_tag'] ?? null);

        return [
            'text' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'unit_detail', 'unit' => $detail],
        ];
    }

    /**
     * Opens the stay request form on the guest's screen, pre-filled. The guest
     * types their own name and contact there; the form submits through the
     * same reservation endpoint as the stay request wizard.
     */
    private function startReservation(array $input): array
    {
        $unitType = $this->findUnitType($input['unit_type_slug']);
        if (! $unitType) {
            return ['text' => 'Unit type not found.', 'ui' => null];
        }

        $adults = max(1, (int) ($input['adults'] ?? 1));
        $children = max(0, (int) ($input['children'] ?? 0));
        $units = max(1, (int) ($input['units'] ?? 1));
        $rentalType = $this->rentalType($input);
        $prefill = compact('adults', 'children', 'units');
        $quote = null;

        if ($rentalType !== Booking::RENTAL_DAILY && $unitType->offersRental($rentalType)) {
            $prefill['rental_type'] = $rentalType;
            $prefill['lease_months'] = (int) ($input['lease_months'] ?? 0) ?: null;
        }

        $hasDates = filled($input['check_in'] ?? null) && ($rentalType === Booking::RENTAL_DAILY ? filled($input['check_out'] ?? null) : filled($input['lease_months'] ?? null));
        $stay = $hasDates ? $this->stayFromInput($input) : null;

        if (is_array($stay)) {
            $today = CarbonImmutable::now($this->apartment->timezone)->startOfDay();

            if (! $stay['check_in']->lessThan($today)) {
                $prefill += ['check_in' => $stay['check_in']->toDateString(), 'check_out' => $stay['check_out']->toDateString()];
                $quote = $this->quoteFor($unitType, $stay, $units);

                if (! $quote) {
                    return [
                        'text' => 'This unit has no availability for every night of those dates, so the form was not opened. Tell the guest honestly and offer other dates or another unit (search_units).',
                        'ui' => ['type' => 'availability', 'available' => false, 'unit_type_slug' => $unitType->slug],
                    ];
                }
            }
        }

        $unit = $this->unitDetail($unitType);

        return [
            'text' => json_encode([
                'ok' => true,
                'message' => 'The stay request form for '.$unit['name'].' is now open on the guest\'s screen'.(isset($prefill['check_in']) ? ' with the dates filled in' : '').'. Tell the guest in one short sentence to complete their name and contact there. Do not ask for their name or phone in chat.',
            ], JSON_UNESCAPED_UNICODE),
            'ui' => [
                'type' => 'reservation_form',
                'unit' => $unit,
                'prefill' => $prefill,
                'quote' => $quote ? ['nights' => $quote['nights'], 'grand_total' => $quote['grand_total'], 'discount_percent' => $quote['discount_percent'], 'deposit_total' => $quote['deposit_total'] ?? null] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitDetail(UnitType $unitType, ?string $imageTag = null): array
    {
        $images = $unitType->images;
        if ($imageTag) {
            $images = $images->filter(fn ($img) => $img->hasTag($imageTag));
        }

        return [
            'unit_type_slug' => $unitType->slug,
            'name' => $unitType->translatedName($this->locale),
            'description' => $unitType->translatedDescription($this->locale),
            'size_sqm' => $unitType->size_sqm,
            'bedrooms' => $unitType->bedrooms,
            'bathrooms' => $unitType->bathrooms,
            'floor_range' => $unitType->floor_range,
            'bed_config' => $unitType->bed_config,
            'view_type' => $unitType->view_type,
            'max_adults' => $unitType->max_adults,
            'max_children' => $unitType->max_children,
            'breakfast_included' => $unitType->breakfast_included,
            'extra_bed_available' => $unitType->extra_bed_available,
            'extra_bed_price' => $unitType->extra_bed_price,
            'amenities' => $unitType->amenities,
            'starting_price' => $unitType->base_price,
            'monthly_price' => $unitType->offersMonthlyRental() ? $unitType->monthly_price : null,
            'yearly_price' => $unitType->offersYearlyRental() ? $unitType->yearly_price : null,
            'yearly_monthly_equivalent' => $unitType->yearlyMonthlyEquivalent(),
            'currency' => $this->apartment->currency,
            'lease' => [
                'security_deposit_months' => $this->apartment->security_deposit_months,
                'min_monthly_lease_months' => max(1, $this->apartment->min_monthly_lease_months),
                'max_monthly_lease_months' => Apartment::MAX_MONTHLY_LEASE_MONTHS,
                'max_yearly_lease_years' => Apartment::MAX_YEARLY_LEASE_YEARS,
            ],
            'long_stay' => [
                'weekly_discount_percent' => $this->apartment->weekly_discount_percent,
                'weekly_from_nights' => Apartment::WEEKLY_STAY_NIGHTS,
                'monthly_discount_percent' => $this->apartment->monthly_discount_percent,
                'monthly_from_nights' => Apartment::MONTHLY_STAY_NIGHTS,
            ],
            'note' => 'starting_price is a per-night rate before long-stay discounts; monthly_price is the rent per month and yearly_price the rent per year (null when the unit is not rented that way). All are indicative only — always call check_availability for the exact price on real dates.',
            'images' => $images->map(fn ($img) => [
                'url' => $img->image_source,
                'tags' => $img->tags,
                'alt' => $img->alt_text,
            ])->values()->all(),
        ];
    }

    private function checkAvailability(array $input): array
    {
        $unitType = $this->findUnitType($input['unit_type_slug']);
        if (! $unitType) {
            return ['text' => 'Unit type not found.', 'ui' => null];
        }

        $stay = $this->stayFromInput($input);
        if (is_string($stay)) {
            return ['text' => $stay, 'ui' => null];
        }

        $isLease = $stay['rental_type'] !== Booking::RENTAL_DAILY;
        $extraBed = ! $isLease && (bool) ($input['extra_bed'] ?? false);

        if ($isLease && ! $unitType->offersRental($stay['rental_type'])) {
            return ['text' => 'This unit type is not rented '.$stay['rental_type'].'. Offer the rental types it does have (see monthly_price / yearly_price from get_unit_detail) or another unit.', 'ui' => null];
        }

        $quote = $this->quoteFor($unitType, $stay, extraBed: $extraBed);

        if (! $quote) {
            return [
                'text' => json_encode([
                    'available' => false,
                    'unit_type_slug' => $unitType->slug,
                    'message' => 'Not every night in this range has availability for this unit type.',
                ], JSON_UNESCAPED_UNICODE),
                'ui' => ['type' => 'availability', 'available' => false, 'unit_type_slug' => $unitType->slug],
            ];
        }

        $result = [
            'available' => true,
            'unit_type_slug' => $unitType->slug,
            'name' => $unitType->translatedName($this->locale),
            'rental_type' => $stay['rental_type'],
            'lease_months' => $stay['lease_months'],
            'check_in' => $stay['check_in']->toDateString(),
            'check_out' => $stay['check_out']->toDateString(),
            'nights' => $stay['nights'],
            ...($isLease
                ? [
                    'rate' => $quote['rate'],
                    'rate_period' => $quote['rate_period'],
                    'monthly_rate' => $quote['monthly_rate'],
                    'deposit_months' => $quote['deposit_months'],
                    'deposit_total' => $quote['deposit_total'],
                ]
                : ['nightly_breakdown' => $quote['nightly']]),
            'unit_total' => $quote['unit_total'],
            'extra_bed_total' => $quote['extra_bed_total'],
            'subtotal' => $quote['subtotal'],
            'discount_percent' => $quote['discount_percent'],
            'discount_total' => $quote['discount_total'],
            'grand_total' => $quote['grand_total'],
            'currency' => $this->apartment->currency,
            'min_available_units' => $quote['min_available_units'],
        ];

        return [
            'text' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'availability', 'available' => true, 'quote' => $result],
        ];
    }

    private function createBookingRequest(array $input): array
    {
        $unitType = $this->findUnitType($input['unit_type_slug']);
        if (! $unitType) {
            return ['text' => 'Unit type not found.', 'ui' => null];
        }

        $stay = $this->stayFromInput($input);
        if (is_string($stay)) {
            return ['text' => $stay, 'ui' => null];
        }

        if (! $unitType->offersRental($stay['rental_type'])) {
            return ['text' => 'This unit type is not rented '.$stay['rental_type'].'. Offer another rental type or unit.', 'ui' => null];
        }

        $units = (int) ($input['units'] ?? 1);
        $adults = (int) $input['adults'];
        $children = (int) ($input['children'] ?? 0);
        $guestName = trim((string) $input['guest_name']);
        $guestPhone = trim((string) $input['guest_phone']);
        $guestEmail = trim((string) ($input['guest_email'] ?? ''));

        $problem = match (true) {
            $units < 1 || $units > 10 => 'units must be between 1 and 10.',
            $adults < 1 || $adults > 20 => 'adults must be between 1 and 20.',
            $children < 0 || $children > 10 => 'children must be between 0 and 10.',
            $guestName === '' || mb_strlen($guestName) > 100 => 'guest_name must be the guest\'s name, at most 100 characters.',
            preg_match(ReservationService::PHONE_PATTERN, $guestPhone) !== 1 => 'guest_phone is not a valid phone or WhatsApp number. Ask the guest to type it again.',
            $guestEmail !== '' && ! filter_var($guestEmail, FILTER_VALIDATE_EMAIL) => 'guest_email is not a valid email address. Ask the guest to type it again or leave it out.',
            default => null,
        };

        if ($problem) {
            return ['text' => "The request was not created: {$problem}", 'ui' => null];
        }

        if (! $this->reservations->fitsOccupancy($unitType, $adults, $children, $units)) {
            return ['text' => 'This household does not fit that unit type for the requested number of units. Suggest a unit with more bedrooms or more units.', 'ui' => null];
        }

        $booking = $this->pendingDuplicate($unitType, $stay, $units, $guestPhone) ?? $this->reservations->createRequest($this->apartment, $unitType, [
            'rental_type' => $stay['rental_type'],
            'lease_months' => $stay['lease_months'],
            'check_in' => $stay['check_in'],
            'check_out' => $stay['check_out'],
            'adults' => $adults,
            'children' => $children,
            'units' => $units,
            'extra_bed' => (bool) ($input['extra_bed'] ?? false),
            'guest_name' => $guestName,
            'guest_email' => $guestEmail !== '' ? $guestEmail : null,
            'guest_phone' => $guestPhone,
            'locale' => $this->locale,
            'notes' => isset($input['notes']) && is_string($input['notes']) ? Str::limit($input['notes'], 500, '') : null,
        ], $this->conversation);

        if (! $booking) {
            return [
                'text' => 'Unit is no longer available for these exact dates — availability may have just changed. Call check_availability again or offer alternative dates.',
                'ui' => null,
            ];
        }

        $payload = [
            'booking_reference' => $booking->reference,
            'unit_type_slug' => $unitType->slug,
            'name' => $unitType->translatedName($this->locale),
            'rental_type' => $booking->rental_type,
            'lease_months' => $booking->lease_months,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'nights' => (int) $booking->check_in->diffInDays($booking->check_out),
            'units' => $booking->unit_count,
            'total_price' => (float) $booking->total_price,
            'deposit_total' => $booking->deposit_total === null ? null : (float) $booking->deposit_total,
            'currency' => $this->apartment->currency,
            'status' => 'pending_confirmation',
        ];

        return [
            'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'booking_confirmation', 'booking' => $payload],
        ];
    }

    /**
     * The request this conversation already made for the same unit, dates and
     * phone. A turn that failed after the booking was saved is retried by the
     * guest, and the retry must not hold the units a second time.
     *
     * @param  array{rental_type: string, check_in: CarbonImmutable, check_out: CarbonImmutable}  $stay
     */
    private function pendingDuplicate(UnitType $unitType, array $stay, int $units, string $guestPhone): ?Booking
    {
        return $this->conversation->bookings()
            ->where('status', Booking::STATUS_PENDING)
            ->where('unit_type_id', $unitType->id)
            ->where('rental_type', $stay['rental_type'])
            ->whereDate('check_in', $stay['check_in']->toDateString())
            ->whereDate('check_out', $stay['check_out']->toDateString())
            ->where('unit_count', $units)
            ->where('guest_phone', $guestPhone)
            ->first();
    }

    private function requestHumanHandover(array $input): array
    {
        if ($this->conversation->isHandedOver()) {
            return [
                'text' => json_encode(['ok' => true, 'message' => 'The team already has this conversation and will join shortly.'], JSON_UNESCAPED_UNICODE),
                'ui' => null,
            ];
        }

        $reason = $input['reason'];
        $summary = $input['summary'];
        $email = trim((string) ($input['guest_email'] ?? ''));
        $emailIsValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $handover = HandoverRequest::create([
            'conversation_id' => $this->conversation->id,
            'reason' => $reason,
            'summary' => $summary,
            'status' => HandoverRequest::STATUS_OPEN,
        ]);

        $this->apartment->notifyStaff(new NewHandoverRequest($handover));

        $this->conversation->update([
            'status' => Conversation::STATUS_HANDED_OVER,
            'handover_summary' => $summary,
            ...($emailIsValid ? ['guest_email' => $email] : []),
        ]);

        return [
            'text' => json_encode([
                'ok' => true,
                'message' => 'A staff member has been notified and will join this conversation shortly.'.($email !== '' && ! $emailIsValid ? ' The email address given was not valid and was not saved; ask the guest to type it again.' : ''),
            ], JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'handover', 'reason' => $reason, 'summary' => $summary],
        ];
    }

    // --- helpers ---

    private function findUnitType(string $slug): ?UnitType
    {
        return $this->apartment->unitTypes()->where('is_active', true)->where('slug', $slug)->first();
    }

    private function rentalType(array $input): string
    {
        return in_array($input['rental_type'] ?? null, Booking::RENTAL_TYPES, true) ? $input['rental_type'] : Booking::RENTAL_DAILY;
    }

    /**
     * The stay the guest described, whichever way they want to rent: dates
     * for a nightly stay, a move-in date and a term for a lease. A string is
     * the problem to report back to the model instead.
     *
     * @param  array<string, mixed>  $input
     * @return array{rental_type: string, lease_months: ?int, check_in: CarbonImmutable, check_out: CarbonImmutable, nights: int}|string
     */
    private function stayFromInput(array $input): array|string
    {
        $rentalType = $this->rentalType($input);
        $checkIn = $this->parseDate($input['check_in'] ?? null);

        if ($rentalType === Booking::RENTAL_DAILY) {
            $checkOut = $this->parseDate($input['check_out'] ?? null);

            if (! $checkIn || ! $checkOut) {
                return 'check_in and check_out are required for a daily rental, written as YYYY-MM-DD.';
            }

            $nights = (int) $checkIn->diffInDays($checkOut, false);

            if ($nights < 1) {
                return 'check_out must be after check_in.';
            }

            if ($nights > ReservationService::MAX_NIGHTS) {
                return 'Online stays are limited to '.ReservationService::MAX_NIGHTS.' nights. For a longer stay offer a monthly or yearly rental, or call request_human_handover.';
            }

            $stay = ['rental_type' => $rentalType, 'lease_months' => null, 'check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => $nights];
        } else {
            $months = (int) ($input['lease_months'] ?? 0);

            if (! $checkIn || ! $this->reservations->isValidLeaseTerm($this->apartment, $rentalType, $months)) {
                return $rentalType === Booking::RENTAL_YEARLY
                    ? 'A yearly rental needs a move-in date (check_in, YYYY-MM-DD) and lease_months of 12, 24 or 36.'
                    : 'A monthly rental needs a move-in date (check_in, YYYY-MM-DD) and lease_months from '.max(1, $this->apartment->min_monthly_lease_months).' to '.Apartment::MAX_MONTHLY_LEASE_MONTHS.'. Twelve months or more is a yearly rental.';
            }

            $checkOut = $this->reservations->leaseEnd($checkIn, $months);
            $stay = ['rental_type' => $rentalType, 'lease_months' => $months, 'check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => (int) $checkIn->diffInDays($checkOut)];
        }

        $today = CarbonImmutable::now($this->apartment->timezone)->startOfDay();

        if ($checkIn->toDateString() < $today->toDateString()) {
            return "check_in is in the past. Today is {$today->toDateString()}; ask the guest for a date from today on.";
        }

        return $stay;
    }

    /**
     * A real calendar date written as YYYY-MM-DD, or null for anything else.
     */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->toDateString() === $value ? $date : null;
    }

    /**
     * @param  array{rental_type: string, lease_months: ?int, check_in: CarbonImmutable, check_out: CarbonImmutable, nights: int}  $stay
     * @return array<string, mixed>|null
     */
    private function quoteFor(UnitType $unitType, array $stay, int $units = 1, bool $extraBed = false): ?array
    {
        return $stay['rental_type'] === Booking::RENTAL_DAILY
            ? $this->reservations->quote($unitType, $stay['check_in'], $stay['check_out'], $units, $extraBed)
            : $this->reservations->quoteLease($unitType, $stay['rental_type'], $stay['check_in'], $stay['lease_months'], $units);
    }
}
