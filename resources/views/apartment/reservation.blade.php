@php
    $unitTypes = $unitTypes->filter(fn ($unitType) => $unitType->isForRent())->values();
    $rentalTypes = array_values(array_filter([
        $unitTypes->contains(fn ($unitType) => $unitType->offersMonthlyRental()) ? 'monthly' : null,
        $unitTypes->contains(fn ($unitType) => $unitType->offersYearlyRental()) ? 'yearly' : null,
    ]));
    $priceText = fn ($value, string $per) => trim($labels['from'].' '.$apartment->currency.' '.number_format((float) $value, 0, ',', '.').' '.$per);
    $viewingUnits = $unitTypes;
    $canApply = $rentalTypes !== [];
@endphp
<section class="lobby-content wizard-panel stage-panel-right @container" aria-label="{{ $wizard['title'] }}">
    <a href="{{ route('apartment.show', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}" data-stage-exit data-tour-line="{{ $narration['tour_lobby'] }}" class="panel-close" aria-label="{{ $lobby['back'] }}"><span aria-hidden="true">×</span></a>
    <p class="lobby-eyebrow">{{ $lobby['floor'] }} {{ $floor }} · {{ $apartment->name }}</p>
    <h2>{{ $wizard['title'] }}</h2>

    <div class="wizard" data-wizard
        data-quote-url="{{ route('reservation.quote', $apartment->slug) }}"
        data-availability-url="{{ route('reservation.availability', $apartment->slug) }}"
        data-submit-url="{{ route('reservation.store', $apartment->slug) }}"
        data-viewing-url="{{ route('reservation.viewing', $apartment->slug) }}"
        data-apartment-slug="{{ $apartment->slug }}"
        data-locale="{{ $locale }}"
        data-currency="{{ $apartment->currency }}"
        data-today="{{ $today }}"
        data-min-months="{{ max(1, $apartment->min_monthly_lease_months) }}"
        data-max-months="{{ \App\Models\Apartment::MAX_MONTHLY_LEASE_MONTHS }}"
        data-max-years="{{ \App\Models\Apartment::MAX_YEARLY_LEASE_YEARS }}"
        data-preselect-unit="{{ $preselectedUnit ?? '' }}"
    >
        <div data-wizard-flow>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-stone-600">{{ $wizard['intro'] }}</p>

            <details class="wizard-viewing" data-viewing{{ ($viewingRequested ?? false) ? ' open' : '' }}>
                <summary>{{ $wizard['viewing_title'] }}</summary>
                <p class="wizard-hint">{{ $wizard['viewing_intro'] }}</p>
                <form data-viewing-form novalidate>
                    <label class="wizard-field wizard-field-wide">
                        <span>{{ $wizard['choose_unit'] }}</span>
                        <select name="unit_type_slug" required>
                            <option value="">—</option>
                            @foreach ($viewingUnits as $unitType)
                                <option value="{{ $unitType->slug }}">{{ $unitType->translatedName($locale) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="wizard-field"><span>{{ $wizard['viewing_date'] }}</span><input type="date" name="preferred_date" required></label>
                    <label class="wizard-field"><span>{{ $wizard['viewing_time'] }}</span><input type="time" name="preferred_time" value="10:00" required></label>
                    <label class="wizard-field wizard-field-wide"><span>{{ $wizard['name'] }}</span><input type="text" name="visitor_name" maxlength="100" autocomplete="name" required></label>
                    <fieldset class="wizard-field-wide">
                        <legend>{{ $wizard['contact_method'] }}</legend>
                        <div class="wizard-pills">
                            @foreach (['whatsapp', 'phone', 'email'] as $method)
                                <label><input type="radio" name="contact_type" value="{{ $method }}" @checked($method === 'whatsapp')><span>{{ $wizard[$method] }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="wizard-field wizard-field-wide"><span>{{ $wizard['contact_value'] }}</span><input type="text" name="contact_value" maxlength="120" required></label>
                    <label class="wizard-field wizard-field-wide"><span>{{ $wizard['special'] }}</span><textarea name="notes" rows="2" maxlength="500"></textarea></label>
                    <p class="wizard-hint wizard-viewing-message" data-viewing-message role="status" hidden></p>
                    <div class="wizard-actions"><button type="submit" class="lobby-action">{{ $wizard['viewing_submit'] }}</button></div>
                </form>
            </details>

            <details class="wizard-viewing" data-documents>
                <summary>{{ $wizard['docs_title'] }}</summary>
                <ul class="wizard-docs">
                    <li>{{ $wizard['docs_individual'] }}</li>
                    <li>{{ $wizard['docs_foreign'] }}</li>
                    <li>{{ $wizard['docs_company'] }}</li>
                </ul>
                <p class="wizard-hint">{{ $wizard['docs_note'] }} <a href="{{ route('apartment.lease-sample', ['apartmentSlug' => $apartment->slug, 'lang' => $locale]) }}" target="_blank" rel="noopener" class="underline">{{ $wizard['sample_link'] }}</a></p>
            </details>


            @unless ($canApply)
                <p class="wizard-hint mt-4" role="status">{{ $wizard['no_units'] }}</p>
            @endunless

            <div class="wizard-progress" aria-label="{{ $wizard['title'] }}" @unless ($canApply) hidden @endunless>
                <p class="wizard-step-label" data-wizard-step-label aria-live="polite"></p>
                <ol>
                    @foreach ($wizard['steps'] as $stepLabel)
                        <li data-progress-step><span>{{ $stepLabel }}</span></li>
                    @endforeach
                </ol>
            </div>

            <form data-wizard-form novalidate autocomplete="on" @unless ($canApply) hidden @endunless>
                <div data-step="1" class="wizard-step wizard-step-wide">
                    <input type="hidden" name="check_in" required>

                    @unless ($canApply)
                        <input type="hidden" name="rental_type" value="monthly">
                    @endunless
                    <fieldset class="wizard-rental">
                        <legend>{{ $wizard['rental_title'] }}</legend>
                        <div class="wizard-pills">
                            @foreach ($rentalTypes as $rentalType)
                                <label><input type="radio" name="rental_type" value="{{ $rentalType }}" @checked($loop->first)><span>{{ $wizard['rental_'.$rentalType] }}</span></label>
                            @endforeach
                        </div>
                        <p class="wizard-hint" data-rental-hint aria-live="polite"></p>
                    </fieldset>

                    <div class="wizard-dates" data-date-picker>
                        <div class="wizard-slots">
                            <div class="wizard-slot" data-slot="move_in"><span>{{ $wizard['move_in'] }}</span><strong data-slot-value>{{ $wizard['cal_choose'] }}</strong></div>
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
                            <label class="wizard-field wizard-term">
                                <span>{{ $wizard['term'] }}</span>
                                <select name="lease_months"></select>
                            </label>
                            <p class="wizard-hint" data-lease-hint aria-live="polite"></p>
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
                    @if ($preselectedRoom ?? null)
                        <p class="wizard-room" data-preferred-unit data-unit-type="{{ $preselectedRoom->unitType->slug }}">
                            <input type="hidden" name="preferred_unit_number" value="{{ $preselectedRoom->unit_number }}">
                            <strong>{{ $wizard['preferred_unit'] }}: {{ $preselectedRoom->unit_number }}</strong>
                            <span>{{ str_replace([':floor', ':direction'], [$preselectedRoom->floor === 0 ? 'G' : $preselectedRoom->floor, $preselectedRoom->facing ? $lobby['facing_'.$preselectedRoom->facing] : '—'], $wizard['preferred_unit_hint']) }}</span>
                        </p>
                    @endif
                    <fieldset>
                        <legend>{{ $wizard['choose_unit'] }}</legend>
                        <div class="wizard-units">
                            @foreach ($unitTypes as $unitType)
                                <label class="wizard-unit" data-unit-option
                                    data-name="{{ $unitType->translatedName($locale) }}"
                                    data-max-adults="{{ $unitType->max_adults }}"
                                    data-max-occupancy="{{ $unitType->maxOccupancy() }}"
                                    data-price-monthly="{{ $unitType->offersMonthlyRental() ? $priceText($unitType->monthly_price, $labels['per_month']) : '' }}"
                                    data-price-yearly="{{ $unitType->offersYearlyRental() ? $priceText($unitType->yearly_price, $labels['per_year']) : '' }}"
                                >
                                    <input type="radio" name="unit_type_slug" value="{{ $unitType->slug }}">
                                    <span class="wizard-unit-body">
                                        <strong>{{ $unitType->translatedName($locale) }}</strong>
                                        <span>{{ str_replace(':count', (string) $unitType->maxOccupancy(), $wizard['fits']) }}</span>
                                        <span class="wizard-unit-price" data-unit-price></span>
                                        <span class="wizard-unit-hint" data-unit-hint></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
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
            <p class="mt-3 max-w-md text-sm leading-relaxed text-stone-600">{{ $wizard['docs_individual'] }} {{ $wizard['docs_foreign'] }} {{ $wizard['docs_note'] }}</p>
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
