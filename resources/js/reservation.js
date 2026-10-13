import { createDatePicker } from './date-picker';

/**
 * Guided stay request wizard: five short steps on the leasing floor — dates,
 * residents, unit, contact, summary — for a nightly stay, a monthly lease or a
 * yearly lease (a move-in date plus a term instead of a date range), then a reference number and
 * WhatsApp / phone / email hand-over. Progress survives leaving the page and
 * switching language (sessionStorage), and every rule is enforced again on the
 * server, which is the only source of price and availability.
 */
function initReservationWizard() {
    const root = document.querySelector('[data-wizard]');
    if (!root) return;

    const labels = JSON.parse(root.querySelector('[data-wizard-labels]').textContent);
    const form = root.querySelector('[data-wizard-form]');
    const flow = root.querySelector('[data-wizard-flow]');
    const done = root.querySelector('[data-wizard-done]');
    const steps = [...root.querySelectorAll('[data-step]')];
    const progress = [...root.querySelectorAll('[data-progress-step]')];
    const stepLabel = root.querySelector('[data-wizard-step-label]');
    const nightsHint = root.querySelector('[data-nights-hint]');
    const backButton = root.querySelector('[data-wizard-back]');
    const nextButton = root.querySelector('[data-wizard-next]');
    const submitButton = root.querySelector('[data-wizard-submit]');
    const errorBox = root.querySelector('[data-wizard-error]');
    const errorText = root.querySelector('[data-wizard-error-text]');
    const alternativesBox = root.querySelector('[data-wizard-alternatives]');
    const unitOptions = [...root.querySelectorAll('[data-unit-option]')];
    const extraBedField = root.querySelector('[data-extra-bed-field]');
    const termField = root.querySelector('[data-term-field]');
    const rentalHint = root.querySelector('[data-rental-hint]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    const config = {
        quoteUrl: root.dataset.quoteUrl,
        submitUrl: root.dataset.submitUrl,
        locale: root.dataset.locale,
        currency: root.dataset.currency,
        today: root.dataset.today,
        maxNights: Number(root.dataset.maxNights),
        minMonths: Number(root.dataset.minMonths) || 1,
        maxMonths: Number(root.dataset.maxMonths) || 11,
        maxYears: Number(root.dataset.maxYears) || 3,
        draftKey: `reservation_draft_${root.dataset.apartmentSlug}`,
        tokenKey: `concierge_token_${root.dataset.apartmentSlug}`,
    };

    const fieldStep = {
        rental_type: 1, lease_months: 1, check_in: 1, check_out: 1, adults: 2, children: 2, units: 2, unit_type_slug: 3, extra_bed: 3,
        guest_name: 4, contact_type: 4, contact_value: 4, special_request: 4,
    };

    let current = 1;
    let quote = null;
    let busy = false;

    const isLease = () => form.elements.rental_type.value !== 'daily';
    const text = (template, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, value), template);
    const money = (value) => `${config.currency} ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;

    function formatDate(iso) {
        const [year, month, day] = iso.split('-').map(Number);
        return new Intl.DateTimeFormat(config.locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(year, month - 1, day));
    }

    const datePicker = createDatePicker({
        root: root.querySelector('[data-date-picker]'),
        checkIn: form.elements.check_in,
        checkOut: form.elements.check_out,
        config,
        labels,
        formatDate,
        onChange: () => {
            clearError();
            form.dispatchEvent(new Event('input', { bubbles: true }));
        },
    });

    function nightsBetween(checkIn, checkOut) {
        if (!checkIn || !checkOut) return 0;
        const [y1, m1, d1] = checkIn.split('-').map(Number);
        const [y2, m2, d2] = checkOut.split('-').map(Number);
        return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86400000);
    }

    function values() {
        const data = new FormData(form);
        const rentalType = data.get('rental_type') || 'daily';

        return {
            rental_type: rentalType,
            lease_months: rentalType === 'daily' ? 0 : Number(data.get('lease_months')) || 0,
            check_in: data.get('check_in') || '',
            check_out: rentalType === 'daily' ? data.get('check_out') || '' : '',
            adults: Number(data.get('adults')) || 0,
            children: Number(data.get('children')) || 0,
            units: Number(data.get('units')) || 0,
            unit_type_slug: data.get('unit_type_slug') || '',
            extra_bed: form.elements.extra_bed.checked && !extraBedField.hidden,
            guest_name: (data.get('guest_name') || '').trim(),
            contact_type: data.get('contact_type') || 'whatsapp',
            contact_value: (data.get('contact_value') || '').trim(),
            special_request: (data.get('special_request') || '').trim(),
        };
    }

    function saveDraft() {
        try {
            sessionStorage.setItem(config.draftKey, JSON.stringify({ step: current, values: values() }));
        } catch { /* storage unavailable: the wizard still works without it */ }
    }

    function clearDraft() {
        try {
            sessionStorage.removeItem(config.draftKey);
        } catch { /* nothing to clear */ }
    }

    function restoreDraft() {
        try {
            const draft = JSON.parse(sessionStorage.getItem(config.draftKey) || 'null');
            if (!draft) return;
            const { values: saved } = draft;
            const savedPill = form.querySelector(`input[type="radio"][name="rental_type"][value="${saved.rental_type}"]`);
            if (savedPill) {
                savedPill.checked = true;
                applyRentalType();
                if (saved.lease_months) form.elements.lease_months.value = saved.lease_months;
            }
            ['check_in', 'check_out', 'adults', 'children', 'units', 'guest_name', 'contact_value', 'special_request'].forEach((name) => {
                if (saved[name] !== undefined && saved[name] !== '' && saved[name] !== 0) form.elements[name].value = saved[name];
            });
            if (saved.children === 0) form.elements.children.value = 0;
            if (saved.contact_type) form.elements.contact_type.value = saved.contact_type;
            if (saved.unit_type_slug) form.elements.unit_type_slug.value = saved.unit_type_slug;
            form.elements.extra_bed.checked = Boolean(saved.extra_bed);
            current = Math.min(Math.max(Number(draft.step) || 1, 1), steps.length);
        } catch { /* ignore a corrupt draft */ }
    }

    function clearError() {
        errorBox.hidden = true;
        errorText.textContent = '';
        alternativesBox.hidden = true;
        alternativesBox.querySelector('ul').replaceChildren();
    }

    function showError(message, alternatives = []) {
        errorText.textContent = message;
        errorBox.hidden = false;

        const list = alternativesBox.querySelector('ul');
        list.replaceChildren();
        alternatives.forEach((alternative) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'wizard-secondary';
            button.textContent = `${alternative.name} · ${money(alternative.total)}`;
            button.addEventListener('click', () => {
                form.elements.unit_type_slug.value = alternative.slug;
                refreshUnits();
                clearError();
                saveDraft();
            });
            item.appendChild(button);
            list.appendChild(item);
        });
        alternativesBox.hidden = alternatives.length === 0;
    }

    function selectedOption() {
        const slug = form.elements.unit_type_slug.value;
        return unitOptions.find((option) => option.querySelector('input').value === slug) || null;
    }

    const priceOf = (option, rentalType) => option.dataset[`price${rentalType[0].toUpperCase()}${rentalType.slice(1)}`] || '';

    function fits(option, party) {
        return party.adults <= Number(option.dataset.maxAdults) * party.units
            && party.adults + party.children <= Number(option.dataset.maxOccupancy) * party.units;
    }

    function refreshUnits() {
        const party = values();

        unitOptions.forEach((option) => {
            const input = option.querySelector('input');
            const offered = Boolean(priceOf(option, party.rental_type));
            const suitable = offered && fits(option, party);
            input.disabled = !suitable;
            option.classList.toggle('is-disabled', !suitable);
            option.querySelector('[data-unit-hint]').textContent = !offered ? labels.not_offered : (suitable ? '' : labels.too_small);
            option.querySelector('[data-unit-price]').textContent = priceOf(option, party.rental_type);
            if (!suitable && input.checked) input.checked = false;
        });

        const chosen = selectedOption();
        extraBedField.hidden = party.rental_type !== 'daily' || !chosen || chosen.dataset.extraBed !== '1';
        if (extraBedField.hidden) form.elements.extra_bed.checked = false;
    }

    const termLabel = (months, rentalType) => {
        const count = rentalType === 'yearly' ? months / 12 : months;
        return text(labels[`term_${rentalType === 'yearly' ? 'year' : 'month'}${count === 1 ? '' : 's'}`], { count });
    };

    /** The terms a lease can have: 1 to 11 months by the month, whole years by the year. */
    function termOptions(rentalType) {
        if (rentalType === 'yearly') return Array.from({ length: config.maxYears }, (_, index) => (index + 1) * 12);
        return Array.from({ length: config.maxMonths - config.minMonths + 1 }, (_, index) => config.minMonths + index);
    }

    /** Shows the picker, hint and term that fit the chosen kind of rental. */
    function applyRentalType() {
        const rentalType = form.elements.rental_type.value || 'daily';
        const lease = rentalType !== 'daily';
        const previous = Number(form.elements.lease_months.value) || 0;
        const options = lease ? termOptions(rentalType) : [];

        form.elements.lease_months.replaceChildren(...options.map((months) => {
            const option = document.createElement('option');
            option.value = months;
            option.textContent = termLabel(months, rentalType);
            return option;
        }));
        if (options.includes(previous)) form.elements.lease_months.value = previous;
        else if (lease) form.elements.lease_months.value = rentalType === 'yearly' ? 12 : Math.min(Math.max(config.minMonths, 6), config.maxMonths);

        termField.hidden = !lease;
        if (rentalHint) rentalHint.textContent = labels[`rental_hint_${rentalType}`] || '';
        datePicker.setSingle(lease);
    }

    function leaseEnd(checkIn, months) {
        const [year, month, day] = checkIn.split('-').map(Number);
        const target = new Date(Date.UTC(year, month - 1 + months, 1));
        const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
        return new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(day, last))).toISOString().slice(0, 10);
    }

    function refreshNights() {
        const checkIn = form.elements.check_in.value;
        const nights = nightsBetween(checkIn, form.elements.check_out.value);

        if (isLease()) {
            const months = Number(form.elements.lease_months.value) || 0;
            if (checkIn && months) nightsHint.textContent = text(labels.lease_ends, { date: formatDate(leaseEnd(checkIn, months)) });
            else if (!datePicker.hasOpenNights) nightsHint.textContent = labels.cal_none;
            else nightsHint.textContent = labels.cal_pick_in;
        } else if (nights > 0) nightsHint.textContent = `${nights} ${labels.nights}`;
        else if (!datePicker.hasOpenNights) nightsHint.textContent = labels.cal_none;
        else nightsHint.textContent = checkIn ? labels.cal_pick_out : labels.cal_pick_in;
    }

    function validate(step) {
        const data = values();

        if (step === 1) {
            if (!data.check_in) return { field: 'check_in', message: labels.cal_pick_in };
            if (data.check_in < config.today) return { field: 'check_in', message: labels.check_in_past };
            if (data.rental_type !== 'daily') {
                return termOptions(data.rental_type).includes(data.lease_months) ? null : { field: 'lease_months', message: labels.need_term };
            }
            if (!data.check_out) return { field: 'check_out', message: labels.cal_pick_out };
            if (data.check_out <= data.check_in) return { field: 'check_out', message: labels.check_out_after };
            if (nightsBetween(data.check_in, data.check_out) > config.maxNights) return { field: 'check_out', message: text(labels.too_long, { max: config.maxNights }) };
        }

        if (step === 2) {
            if (data.adults < 1) return { field: 'adults', message: labels.invalid };
            if (data.units < 1) return { field: 'units', message: labels.invalid };
        }

        if (step === 3) {
            const chosen = selectedOption();
            if (!chosen) return { field: 'unit_type_slug', message: labels.select_unit };
            if (!fits(chosen, data)) return { field: 'unit_type_slug', message: labels.too_small };
        }

        if (step === 4) {
            if (!data.guest_name) return { field: 'guest_name', message: labels.invalid };
            const valid = data.contact_type === 'email'
                ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.contact_value)
                : /^\+?[0-9\s\-().]{6,20}$/.test(data.contact_value);
            if (!valid) return { field: 'contact_value', message: data.contact_type === 'email' ? labels.contact_email : labels.contact_phone };
        }

        return null;
    }

    function setBusy(value, label = null) {
        busy = value;
        [backButton, nextButton, submitButton].forEach((button) => { button.disabled = value; });
        if (label) nextButton.firstChild.textContent = `${label} `;
        else nextButton.firstChild.textContent = `${labels.next} `;
    }

    function showStep(step, focus = false) {
        current = step;
        steps.forEach((element, index) => { element.hidden = index + 1 !== step; });
        progress.forEach((element, index) => {
            element.classList.toggle('is-current', index + 1 === step);
            element.classList.toggle('is-done', index + 1 < step);
            if (index + 1 === step) element.setAttribute('aria-current', 'step');
            else element.removeAttribute('aria-current');
        });
        stepLabel.textContent = `${text(labels.step_of, { current: step, total: steps.length })} · ${labels.steps[step - 1]}`;
        backButton.hidden = step === 1;
        nextButton.hidden = step === steps.length;
        submitButton.hidden = step !== steps.length;

        if (step === 2 || step === 3) refreshUnits();
        if (step === 3 && unitOptions.every((option) => option.classList.contains('is-disabled'))) showError(labels.capacity);
        if (step === steps.length) renderSummary();
        if (focus) steps[step - 1].querySelector('input:not([type="hidden"]):not([disabled]), textarea, [data-cal-focus]')?.focus({ preventScroll: true });
        saveDraft();
    }

    function goToField(field) {
        showStep(fieldStep[field] || current, true);
    }

    async function post(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken || '' },
            body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, body };
    }

    function stayPayload() {
        const { rental_type, lease_months, check_in, check_out, adults, children, units, unit_type_slug, extra_bed } = values();
        const lease = rental_type !== 'daily';
        return {
            rental_type, check_in, adults, children, units, unit_type_slug, locale: config.locale,
            ...(lease ? { lease_months } : { check_out, extra_bed }),
        };
    }

    function handleFailure(result) {
        if (result.status === 422 && result.body.errors) {
            const [field, messages] = Object.entries(result.body.errors)[0];
            showError(messages[0], result.body.alternatives || []);
            if (fieldStep[field] && fieldStep[field] !== current) showStep(fieldStep[field]);
            return;
        }
        showError(labels.error_generic);
    }

    async function next() {
        if (busy || current >= steps.length) return;
        clearError();

        const problem = validate(current);
        if (problem) {
            showError(problem.message);
            form.elements[problem.field]?.focus?.();
            return;
        }

        if (current === 3) {
            setBusy(true, labels.checking);
            try {
                const result = await post(config.quoteUrl, stayPayload());
                if (!result.ok) {
                    handleFailure(result);
                    return;
                }
                quote = result.body;
            } catch {
                showError(labels.error_network);
                return;
            } finally {
                setBusy(false);
            }
        }

        showStep(current + 1, true);
    }

    function summaryRow(label, value, step) {
        const wrapper = document.createElement('div');
        const term = document.createElement('dt');
        term.textContent = label;
        const detail = document.createElement('dd');
        const span = document.createElement('span');
        span.textContent = value;
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.textContent = labels.edit;
        edit.addEventListener('click', () => showStep(step, true));
        detail.append(span, edit);
        wrapper.append(term, detail);
        return wrapper;
    }

    function renderSummary() {
        const data = values();
        const chosen = selectedOption();
        const lease = data.rental_type !== 'daily';
        const nights = nightsBetween(data.check_in, data.check_out);
        const moveOut = lease ? (quote?.check_out || leaseEnd(data.check_in, data.lease_months)) : data.check_out;
        const guests = [`${data.adults} ${labels.adults.toLowerCase()}`];
        if (data.children > 0) guests.push(`${data.children} ${labels.children.toLowerCase()}`);

        const unit = `${chosen ? chosen.dataset.name : ''}${data.extra_bed ? ` + ${labels.extra_bed.toLowerCase()}` : ''}`;
        const rows = [
            ...(lease ? [summaryRow(labels[`rental_${data.rental_type}`], termLabel(data.lease_months, data.rental_type), 1)] : []),
            summaryRow(labels.dates, `${formatDate(data.check_in)} → ${formatDate(moveOut)}${lease ? '' : ` (${nights} ${labels.nights})`}`, 1),
            summaryRow(labels.guests, `${guests.join(', ')} · ${labels.units}: ${data.units}`, 2),
            summaryRow(labels.unit, unit, 3),
            ...(lease && quote ? [summaryRow(labels.rent, text(quote.rate_period === 'year' ? labels.rent_per_year : labels.rent_per_month, { price: money(quote.rate) }), 3)] : []),
            summaryRow(labels.contact, `${data.guest_name} · ${labels[data.contact_type]}: ${data.contact_value}`, 4),
        ];
        if (data.special_request) rows.push(summaryRow(labels.special, data.special_request, 4));

        root.querySelector('[data-summary]').replaceChildren(...rows);
        root.querySelector('[data-summary-total]').textContent = quote ? money(quote.grand_total) : '';

        const deposit = root.querySelector('[data-summary-deposit]');
        const hasDeposit = Boolean(quote && lease && quote.deposit_total > 0);
        deposit.hidden = !hasDeposit;
        root.querySelector('[data-summary-deposit-hint]').hidden = !hasDeposit;
        if (hasDeposit) deposit.querySelector('[data-summary-deposit-amount]').textContent = money(quote.deposit_total);

        const discount = root.querySelector('[data-summary-discount]');
        const hasDiscount = Boolean(quote && quote.discount_percent > 0);
        discount.hidden = !hasDiscount;
        if (hasDiscount) {
            discount.querySelector('[data-summary-discount-percent]').textContent = `−${quote.discount_percent}%`;
            discount.querySelector('[data-summary-discount-amount]').textContent = `−${money(quote.discount_total)}`;
        }
    }

    function showDone(result) {
        flow.hidden = true;
        done.hidden = false;
        root.querySelector('[data-done-reference]').textContent = result.reference;

        [['whatsapp', 'whatsapp_url'], ['phone', 'phone_url'], ['email', 'email_url']].forEach(([name, key]) => {
            const link = root.querySelector(`[data-done-${name}]`);
            const url = result.handover?.[key];
            link.hidden = !url;
            if (url) link.href = url;
        });
    }

    async function submit(event) {
        event.preventDefault();
        if (busy || current !== steps.length) return;
        clearError();

        const problem = [1, 2, 3, 4].map(validate).find(Boolean);
        if (problem) {
            showError(problem.message);
            goToField(problem.field);
            return;
        }

        setBusy(true);
        submitButton.textContent = labels.sending;

        try {
            const result = await post(config.submitUrl, {
                ...stayPayload(),
                guest_name: values().guest_name,
                contact_type: values().contact_type,
                contact_value: values().contact_value,
                special_request: values().special_request,
                guest_token: localStorage.getItem(config.tokenKey) || undefined,
            });

            if (!result.ok) {
                handleFailure(result);
                return;
            }

            clearDraft();
            showDone(result.body);
        } catch {
            showError(labels.error_network);
        } finally {
            setBusy(false);
            submitButton.textContent = labels.submit;
        }
    }

    function reset() {
        form.reset();
        applyRentalType();
        datePicker.clear();
        quote = null;
        clearError();
        done.hidden = true;
        flow.hidden = false;
        clearDraft();
        refreshNights();
        showStep(1, true);
    }

    function preselect(slug) {
        if (!flow.hidden) {
            refreshUnits();
            const option = unitOptions.find((candidate) => candidate.querySelector('input').value === slug);
            if (option && !option.querySelector('input').disabled) {
                form.elements.unit_type_slug.value = slug;
                refreshUnits();
                saveDraft();
            }
        }
    }

    form.addEventListener('input', () => { refreshNights(); if (current <= 3) refreshUnits(); saveDraft(); });
    form.addEventListener('change', (event) => {
        if (event.target.name === 'rental_type') {
            applyRentalType();
            clearError();
        }
        refreshNights();
        refreshUnits();
        saveDraft();
    });
    form.addEventListener('submit', submit);
    nextButton.addEventListener('click', next);
    backButton.addEventListener('click', () => { clearError(); showStep(Math.max(1, current - 1), true); });
    root.querySelector('[data-wizard-reset]').addEventListener('click', reset);
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA' && current < steps.length) {
            event.preventDefault();
            next();
        }
    });

    applyRentalType();
    restoreDraft();
    datePicker.show();
    refreshNights();

    if (root.dataset.availabilityUrl) {
        fetch(root.dataset.availabilityUrl, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('availability unavailable'))))
            .then((availability) => {
                datePicker.setAvailability(availability.dates);
                refreshNights();
            })
            .catch(() => { /* the server still checks every night when the guest continues */ });
    }

    refreshUnits();
    showStep(current);
    preselect(root.dataset.preselectUnit || '');
}

document.addEventListener('DOMContentLoaded', initReservationWizard);
