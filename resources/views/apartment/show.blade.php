@php
    $money = fn ($value) => $apartment->currency.' '.number_format((float) $value, 0, ',', '.');
    $unitsItem = collect($menuItems)->firstWhere('key', 'units');
    $reservationItem = collect($menuItems)->firstWhere('key', 'reservation');
@endphp
<x-apartment-stage :apartment="$apartment" :locale="$locale" :supported-locales="$supportedLocales" :labels="$labels" :lobby="$lobby" :narration="$narration" :scene="$scene" :floor="$floor" :backdrop="$backdrop" :menu-items="$menuItems">
    <x-slot:welcome>
        <div class="stage-welcome">
            <span class="lobby-eyebrow">{{ $apartment->name }} · {{ $apartment->city }}</span>
            <h1>{{ $lobby['welcome'] }} <em>{{ $lobby['lobby'] }}.</em></h1>
            <p>{{ $lobby['intro'] }}</p>

            @if ($unitTypes->isNotEmpty())
                <div class="lobby-cta">
                    <a href="{{ $unitsItem['href'] }}" class="lobby-action lobby-action-signal" data-stage-exit data-tour-line="{{ $unitsItem['tour'] }}" data-topic="{{ $unitsItem['topic'] }}">{{ $lobby['cta_units'] }} <span aria-hidden="true">→</span></a>
                    <a href="{{ $reservationItem['href'] }}" class="lobby-action-ghost" data-stage-exit data-tour-line="{{ $reservationItem['tour'] }}" data-topic="{{ $reservationItem['topic'] }}">{{ $lobby['start_booking'] }}</a>
                </div>
            @endif

            <dl class="lobby-stats">
                @if ($unitTypes->isNotEmpty())
                    <div><dt>{{ $lobby['stat_from'] }}</dt><dd>{{ $money($unitTypes->min('base_price')) }}</dd></div>
                @endif
                @if ($unitTypes->contains(fn ($unitType) => $unitType->offersMonthlyRental()))
                    <div><dt>{{ $lobby['stat_rent_monthly'] }}</dt><dd>{{ $money($unitTypes->filter(fn ($unitType) => $unitType->offersMonthlyRental())->min('monthly_price')) }}</dd></div>
                @elseif ($apartment->monthly_discount_percent > 0)
                    <div><dt>{{ $lobby['stat_monthly'] }}</dt><dd>{{ str_replace(':percent', (string) $apartment->monthly_discount_percent, $lobby['stat_monthly_value']) }}</dd></div>
                @endif
                @if ($apartment->check_in_time)
                    <div><dt>{{ $lobby['stat_checkin'] }}</dt><dd>{{ substr($apartment->check_in_time, 0, 5) }}</dd></div>
                @endif
            </dl>
        </div>
    </x-slot:welcome>
</x-apartment-stage>
