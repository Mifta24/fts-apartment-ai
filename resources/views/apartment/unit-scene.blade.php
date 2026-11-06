@php
    $unitName = $unitType->translatedName($locale);
    $money = fn ($value) => $apartment->currency."\u{00A0}".number_format((float) $value, 0, ',', '.');
    $beds = collect($unitType->bed_config ?? [])
        ->map(fn ($bed) => $bed['count'].' × '.($unitTerms['bed'][$bed['type']] ?? Str::headline($bed['type'])))
        ->implode(', ');
    $layout = match (true) {
        ! $unitType->isResidential() => $lobby['cat_'.$unitType->category],
        $unitType->isStudio() => $lobby['studio'],
        default => str_replace([':count', ':baths'], [$unitType->bedrooms, $unitType->bathrooms], $lobby['bedrooms']),
    };
    $primaryImage = $unitType->images->first();
    $unitUrl = fn ($unit) => route('apartment.unit', ['apartmentSlug' => $apartment->slug, 'unitSlug' => $unit->slug, 'lang' => $locale]);
@endphp
<section class="lobby-content @container" aria-label="{{ $unitName }}">
    <a href="{{ route('apartment.units', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}" data-stage-exit class="panel-close" aria-label="{{ $labels['units_heading'] }}"><span aria-hidden="true">×</span></a>
    <article class="unit-scene">
        <div class="flex flex-wrap items-center justify-between gap-3 pr-12">
            <p class="lobby-eyebrow">{{ $lobby['unit_counter'] }} {{ str_pad((string) ($unitIndex + 1), 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) $unitTypes->count(), 2, '0', STR_PAD_LEFT) }}</p>
            @if ($unitTypes->count() > 1)
                <nav class="flex gap-2" aria-label="{{ $lobby['unit_scene'] }}">
                    <a href="{{ $unitUrl($previousUnit) }}" data-stage-exit class="unit-scene-step" rel="prev"><span aria-hidden="true">←</span> {{ $lobby['prev_unit'] }}</a>
                    <a href="{{ $unitUrl($nextUnit) }}" data-stage-exit class="unit-scene-step" rel="next">{{ $lobby['next_unit'] }} <span aria-hidden="true">→</span></a>
                </nav>
            @endif
        </div>

        <div class="mt-4 grid gap-7 @2xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div data-unit-gallery>
                <div class="unit-scene-photo">
                    @if ($primaryImage)
                        <img src="{{ $primaryImage->image_source }}" alt="{{ $primaryImage->alt_text }}" data-unit-gallery-main>
                    @else
                        <div class="grid h-full place-items-center text-sm">{{ $lobby['no_photo'] }}</div>
                    @endif
                    <span class="unit-scene-badge">{{ $layout }}</span>
                </div>
                @if ($unitType->images->count() > 1)
                    <ul class="mt-2 grid grid-cols-4 gap-2" aria-label="{{ $lobby['gallery'] }}">
                        @foreach ($unitType->images as $imageIndex => $image)
                            <li>
                                <button type="button" class="unit-scene-thumb" data-unit-thumb data-src="{{ $image->image_source }}" data-alt="{{ $image->alt_text }}" aria-label="{{ $lobby['photo'] }} {{ $imageIndex + 1 }}" @if($imageIndex === 0) aria-current="true" @endif>
                                    <img src="{{ $image->image_source }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @include('apartment.narrator', ['text' => $unitNarrations['unit'][$unitType->slug], 'key' => 'unit-'.$unitType->slug])
            </div>

            <div class="min-w-0">
                <h2 class="mt-0!">{{ $unitName }}</h2>
                <p class="unit-scene-description">{{ $unitType->translatedDescription($locale) }}</p>

                <div class="unit-scene-rate">
                    @if ($unitType->isForSale())
                        <p>
                            <span class="unit-scene-rate-label">{{ $lobby['sale_from'] }}</span>
                            @if ($unitType->offersSalePrice())
                                <span class="unit-scene-rate-amount"><strong>{{ $money($unitType->sale_price) }}</strong></span>
                            @else
                                <span class="unit-scene-rate-amount"><strong>{{ $lobby['for_sale'] }}</strong></span>
                            @endif
                        </p>
                        <p class="unit-scene-note">{{ $lobby['sale_note'] }}</p>
                    @endif
                    @if ($unitType->offersMonthlyRental())
                        <p class="unit-scene-monthly">{{ str_replace(':price', $money($unitType->monthly_price), $lobby['rent_monthly']) }}</p>
                    @endif
                    @if ($unitType->offersYearlyRental())
                        <p class="unit-scene-monthly">{{ str_replace([':price', ':monthly'], [$money($unitType->yearly_price), $money($unitType->yearlyMonthlyEquivalent())], $lobby['rent_yearly']) }}</p>
                    @endif
                    @if ($unitType->offersMonthlyRental() || $unitType->offersYearlyRental())
                        <p class="unit-scene-note">{{ str_replace([':months', ':min', ':max', ':years'], [$apartment->security_deposit_months, max(1, $apartment->min_monthly_lease_months), \App\Models\Apartment::MAX_MONTHLY_LEASE_MONTHS, \App\Models\Apartment::MAX_YEARLY_LEASE_YEARS], $lobby['lease_note']) }}</p>
                    @endif
                    @if ($unitType->isForRent())
                        <p class="unit-scene-note">{{ $lobby['availability_note'] }}</p>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    @if ($unitType->isForRent())
                        <a href="{{ route('apartment.reservation', ['apartmentSlug' => $apartment->slug, 'lang' => $locale, 'unit' => $unitType->slug]) }}" data-stage-exit data-tour-line="{{ $narration['tour_reservation'] }}" class="lobby-action">{{ $lobby['reserve_unit'] }} <span aria-hidden="true">↗</span></a>
                        <a href="{{ route('apartment.reservation', ['apartmentSlug' => $apartment->slug, 'lang' => $locale, 'unit' => $unitType->slug, 'viewing' => 1]) }}" data-stage-exit data-tour-line="{{ $narration['tour_reservation'] }}" class="unit-scene-step">{{ $lobby['viewing_unit'] }}</a>
                    @endif
                    @if ($unitType->isForSale())
                        <button type="button" class="{{ $unitType->isForRent() ? 'unit-scene-step' : 'lobby-action' }}" data-hero-quick-message="{{ str_replace(':name', $unitName, $lobby['ask_sale_q']) }}">{{ $lobby['ask_sale'] }}</button>
                    @endif
                    <button type="button" class="unit-scene-step" data-hero-quick-message="{{ str_replace(':name', $unitName, $lobby['ask_unit_q']) }}">{{ $lobby['ask_unit'] }}</button>
                </div>

                <dl class="unit-scene-facts">
                    <div><dt>{{ $lobby['layout'] }}</dt><dd>{{ $layout }}</dd></div>
                    @if ($unitType->size_sqm)
                        <div><dt>{{ $lobby['size'] }}</dt><dd>{{ $unitType->size_sqm }} m²</dd></div>
                    @endif
                    @if ($unitType->floor_range)
                        <div><dt>{{ $lobby['floors'] }}</dt><dd>{{ $unitType->floor_range }}</dd></div>
                    @endif
                    <div><dt>{{ $lobby['guests'] }}</dt><dd>{{ $unitType->maxOccupancy() }} {{ $labels['max_guests'] }}</dd></div>
                    @if ($beds !== '')
                        <div><dt>{{ $lobby['bed'] }}</dt><dd>{{ $beds }}</dd></div>
                    @endif
                    @if ($unitType->view_type)
                        <div><dt>{{ $lobby['view'] }}</dt><dd>{{ $unitTerms['view'][$unitType->view_type] ?? Str::headline($unitType->view_type) }}</dd></div>
                    @endif
                </dl>

                @if ($unitType->isForRent())
                    <h3 class="unit-scene-subheading">{{ $lobby['units_free_title'] }}</h3>
                    @if ($listedUnits->isEmpty())
                        <p class="unit-scene-note">{{ $lobby['units_free_none'] }}</p>
                    @else
                        <ul class="unit-scene-rooms">
                            @foreach ($listedUnits as $room)
                                @php $freeFrom = $room->freeFrom($today); @endphp
                                <li>
                                    <a href="{{ route('apartment.reservation', ['apartmentSlug' => $apartment->slug, 'lang' => $locale, 'unit' => $unitType->slug, 'room' => $room->unit_number]) }}" data-stage-exit data-tour-line="{{ $narration['tour_reservation'] }}" aria-label="{{ $lobby['unit_pick'] }}: {{ $lobby['unit_no'] }} {{ $room->unit_number }}">
                                        <strong>{{ $lobby['unit_no'] }} {{ $room->unit_number }}</strong>
                                        <span>{{ str_replace(':floor', $room->floor === 0 ? 'G' : $room->floor, $lobby['unit_floor']) }}@if ($room->facing) · {{ str_replace(':direction', $lobby['facing_'.$room->facing], $lobby['unit_facing']) }}@endif</span>
                                        <span class="{{ $freeFrom ? 'is-later' : 'is-now' }}">{{ $freeFrom ? str_replace(':date', $freeFrom->copy()->locale($locale)->translatedFormat('j M Y'), $lobby['unit_free_from']) : $lobby['unit_ready'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif

                @if (! empty($unitType->amenities))
                    <h3 class="unit-scene-subheading">{{ $lobby['amenities'] }}</h3>
                    <ul class="unit-scene-amenities">
                        @foreach ($unitType->amenities as $amenity)
                            <li>{{ $unitTerms['amenity'][$amenity] ?? Str::headline($amenity) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </article>
</section>
