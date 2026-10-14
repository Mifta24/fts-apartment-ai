@php
    $unitTypes = $unitTypes->filter(fn ($unitType) => $unitType->isForRent())->values();
    $rentalTypes = array_values(array_filter([
        $unitTypes->contains(fn ($unitType) => $unitType->offersDailyRental()) ? 'daily' : null,
        $unitTypes->contains(fn ($unitType) => $unitType->offersMonthlyRental()) ? 'monthly' : null,
        $unitTypes->contains(fn ($unitType) => $unitType->offersYearlyRental()) ? 'yearly' : null,
    ]));
    $priceText = fn ($value, string $per) => trim($labels['from'].' '.$apartment->currency.' '.number_format((float) $value, 0, ',', '.').' '.$per);
@endphp
<section class="lobby-content wizard-panel stage-panel-right @container" aria-label="{{ $wizard['title'] }}">
    <a href="{{ route('apartment.show', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}" data-stage-exit data-tour-line="{{ $narration['tour_lobby'] }}" class="panel-close" aria-label="{{ $lobby['back'] }}"><span aria-hidden="true">×</span></a>
    <p class="lobby-eyebrow">{{ $lobby['floor'] }} {{ $floor }} · {{ $apartment->name }}</p>
    <h2>{{ $wizard['title'] }}</h2>

    <div class="wizard" data-wizard
        data-quote-url="{{ route('reservation.quote', $apartment->slug) }}"
        data-availability-url="{{ route('reservation.availability', $apartment->slug) }}"
        data-submit-url="{{ route('reservation.store', $apartment->slug) }}"
        data-apartment-slug="{{ $apartment->slug }}"
        data-locale="{{ $locale }}"
        data-currency="{{ $apartment->currency }}"
        data-today="{{ $today }}"
        data-max-nights="{{ \App\Services\Reservation\ReservationService::MAX_NIGHTS }}"
        data-min-months="{{ max(1, $apartment->min_monthly_lease_months) }}"
        data-max-months="{{ \App\Models\Apartment::MAX_MONTHLY_LEASE_MONTHS }}"
        data-max-years="{{ \App\Models\Apartment::MAX_YEARLY_LEASE_YEARS }}"
        data-preselect-unit="{{ $preselectedUnit ?? '' }}"
    >
        <div data-wizard-flow>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-stone-600">{{ $wizard['intro'] }}</p>

            <div class="wizard-progress" aria-label="{{ $wizard['title'] }}">
                <p class="wizard-step-label" data-wizard-step-label aria-live="polite"></p>
                <ol>
                    @foreach ($wizard['steps'] as $stepLabel)
                        <li data-progress-step><span>{{ $stepLabel }}</span></li>
                    @endforeach
                </ol>
            </div>

            <form data-wizard-form novalidate autocomplete="on">
                <div data-step="1" class="wizard-step wizard-step-wide">
                    <input type="hidden" name="check_in" required>
                    <input type="hidden" name="check_out">

                    @if (count($rentalTypes) > 1 || ($rentalTypes[0] ?? 'daily') !== 'daily')
                        <fieldset class="wizard-rental">
                            <legend>{{ $wizard['rental_title'] }}</legend>
                            <div class="wizard-pills">
                                @foreach ($rentalTypes as $rentalType)
                                    <label><input type="radio" name="rental_type" value="{{ $rentalType }}" @checked($loop->first)><span>{{ $wizard['rental_'.$rentalType] }}</span></label>
                                @endforeach
                            </div>
                            <p class="wizard-hint" data-rental-hint aria-live="polite"></p>
                        </fieldset>
                    @else
                        <input type="hidden" name="rental_type" value="daily">
                    @endif

                    <div class="wizard-dates" data-date-picker>
                        <div class="wizard-slots">
                            <div class="wizard-slot" data-slot="check_in"><span>{{ $wizard['check_in'] }}</span><strong data-slot-value>{{ $wizard['cal_choose'] }}</strong></div>
                            <div class="wizard-slot" data-slot="check_out"><span>{{ $wizard['check_out'] }}</span><strong data-slot-value>{{ $wizard['cal_choose'] }}</strong></div>
                        </div>
                        <div class="wizard-cal">
                            <div class="wizard-cal-head">
                                <button type="button" data-cal-prev aria-label="{{ $wizard['cal_prev'] }}">←</button>
                                <strong data-cal-title aria-live="polite"></strong>
                                <button type="button" data-cal-next aria-label="{{ $wizard['cal_next'] }}">→</button>
                            </div>
                            <div class="wizard-cal-week" data-cal-weekdays aria-hidden="true"></div>
                            <div class="wizard-cal-grid" data-cal-grid></div>
                        </div>
                        <div class="wizard-dates-extra">
                            <label class="wizard-field wizard-term" data-term-field hidden>
                                <span>{{ $wizard['term'] }}</span>
                                <select name="lease_months"></select>
                            </label>
                            <p class="wizard-hint" data-nights-hint aria-live="polite"></p>
                            @if ($wizard['long_stay_hint'])
                                <p class="wizard-long-stay"><span aria-hidden="true">%</span>{{ $wizard['long_stay_hint'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                <div data-step="2" class="wizard-step" hidden>
                    <label class="wizard-field">
                        <span>{{ $wizard['adults'] }}</span>
                        <input type="number" name="adults" min="1" max="20" value="2" inputmode="numeric" required>
                    </label>
                    <label class="wizard-field">
                        <span>{{ $wizard['children'] }}</span>
                        <input type="number" name="children" min="0" max="10" value="0" inputmode="numeric">
                    </label>
                    <label class="wizard-field">
                        <span>{{ $wizard['units'] }}</span>
                        <input type="number" name="units" min="1" max="10" value="1" inputmode="numeric" required>
                    </label>
                </div>

                <div data-step="3" class="wizard-step wizard-step-wide" hidden>
                    <fieldset>
                        <legend>{{ $wizard['choose_unit'] }}</legend>
                        <div class="wizard-units">
                            @foreach ($unitTypes as $unitType)
                                <label class="wizard-unit" data-unit-option
                                    data-name="{{ $unitType->translatedName($locale) }}"
                                    data-max-adults="{{ $unitType->max_adults }}"
                                    data-max-occupancy="{{ $unitType->maxOccupancy() }}"
                                    data-extra-bed="{{ $unitType->extra_bed_available ? 1 : 0 }}"
                                    data-price-daily="{{ $unitType->offersDailyRental() ? $priceText($unitType->base_price, $labels['per_night']) : '' }}"
                                    data-price-monthly="{{ $unitType->offersMonthlyRental() ? $priceText($unitType->monthly_price, $labels['per_month']) : '' }}"
                                    data-price-yearly="{{ $unitType->offersYearlyRental() ? $priceText($unitType->yearly_price, $labels['per_year']) : '' }}"
                                >
                                    <input type="radio" name="unit_type_slug" value="{{ $unitType->slug }}">
                                    <span class="wizard-unit-body">
                                        <strong>{{ $unitType->translatedName($locale) }}</strong>
                                        <span>{{ str_replace(':count', (string) $unitType->maxOccupancy(), $wizard['fits']) }}</span>
                                        <span class="wizard-unit-price" data-unit-price>{{ $unitType->offersDailyRental() ? $priceText($unitType->base_price, $labels['per_night']) : '' }}</span>
                                        <span class="wizard-unit-hint" data-unit-hint></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="wizard-check" data-extra-bed-field hidden>
                        <input type="checkbox" name="extra_bed" value="1">
                        <span>{{ $wizard['extra_bed'] }}</span>
                    </label>
                </div>

                <div data-step="4" class="wizard-step" hidden>
                    <label class="wizard-field wizard-field-wide">
                        <span>{{ $wizard['name'] }}</span>
                        <input type="text" name="guest_name" maxlength="100" autocomplete="name" required>
                    </label>
                    <fieldset class="wizard-field-wide">
                        <legend>{{ $wizard['contact_method'] }}</legend>
                        <div class="wizard-pills">
                            @foreach (['whatsapp', 'phone', 'email'] as $method)
                                <label><input type="radio" name="contact_type" value="{{ $method }}" @checked($method === 'whatsapp')><span>{{ $wizard[$method] }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="wizard-field wizard-field-wide">
                        <span>{{ $wizard['contact_value'] }}</span>
                        <input type="text" name="contact_value" maxlength="120" autocomplete="tel" required>
                    </label>
                    <label class="wizard-field wizard-field-wide">
                        <span>{{ $wizard['special'] }}</span>
                        <textarea name="special_request" rows="2" maxlength="500" placeholder="{{ $wizard['special_placeholder'] }}"></textarea>
                    </label>
                </div>

                <div data-step="5" class="wizard-step wizard-step-wide" hidden>
                    <dl class="wizard-summary" data-summary></dl>
                    <p class="wizard-discount" data-summary-discount hidden><span>{{ $wizard['discount'] }} <b data-summary-discount-percent></b></span> <strong data-summary-discount-amount></strong></p>
                    <p class="wizard-total"><span>{{ $wizard['estimated_total'] }}</span> <strong data-summary-total></strong></p>
                    <p class="wizard-deposit" data-summary-deposit hidden><span>{{ $wizard['deposit'] }}</span> <strong data-summary-deposit-amount></strong></p>
                    <p class="wizard-hint" data-summary-deposit-hint hidden>{{ $wizard['deposit_hint'] }}</p>
                    <p class="wizard-hint">{{ $wizard['disclaimer'] }}</p>
                </div>

                <div class="wizard-error" data-wizard-error role="alert" hidden>
                    <p data-wizard-error-text></p>
                    <div class="wizard-alternatives" data-wizard-alternatives hidden>
                        <p>{{ $wizard['available_instead'] }}</p>
                        <ul></ul>
                    </div>
                </div>

                <div class="wizard-actions">
                    <button type="button" class="wizard-secondary" data-wizard-back hidden>{{ $wizard['back'] }}</button>
                    <button type="button" class="lobby-action" data-wizard-next>{{ $wizard['next'] }} <span aria-hidden="true">→</span></button>
                    <button type="submit" class="lobby-action" data-wizard-submit hidden>{{ $wizard['submit'] }}</button>
                </div>
            </form>
        </div>

        <div class="wizard-done" data-wizard-done hidden>
            <p class="lobby-eyebrow">{{ $wizard['done_title'] }}</p>
            <p class="wizard-reference-label">{{ $wizard['reference'] }}</p>
            <p class="wizard-reference" data-done-reference></p>
            <p class="wizard-status">{{ $wizard['awaiting'] }}</p>
            <p class="mt-4 max-w-md text-sm leading-relaxed text-stone-600">{{ $wizard['done_hint'] }}</p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a class="lobby-action" data-done-whatsapp target="_blank" rel="noopener" hidden>{{ $wizard['send_whatsapp'] }} <span aria-hidden="true">↗</span></a>
                <a class="wizard-secondary" data-done-phone hidden>{{ $wizard['call_apartment'] }}</a>
                <a class="wizard-secondary" data-done-email hidden>{{ $wizard['email_apartment'] }}</a>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('apartment.show', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}" data-stage-exit class="wizard-secondary">{{ $lobby['back'] }}</a>
                <button type="button" class="wizard-secondary" data-wizard-reset>{{ $wizard['new_request'] }}</button>
            </div>
        </div>

        <script type="application/json" data-wizard-labels>@json($wizard)</script>
    </div>
</section>
