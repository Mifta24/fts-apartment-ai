<?php

namespace App\Services\Concierge;

use App\Models\Apartment;
use App\Models\ApartmentKnowledgeItem;
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
                    'description' => 'Search apartment unit types (studio, 1, 2 or 3 bedrooms). With dates and household it checks real-time availability and returns the total price, long-stay discount already applied. Without dates it simply lists the building\'s unit types with their per-night starting price. Use this whenever a guest wants to see, browse or compare units, or describes what they want (dates, residents, bedrooms, view, budget) rather than naming one unit by name — never wait for dates before showing the units.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'check_in' => ['type' => 'string', 'description' => 'Optional check-in date, YYYY-MM-DD; leave out when the guest has not given dates'],
                            'check_out' => ['type' => 'string', 'description' => 'Optional check-out date, YYYY-MM-DD'],
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
                    'description' => 'Get the current, real-time availability and exact total price for one unit type over specific dates, including any weekly or monthly long-stay discount. ALWAYS call this before confirming a price or telling a guest a unit is available — never state a price or availability from memory.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'check_in' => ['type' => 'string'],
                            'check_out' => ['type' => 'string'],
                            'extra_bed' => ['type' => 'boolean'],
                        ],
                        'required' => ['unit_type_slug', 'check_in', 'check_out'],
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
                            'check_out' => ['type' => 'string', 'description' => 'Optional, YYYY-MM-DD'],
                            'adults' => ['type' => 'integer', 'minimum' => 1],
                            'children' => ['type' => 'integer', 'minimum' => 0],
                            'units' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of units, default 1'],
                        ],
                        'required' => ['unit_type_slug'],
                    ],
                ],
                [
                    'name' => 'create_booking_request',
                    'description' => 'Create a stay request directly in the chat, only when the guest has already typed the unit, exact dates, household size, their name and phone in the conversation. Otherwise use start_reservation instead. This re-checks availability before booking. It does not charge payment or sign a lease — it creates a pending request for the apartment team to confirm.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'unit_type_slug' => ['type' => 'string'],
                            'check_in' => ['type' => 'string'],
                            'check_out' => ['type' => 'string'],
                            'adults' => ['type' => 'integer'],
                            'children' => ['type' => 'integer'],
                            'units' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of units, default 1'],
                            'extra_bed' => ['type' => 'boolean'],
                            'guest_name' => ['type' => 'string'],
                            'guest_email' => ['type' => 'string'],
                            'guest_phone' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['unit_type_slug', 'check_in', 'check_out', 'adults', 'guest_name', 'guest_phone'],
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
                        ],
                        'required' => ['reason', 'summary'],
                    ],
                ],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{text: string, ui: array|null}
     */
    public function dispatch(string $name, array $input): array
    {
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
        if (empty($input['check_in']) || empty($input['check_out'])) {
            return $this->browseUnits($input);
        }

        [$checkIn, $checkOut, $nights] = $this->parseStay($input['check_in'], $input['check_out']);
        $adults = max(1, (int) ($input['adults'] ?? 1));
        $children = (int) ($input['children'] ?? 0);
        $viewType = $input['view_type'] ?? null;
        $bedrooms = isset($input['bedrooms']) ? (int) $input['bedrooms'] : null;

        if ($nights < 1) {
            return ['text' => 'check_out must be after check_in.', 'ui' => null];
        }

        $candidates = $this->apartment->unitTypes()
            ->where('is_active', true)
            ->with('images')
            ->get()
            ->filter(fn (UnitType $rt) => $this->reservations->fitsOccupancy($rt, $adults, $children, 1))
            ->when($viewType, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->view_type === $viewType))
            ->when($bedrooms !== null, fn ($c) => $c->filter(fn (UnitType $rt) => $rt->bedrooms >= $bedrooms));

        $matches = [];
        foreach ($candidates as $unitType) {
            $stay = $this->reservations->quote($unitType, $checkIn, $checkOut);
            if (! $stay) {
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
                'nights' => $nights,
                'discount_percent' => $stay['discount_percent'],
                'total_price' => $stay['grand_total'],
                'currency' => $this->apartment->currency,
                'min_available_units' => $stay['min_available_units'],
                'thumbnail_url' => $thumbnail?->image_source,
            ];
        }

        if (empty($matches)) {
            return [
                'text' => 'No unit type has availability for every night of that stay for this household. Tell the guest honestly and offer to check different dates or another layout, or call request_human_handover if they want to join the waiting list.',
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
                'check_in' => $input['check_in'],
                'check_out' => $input['check_out'],
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
            'currency' => $unit['currency'],
        ], $units);

        return [
            'text' => json_encode([
                'units' => $summary,
                'note' => 'No dates were given, so these are indicative per-night starting prices only. The units are now shown on the guest\'s screen; ask for dates and household size to check real availability and the exact price.',
            ], JSON_UNESCAPED_UNICODE),
            'ui' => [
                'type' => 'unit_results',
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
        $prefill = compact('adults', 'children', 'units');
        $quote = null;

        if (filled($input['check_in'] ?? null) && filled($input['check_out'] ?? null)) {
            [$checkIn, $checkOut, $nights] = $this->parseStay($input['check_in'], $input['check_out']);
            $today = CarbonImmutable::now($this->apartment->timezone)->startOfDay();

            if ($nights >= 1 && ! $checkIn->lessThan($today)) {
                $prefill += ['check_in' => $checkIn->toDateString(), 'check_out' => $checkOut->toDateString()];
                $quote = $this->reservations->quote($unitType, $checkIn, $checkOut, $units);

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
                'quote' => $quote ? ['nights' => $quote['nights'], 'grand_total' => $quote['grand_total'], 'discount_percent' => $quote['discount_percent']] : null,
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
            'currency' => $this->apartment->currency,
            'long_stay' => [
                'weekly_discount_percent' => $this->apartment->weekly_discount_percent,
                'weekly_from_nights' => Apartment::WEEKLY_STAY_NIGHTS,
                'monthly_discount_percent' => $this->apartment->monthly_discount_percent,
                'monthly_from_nights' => Apartment::MONTHLY_STAY_NIGHTS,
            ],
            'note' => 'starting_price is a per-night rate before long-stay discounts and is indicative only — always call check_availability for the exact price on real dates.',
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

        [$checkIn, $checkOut, $nights] = $this->parseStay($input['check_in'], $input['check_out']);
        if ($nights < 1) {
            return ['text' => 'check_out must be after check_in.', 'ui' => null];
        }

        $extraBed = (bool) ($input['extra_bed'] ?? false);
        $stay = $this->reservations->quote($unitType, $checkIn, $checkOut, extraBed: $extraBed);

        if (! $stay) {
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
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => $nights,
            'nightly_breakdown' => $stay['nightly'],
            'unit_total' => $stay['unit_total'],
            'extra_bed_total' => $stay['extra_bed_total'],
            'subtotal' => $stay['subtotal'],
            'discount_percent' => $stay['discount_percent'],
            'discount_total' => $stay['discount_total'],
            'grand_total' => $stay['grand_total'],
            'currency' => $this->apartment->currency,
            'min_available_units' => $stay['min_available_units'],
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

        [$checkIn, $checkOut, $nights] = $this->parseStay($input['check_in'], $input['check_out']);
        if ($nights < 1) {
            return ['text' => 'check_out must be after check_in.', 'ui' => null];
        }

        $units = max(1, (int) ($input['units'] ?? 1));

        if (! $this->reservations->fitsOccupancy($unitType, (int) $input['adults'], (int) ($input['children'] ?? 0), $units)) {
            return ['text' => 'This household does not fit that unit type for the requested number of units. Suggest a unit with more bedrooms or more units.', 'ui' => null];
        }

        $booking = $this->reservations->createRequest($this->apartment, $unitType, [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => (int) $input['adults'],
            'children' => (int) ($input['children'] ?? 0),
            'units' => $units,
            'extra_bed' => (bool) ($input['extra_bed'] ?? false),
            'guest_name' => $input['guest_name'],
            'guest_email' => $input['guest_email'] ?? null,
            'guest_phone' => $input['guest_phone'],
            'locale' => $this->locale,
            'notes' => $input['notes'] ?? null,
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
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => $nights,
            'units' => $units,
            'total_price' => (float) $booking->total_price,
            'currency' => $this->apartment->currency,
            'status' => 'pending_confirmation',
        ];

        return [
            'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'booking_confirmation', 'booking' => $payload],
        ];
    }

    private function requestHumanHandover(array $input): array
    {
        $reason = $input['reason'];
        $summary = $input['summary'];

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
        ]);

        return [
            'text' => json_encode([
                'ok' => true,
                'message' => 'A staff member has been notified and will join this conversation shortly.',
            ], JSON_UNESCAPED_UNICODE),
            'ui' => ['type' => 'handover', 'reason' => $reason, 'summary' => $summary],
        ];
    }

    // --- helpers ---

    private function findUnitType(string $slug): ?UnitType
    {
        return $this->apartment->unitTypes()->where('slug', $slug)->first();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    private function parseStay(string $checkIn, string $checkOut): array
    {
        $in = CarbonImmutable::parse($checkIn)->startOfDay();
        $out = CarbonImmutable::parse($checkOut)->startOfDay();

        return [$in, $out, $in->diffInDays($out)];
    }
}
