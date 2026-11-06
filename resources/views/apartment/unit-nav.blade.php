{{-- The units and properties directory board, grouped into residences, offices and commercial, and units for sale: every unit type with its layout, floors and monthly rent, so the guest can step between units without going back. --}}
<aside class="unit-directory" aria-label="{{ $labels['units_heading'] }}">
    <p class="unit-directory-title"><span>{{ $labels['units_heading'] }}</span><span>{{ $unitTypes->count() }}</span></p>
    @php
        $sections = $unitTypes->groupBy(fn ($item) => match (true) {
            ! $item->isForRent() => 'sale',
            $item->isResidential() => 'residential',
            default => 'commercial',
        });
    @endphp
    <div class="unit-directory-scroll">
    @foreach (['residential', 'commercial', 'sale'] as $section)
        @continue (! $sections->has($section))
        <p class="unit-directory-section"><span>{{ $lobby['section_'.$section] }}</span><span>{{ $sections[$section]->count() }}</span></p>
        <nav class="unit-nav" aria-label="{{ $lobby['section_'.$section] }}">
        @foreach ($sections[$section] as $item)
            <a href="{{ route('apartment.unit', ['apartmentSlug' => $apartment->slug, 'unitSlug' => $item->slug, 'lang' => $locale]) }}"
                data-stage-exit
                @if (($unitType ?? null)?->slug === $item->slug) aria-current="page" @endif>
                @if ($item->images->first())
                    <img class="unit-nav-thumb" src="{{ $item->images->first()->image_source }}" alt="" loading="lazy">
                @endif
                <span class="unit-nav-text">
                    <span class="unit-nav-layout">{{ match (true) {
                        ! $item->isResidential() => $lobby['cat_'.$item->category],
                        $item->isStudio() => $lobby['studio'],
                        default => str_replace([':count', ':baths'], [$item->bedrooms, $item->bathrooms], $lobby['bedrooms']),
                    } }}</span>
                    <span class="unit-nav-name-row">
                        <span class="unit-nav-name">{{ $item->translatedName($locale) }}</span>
                        @if ($item->size_sqm)<span class="unit-nav-size">{{ $item->size_sqm }}&nbsp;m²</span>@endif
                    </span>
                    <span class="unit-nav-price">
                        @if ($item->offersMonthlyRental())
                            {{ str_replace(':price', $apartment->currency."\u{00A0}".number_format((float) $item->monthly_price, 0, ',', '.'), $lobby['rent_from_monthly']) }}
                        @elseif ($item->offersSalePrice())
                            {{ str_replace(':price', $apartment->currency."\u{00A0}".number_format((float) $item->sale_price, 0, ',', '.'), $lobby['sale_price']) }}
                        @else
                            {{ $item->isForSale() ? $lobby['for_sale'] : '' }}
                        @endif
                    </span>
                </span>
                <span class="unit-nav-go" aria-hidden="true">→</span>
            </a>
        @endforeach
        </nav>
    @endforeach
    </div>
</aside>
