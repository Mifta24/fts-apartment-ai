/**
 * Centre stage: when the concierge shows a guest something — one unit, a set
 * of options, the stay request form, a confirmation — the stage dims and the
 * thing is presented in the middle of the screen, as if the concierge were
 * holding it up in person. Vanilla JS; the shell lives in chat.blade.php and
 * everything inside it is built here from the AI tools' ui payloads.
 *
 * The form submits to the same reservation endpoint as the stay request
 * wizard, which re-checks price and availability on the server.
 */
function initShowcase() {
    const root = document.querySelector('[data-showcase]');
    const app = document.getElementById('concierge-app');
    const labelsEl = document.querySelector('[data-showcase-labels]');
    const stage = document.querySelector('.stage');
    if (!root || !app || !labelsEl || !stage) return;

    const card = root.querySelector('[data-showcase-card]');
    const backdrop = root.querySelector('[data-showcase-backdrop]');
    const L = JSON.parse(labelsEl.textContent);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const config = {
        locale: app.dataset.locale,
        currency: app.dataset.currency,
        quoteUrl: app.dataset.quoteUrl,
        reserveUrl: app.dataset.reserveUrl,
        today: app.dataset.today,
        maxNights: Number(app.dataset.maxNights) || 90,
        storageKey: app.dataset.storageKey,
    };

    /** Units seen so far (from tool results), so a chip can reopen one without asking the AI again. */
    const units = new Map();
    /** Form values kept while the guest closes and reopens a unit's request form. */
    const drafts = new Map();

    let isOpen = false;
    let view = null;
    let lastFocus = null;
    let captionTimer = null;
    let closeTimer = null;
    let quoteTimer = null;
    let quoteSeq = 0;
    let submitting = false;

    // --- small helpers ---------------------------------------------------

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const tpl = (template, values) => Object.entries(values).sort(([a], [b]) => b.length - a.length).reduce((out, [key, value]) => out.replaceAll(`:${key}`, value), template || '');
    const numberLocale = config.locale === 'ja' ? 'ja-JP' : config.locale === 'en' ? 'en-US' : 'id-ID';
    const money = (value) => `${config.currency} ${new Intl.NumberFormat(numberLocale, { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
    const headline = (value) => String(value ?? '').replace(/[_-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

    function parseDate(iso) {
        const [year, month, day] = String(iso).split('-').map(Number);
        return new Date(year, month - 1, day);
    }

    function shortDate(iso) {
        return new Intl.DateTimeFormat(config.locale, { day: 'numeric', month: 'short' }).format(parseDate(iso));
    }

    function longDate(iso) {
        return new Intl.DateTimeFormat(config.locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(parseDate(iso));
    }

    function nightsBetween(checkIn, checkOut) {
        if (!checkIn || !checkOut) return 0;
        const [y1, m1, d1] = checkIn.split('-').map(Number);
        const [y2, m2, d2] = checkOut.split('-').map(Number);
        return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86400000);
    }

    function addDays(iso, days) {
        const date = parseDate(iso);
        date.setDate(date.getDate() + days);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function layoutLabel(unit) {
        if (unit.bedrooms === undefined || unit.bedrooms === null) return '';
        return Number(unit.bedrooms) === 0 ? L.studio : tpl(L.bedrooms, { count: unit.bedrooms });
    }

    function photosOf(unit) {
        const images = (unit.images || []).filter((image) => image.url);
        if (!images.length && unit.thumbnail_url) return [{ url: unit.thumbnail_url, alt: unit.name, tags: [] }];
        return images;
    }

    function amenityLabel(item) {
        return typeof item === 'string' ? item : item?.label || item?.name || '';
    }

    function remember(unit) {
        if (unit?.unit_type_slug) units.set(unit.unit_type_slug, { ...(units.get(unit.unit_type_slug) || {}), ...unit });
    }

    // --- open / close ----------------------------------------------------

    function focusables() {
        return [...card.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((el) => el.offsetParent !== null);
    }

    function reveal() {
        window.clearTimeout(closeTimer);

        if (!isOpen) {
            lastFocus = document.activeElement;
            isOpen = true;
            root.hidden = false;
            root.classList.remove('is-closing');
            void root.offsetWidth;
            root.classList.add('is-open');
            stage.classList.add('is-presenting');
            window.apartmentSound?.play('reveal');
        }

        card.scrollTop = 0;
        requestAnimationFrame(() => (card.querySelector('[data-showcase-focus]') || card).focus({ preventScroll: true }));
    }

    function close({ restoreFocus = true } = {}) {
        if (!isOpen) return;

        isOpen = false;
        view = null;
        window.clearInterval(captionTimer);
        window.clearTimeout(quoteTimer);
        quoteSeq++;
        root.classList.remove('is-open');
        root.classList.add('is-closing');
        stage.classList.remove('is-presenting');

        closeTimer = window.setTimeout(() => {
            root.hidden = true;
            root.classList.remove('is-closing');
            card.replaceChildren();
        }, reducedMotion ? 0 : 320);

        if (restoreFocus && lastFocus?.isConnected) lastFocus.focus({ preventScroll: true });
        window.dispatchEvent(new CustomEvent('showcase:closed'));
    }

    document.addEventListener('keydown', (event) => {
        if (!isOpen) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab') {
            const items = focusables();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && (document.activeElement === first || document.activeElement === card)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }, true);

    backdrop.addEventListener('click', () => {
        if (view !== 'form' && !submitting) close();
    });

    // --- shared pieces ---------------------------------------------------

    /** The concierge's strip: who is presenting, and what they are saying. */
    function hostStrip(caption) {
        const host = document.createElement('header');
        host.className = 'showcase-host';
        host.innerHTML = `
            <span class="showcase-host-avatar" aria-hidden="true"></span>
            <div class="showcase-host-text">
                <p class="showcase-host-name">${esc(L.presenting)}<span class="narrator-wave" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span class="showcase-host-hint">${esc(L.presenting_hint)}</span></p>
                <p class="showcase-host-caption" data-caption></p>
            </div>
            <button type="button" class="showcase-close" data-showcase-close aria-label="${esc(L.close)}"><span aria-hidden="true">×</span></button>
        `;
        host.querySelector('[data-showcase-close]').addEventListener('click', () => close());
        speak(host, caption);
        return host;
    }

    /** Types the concierge's words out, with her avatar's ring pulsing while she "speaks". */
    function speak(host, text) {
        window.clearInterval(captionTimer);
        const target = host.querySelector('[data-caption]');
        const clean = String(text || '').trim();
        target.textContent = '';
        host.classList.toggle('has-caption', clean !== '');
        if (!clean) return;

        if (reducedMotion || clean.length > 360) {
            target.textContent = clean;
            return;
        }

        host.classList.add('is-speaking');
        let shown = 0;
        captionTimer = window.setInterval(() => {
            shown = Math.min(clean.length, shown + 2);
            target.textContent = clean.slice(0, shown);
            if (shown >= clean.length) {
                window.clearInterval(captionTimer);
                host.classList.remove('is-speaking');
            }
        }, 16);
    }

    function mount(name, caption, build) {
        view = name;
        card.onkeydown = null;
        window.clearTimeout(quoteTimer);
        quoteSeq++;
        submitting = false;

        const body = document.createElement('div');
        body.className = `showcase-body showcase-view-${name}`;
        build(body);

        card.dataset.view = name;
        card.replaceChildren(hostStrip(caption), body);
        reveal();
    }

    // --- unit ------------------------------------------------------------

    function factsOf(unit) {
        const beds = (unit.bed_config || []).map((bed) => `${bed.count} × ${headline(bed.type)}`).join(', ');
        const guests = Number(unit.max_adults || 0) + Number(unit.max_children || 0);

        return [
            [L.size, unit.size_sqm ? `${unit.size_sqm} m²` : ''],
            [L.guests, guests ? tpl(L.residents, { count: guests }) : ''],
            [L.beds, beds],
            [L.view_title, unit.view_type ? tpl(L.view_label, { view: headline(unit.view_type) }) : ''],
            ['', unit.breakfast_included ? L.breakfast : ''],
        ].filter(([, value]) => value);
    }

    function priceBlock(unit) {
        if (unit.total_price !== undefined && unit.total_price !== null) {
            return `
                <div class="showcase-price">
                    <p class="showcase-price-main">${money(unit.total_price)}</p>
                    <p class="showcase-price-sub">${esc(tpl(L.total_for, { nights: unit.nights }))}${unit.discount_percent > 0 ? ` <b>−${unit.discount_percent}% ${esc(L.discount)}</b>` : ''}</p>
                </div>`;
        }

        return `
            <div class="showcase-price">
                <p class="showcase-price-main"><span class="showcase-price-from">${esc(L.from)}</span> ${money(unit.starting_price)} <span class="showcase-price-per">${esc(L.per_night)}</span></p>
            </div>`;
    }

    function longStayLine(unit) {
        const stay = unit.long_stay;
        if (!stay || !(stay.weekly_discount_percent > 0 || stay.monthly_discount_percent > 0)) return '';

        return `<p class="showcase-longstay"><span aria-hidden="true">%</span>${esc(tpl(L.long_stay, {
            weekly: stay.weekly_discount_percent, weekly_nights: stay.weekly_from_nights,
            monthly: stay.monthly_discount_percent, monthly_nights: stay.monthly_from_nights,
        }))}</p>`;
    }

    function galleryMarkup(unit, photos) {
        if (!photos.length) {
            return `<div class="showcase-gallery"><div class="showcase-photo is-empty"><span>${esc(L.no_photo)}</span><span class="showcase-badge">${esc(layoutLabel(unit))}</span></div></div>`;
        }

        const many = photos.length > 1;

        return `
            <div class="showcase-gallery" data-gallery>
                <div class="showcase-photo">
                    <img class="showcase-photo-img is-current" src="${esc(photos[0].url)}" alt="${esc(photos[0].alt || unit.name)}" data-photo-a>
                    <img class="showcase-photo-img" src="${esc(photos[0].url)}" alt="" aria-hidden="true" data-photo-b>
                    <span class="showcase-badge">${esc(layoutLabel(unit))}</span>
                    <span class="showcase-photo-tag" data-photo-tag hidden></span>
                    ${many ? `
                        <button type="button" class="showcase-arrow is-prev" data-photo-prev aria-label="${esc(L.prev)}"><span aria-hidden="true">‹</span></button>
                        <button type="button" class="showcase-arrow is-next" data-photo-next aria-label="${esc(L.next)}"><span aria-hidden="true">›</span></button>
                        <span class="showcase-photo-count" data-photo-count aria-live="polite"></span>` : ''}
                </div>
                ${many ? `<ul class="showcase-thumbs">${photos.map((photo, index) => `<li><button type="button" class="showcase-thumb" data-photo-thumb="${index}" aria-label="${esc(tpl(L.photo, { n: index + 1, total: photos.length }))}"><img src="${esc(photo.url)}" alt="" loading="lazy"></button></li>`).join('')}</ul>` : ''}
            </div>`;
    }

    /** Crossfading photo viewer with arrows, thumbnails and the arrow keys. */
    function wireGallery(scope, photos) {
        const gallery = scope.querySelector('[data-gallery]');
        if (!gallery) return;

        let a = gallery.querySelector('[data-photo-a]');
        let b = gallery.querySelector('[data-photo-b]');
        const tag = gallery.querySelector('[data-photo-tag]');
        const count = gallery.querySelector('[data-photo-count]');
        const thumbs = [...gallery.querySelectorAll('[data-photo-thumb]')];
        let index = 0;

        function go(next) {
            index = (next + photos.length) % photos.length;
            const photo = photos[index];

            b.src = photo.url;
            b.alt = photo.alt || '';
            b.classList.add('is-current');
            a.classList.remove('is-current');
            [a, b] = [b, a];

            const label = (photo.tags || [])[0];
            tag.hidden = !label;
            tag.textContent = label ? headline(label) : '';
            if (count) count.textContent = `${index + 1} / ${photos.length}`;
            thumbs.forEach((thumb, i) => (i === index ? thumb.setAttribute('aria-current', 'true') : thumb.removeAttribute('aria-current')));
        }

        gallery.querySelector('[data-photo-prev]')?.addEventListener('click', () => go(index - 1));
        gallery.querySelector('[data-photo-next]')?.addEventListener('click', () => go(index + 1));
        thumbs.forEach((thumb) => thumb.addEventListener('click', () => go(Number(thumb.dataset.photoThumb))));
        card.onkeydown = (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key) || event.target.closest('input, textarea, select')) return;
            go(index + (event.key === 'ArrowRight' ? 1 : -1));
        };
        go(0);
    }

    function presentUnit(unit, { caption = '', backToResults = null } = {}) {
        if (!unit) return false;
        remember(unit);
        unit = units.get(unit.unit_type_slug) || unit;

        const photos = photosOf(unit);
        const facts = factsOf(unit);
        const amenities = (unit.amenities || []).map(amenityLabel).filter(Boolean).map(headline);

        mount('unit', caption, (body) => {
            body.innerHTML = `
                <div class="showcase-unit">
                    ${galleryMarkup(unit, photos)}
                    <div class="showcase-info">
                        <p class="showcase-eyebrow">${esc([layoutLabel(unit), unit.floor_range ? tpl(L.floor, { range: unit.floor_range }) : ''].filter(Boolean).join(' · '))}</p>
                        <h2 class="showcase-title">${esc(unit.name)}</h2>
                        ${priceBlock(unit)}
                        ${facts.length ? `<dl class="showcase-facts">${facts.map(([term, value]) => `<div${term ? '' : ' class="is-wide"'}>${term ? `<dt>${esc(term)}</dt>` : ''}<dd>${esc(value)}</dd></div>`).join('')}</dl>` : ''}
                        ${unit.description ? `<p class="showcase-description">${esc(unit.description)}</p>` : ''}
                        ${amenities.length ? `<p class="showcase-subheading">${esc(L.amenities)}</p><ul class="showcase-amenities">${amenities.map((item) => `<li>${esc(item)}</li>`).join('')}</ul>` : ''}
                        ${longStayLine(unit)}
                    </div>
                </div>
                <footer class="showcase-actions">
                    ${backToResults ? `<button type="button" class="showcase-button is-ghost" data-back>← ${esc(L.back)}</button>` : ''}
                    <button type="button" class="showcase-button is-ghost" data-ask>${esc(L.ask)}</button>
                    <button type="button" class="showcase-button is-primary" data-reserve data-showcase-focus>${esc(L.reserve)} <span aria-hidden="true">→</span></button>
                </footer>`;

            wireGallery(body, photos);
            body.querySelector('[data-back]')?.addEventListener('click', () => presentResults(backToResults.payload, { caption: backToResults.caption }));
            body.querySelector('[data-ask]')?.addEventListener('click', () => {
                close({ restoreFocus: false });
                window.dispatchEvent(new CustomEvent('concierge:ask', { detail: { message: tpl(L.ask_question, { unit: unit.name }) } }));
            });
            body.querySelector('[data-reserve]').addEventListener('click', () => presentForm(unit, unit.stay || {}, { caption: '', backTo: () => presentUnit(unit, { caption, backToResults }) }));
        });

        return true;
    }

    // --- several units ---------------------------------------------------

    function presentResults(payload, { caption = '' } = {}) {
        const list = payload?.units || [];
        if (!list.length) return false;
        const stay = { check_in: payload.check_in, check_out: payload.check_out, adults: payload.adults, children: payload.children };
        list.forEach((unit) => remember({ ...unit, stay }));

        mount('results', caption, (body) => {
            const dates = payload.check_in && payload.check_out
                ? tpl(L.results_dates, { in: shortDate(payload.check_in), out: shortDate(payload.check_out), nights: nightsBetween(payload.check_in, payload.check_out) })
                : '';

            body.innerHTML = `
                <div class="showcase-results">
                    <div class="showcase-results-head">
                        <h2 class="showcase-title">${esc(L.results_title)}</h2>
                        ${dates ? `<p class="showcase-eyebrow">${esc(dates)}</p>` : ''}
                    </div>
                    <div class="showcase-rail-wrap">
                        <button type="button" class="showcase-arrow is-prev" data-rail-prev aria-label="${esc(L.scroll_prev)}"><span aria-hidden="true">‹</span></button>
                        <div class="showcase-rail" data-rail tabindex="-1">
                            ${list.map((unit, i) => {
                                const photo = photosOf(unit)[0];
                                const facts = [layoutLabel(unit), unit.size_sqm ? `${unit.size_sqm} m²` : '', tpl(L.residents, { count: Number(unit.max_adults || 0) + Number(unit.max_children || 0) })].filter(Boolean).join(' · ');

                                return `
                                    <article class="showcase-option" style="--i:${i}">
                                        <div class="showcase-option-photo">
                                            ${photo ? `<img src="${esc(photo.url)}" alt="${esc(photo.alt || unit.name)}" loading="lazy">` : `<span>${esc(L.no_photo)}</span>`}
                                            <span class="showcase-badge">${esc(layoutLabel(unit))}</span>
                                        </div>
                                        <div class="showcase-option-body">
                                            <h3>${esc(unit.name)}</h3>
                                            <p class="showcase-option-facts">${esc(facts)}</p>
                                            <p class="showcase-option-price">${money(unit.total_price)} <span>${esc(tpl(L.total_for, { nights: unit.nights }))}</span></p>
                                            ${unit.discount_percent > 0 ? `<p class="showcase-chip">−${unit.discount_percent}% ${esc(L.discount)}</p>` : ''}
                                            <div class="showcase-option-actions">
                                                <button type="button" class="showcase-button is-ghost" data-view="${esc(unit.unit_type_slug)}">${esc(L.view)}</button>
                                                <button type="button" class="showcase-button is-primary" data-pick="${esc(unit.unit_type_slug)}">${esc(L.reserve)}</button>
                                            </div>
                                        </div>
                                    </article>`;
                            }).join('')}
                        </div>
                        <button type="button" class="showcase-arrow is-next" data-rail-next aria-label="${esc(L.scroll_next)}"><span aria-hidden="true">›</span></button>
                    </div>
                </div>`;

            const rail = body.querySelector('[data-rail]');
            const step = () => Math.max(260, rail.clientWidth * 0.7);
            body.querySelector('[data-rail-prev]').addEventListener('click', () => rail.scrollBy({ left: -step(), behavior: 'smooth' }));
            body.querySelector('[data-rail-next]').addEventListener('click', () => rail.scrollBy({ left: step(), behavior: 'smooth' }));

            const syncArrows = () => {
                const overflow = rail.scrollWidth > rail.clientWidth + 4;
                body.querySelectorAll('.showcase-rail-wrap > .showcase-arrow').forEach((arrow) => (arrow.hidden = !overflow));
            };
            syncArrows();
            window.requestAnimationFrame(syncArrows);

            body.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => {
                presentUnit(units.get(button.dataset.view), { caption: '', backToResults: { payload, caption } });
            }));
            body.querySelectorAll('[data-pick]').forEach((button) => button.addEventListener('click', () => {
                const unit = units.get(button.dataset.pick);
                presentForm(unit, { check_in: payload.check_in, check_out: payload.check_out, adults: payload.adults, children: payload.children }, { caption: '', backTo: () => presentResults(payload, { caption }) });
            }));
            body.querySelector('[data-view]')?.setAttribute('data-showcase-focus', '');
        });

        return true;
    }

    // --- the stay request form -------------------------------------------

    function presentForm(unit, prefill = {}, { caption = '', backTo = null } = {}) {
        if (!unit) return false;
        remember(unit);
        unit = units.get(unit.unit_type_slug) || unit;

        const slug = unit.unit_type_slug;
        const saved = drafts.get(slug) || {};
        const values = {
            check_in: '', check_out: '', adults: 1, children: 0, units: 1, extra_bed: false,
            guest_name: '', contact_type: 'whatsapp', contact_value: '', special_request: '',
            ...saved, ...Object.fromEntries(Object.entries(prefill).filter(([, value]) => value !== undefined && value !== null && value !== '')),
        };
        const photo = photosOf(unit)[0];

        mount('form', caption, (body) => {
            body.innerHTML = `
                <form class="showcase-form" data-form novalidate>
                    <aside class="showcase-form-side">
                        <div class="showcase-form-photo">
                            ${photo ? `<img src="${esc(photo.url)}" alt="">` : ''}
                            <span class="showcase-badge">${esc(layoutLabel(unit))}</span>
                        </div>
                        <p class="showcase-eyebrow">${esc(L.form_title)}</p>
                        <h2 class="showcase-title">${esc(unit.name)}</h2>
                        <p class="showcase-form-intro">${esc(L.form_intro)}</p>
                        <div class="showcase-quote" data-quote aria-live="polite"></div>
                    </aside>
                    <div class="showcase-form-main">
                        <div class="showcase-grid">
                            <label class="showcase-field"><span>${esc(L.check_in)}</span><input type="date" name="check_in" min="${esc(config.today)}" value="${esc(values.check_in)}" required data-showcase-focus></label>
                            <label class="showcase-field"><span>${esc(L.check_out)}</span><input type="date" name="check_out" min="${esc(addDays(values.check_in || config.today, 1))}" value="${esc(values.check_out)}" required></label>
                            ${stepper('adults', L.adults, values.adults, 1, 20)}
                            ${stepper('children', L.children, values.children, 0, 10)}
                            ${stepper('units', L.units, values.units, 1, 10)}
                            ${unit.extra_bed_available ? `<label class="showcase-check"><input type="checkbox" name="extra_bed" ${values.extra_bed ? 'checked' : ''}><span>${esc(tpl(L.extra_bed, { price: money(unit.extra_bed_price) }))}</span></label>` : ''}
                            <label class="showcase-field is-wide"><span>${esc(L.name)}</span><input type="text" name="guest_name" value="${esc(values.guest_name)}" maxlength="100" autocomplete="name" required></label>
                            <fieldset class="showcase-field is-wide">
                                <legend>${esc(L.contact_method)}</legend>
                                <div class="showcase-pills">${['whatsapp', 'phone', 'email'].map((type) => `<label><input type="radio" name="contact_type" value="${type}" ${values.contact_type === type ? 'checked' : ''}><span>${esc(L[type])}</span></label>`).join('')}</div>
                                <input type="text" name="contact_value" value="${esc(values.contact_value)}" maxlength="120" placeholder="${esc(L.contact_value)}" aria-label="${esc(L.contact_value)}" required>
                            </fieldset>
                            <label class="showcase-field is-wide"><span>${esc(L.special)}</span><textarea name="special_request" rows="2" maxlength="500" placeholder="${esc(L.special_placeholder)}">${esc(values.special_request)}</textarea></label>
                        </div>
                        <p class="showcase-error" data-error role="alert" hidden></p>
                        <div class="showcase-alternatives" data-alternatives hidden></div>
                        <p class="showcase-disclaimer">${esc(L.disclaimer)}</p>
                        <div class="showcase-actions is-inline">
                            <button type="button" class="showcase-button is-ghost" data-back>← ${esc(L.back)}</button>
                            <button type="submit" class="showcase-button is-primary" data-submit>${esc(L.submit)} <span aria-hidden="true">→</span></button>
                        </div>
                    </div>
                </form>`;

            wireForm(body, unit, backTo);
        });

        return true;
    }

    function stepper(name, label, value, min, max) {
        return `
            <div class="showcase-field">
                <span>${esc(label)}</span>
                <div class="showcase-stepper" data-stepper="${name}" data-min="${min}" data-max="${max}">
                    <button type="button" data-step="-1" aria-label="− ${esc(label)}">−</button>
                    <input type="text" inputmode="numeric" name="${name}" value="${value}" readonly aria-label="${esc(label)}">
                    <button type="button" data-step="1" aria-label="+ ${esc(label)}">+</button>
                </div>
            </div>`;
    }

    function readForm(form) {
        const data = new FormData(form);

        return {
            check_in: data.get('check_in') || '',
            check_out: data.get('check_out') || '',
            adults: Number(data.get('adults')) || 1,
            children: Number(data.get('children')) || 0,
            units: Number(data.get('units')) || 1,
            extra_bed: Boolean(form.elements.extra_bed?.checked),
            guest_name: String(data.get('guest_name') || '').trim(),
            contact_type: data.get('contact_type') || 'whatsapp',
            contact_value: String(data.get('contact_value') || '').trim(),
            special_request: String(data.get('special_request') || '').trim(),
        };
    }

    function wireForm(body, unit, backTo) {
        const form = body.querySelector('[data-form]');
        const errorBox = body.querySelector('[data-error]');
        const alternativesBox = body.querySelector('[data-alternatives]');
        const submit = body.querySelector('[data-submit]');
        const slug = unit.unit_type_slug;

        const clearErrors = () => {
            errorBox.hidden = true;
            errorBox.textContent = '';
            alternativesBox.hidden = true;
            alternativesBox.replaceChildren();
            form.querySelectorAll('.has-error').forEach((el) => el.classList.remove('has-error'));
        };

        const fail = (message, field = null) => {
            errorBox.textContent = message;
            errorBox.hidden = false;
            const input = field ? form.elements[field] : null;
            input?.closest('.showcase-field, .showcase-check')?.classList.add('has-error');
            input?.focus?.();
            errorBox.scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
        };

        const showAlternatives = (alternatives = []) => {
            if (!alternatives.length) return;
            alternativesBox.hidden = false;
            alternativesBox.innerHTML = `<p>${esc(L.available_instead)}</p>`;
            alternatives.forEach((alt) => {
                const next = units.get(alt.slug);
                const chip = document.createElement(next ? 'button' : 'span');
                chip.className = `showcase-chip${next ? ' is-button' : ''}`;
                chip.textContent = `${alt.name} · ${money(alt.total)}`;
                if (next) {
                    chip.type = 'button';
                    chip.addEventListener('click', () => presentForm(next, readForm(form), { backTo }));
                }
                alternativesBox.appendChild(chip);
            });
        };

        const sync = () => {
            drafts.set(slug, readForm(form));
            const checkIn = form.elements.check_in.value;
            form.elements.check_out.min = addDays(checkIn || config.today, 1);
            if (checkIn && form.elements.check_out.value && form.elements.check_out.value <= checkIn) form.elements.check_out.value = '';
            const contact = form.elements.contact_type.value;
            form.elements.contact_value.type = contact === 'email' ? 'email' : 'tel';
            form.elements.contact_value.inputMode = contact === 'email' ? 'email' : 'tel';
            form.elements.contact_value.autocomplete = contact === 'email' ? 'email' : 'tel';
            scheduleQuote(body, unit, readForm(form));
        };

        form.querySelectorAll('[data-stepper]').forEach((box) => {
            const input = box.querySelector('input');
            box.querySelectorAll('[data-step]').forEach((button) => button.addEventListener('click', () => {
                const next = Math.min(Number(box.dataset.max), Math.max(Number(box.dataset.min), Number(input.value) + Number(button.dataset.step)));
                input.value = next;
                clearErrors();
                sync();
            }));
        });

        form.addEventListener('input', () => { clearErrors(); sync(); });
        form.addEventListener('change', () => { clearErrors(); sync(); });
        body.querySelector('[data-back]').addEventListener('click', () => (backTo ? backTo() : close()));
        sync();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitting) return;
            clearErrors();

            const values = readForm(form);
            const nights = nightsBetween(values.check_in, values.check_out);

            if (!values.check_in || values.check_in < config.today) return fail(L.need_dates, 'check_in');
            if (nights < 1 || nights > config.maxNights) return fail(L.need_dates, 'check_out');
            if (!values.guest_name) return fail(L.need_name, 'guest_name');
            if (!values.contact_value) return fail(L.need_contact, 'contact_value');

            submitting = true;
            submit.disabled = true;
            submit.textContent = L.sending;

            try {
                const response = await fetch(config.reserveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken || '' },
                    body: JSON.stringify({ ...values, unit_type_slug: slug, locale: config.locale, guest_token: safeToken() }),
                });
                const payload = await response.json().catch(() => ({}));

                if (response.status === 201) {
                    drafts.delete(slug);
                    presentDone(unit, values, payload);
                    return;
                }

                if (response.status === 422) {
                    const [field, messages] = Object.entries(payload.errors || {})[0] || [];
                    fail(messages?.[0] || payload.message || L.error_generic, field === 'unit_type_slug' ? null : field);
                    showAlternatives(payload.alternatives);
                } else {
                    fail(L.error_generic);
                }
            } catch {
                fail(L.error_network);
            } finally {
                if (view === 'form') {
                    submitting = false;
                    submit.disabled = false;
                    submit.innerHTML = `${esc(L.submit)} <span aria-hidden="true">→</span>`;
                }
            }
        });
    }

    function safeToken() {
        try {
            return localStorage.getItem(config.storageKey) || undefined;
        } catch {
            return undefined;
        }
    }

    function scheduleQuote(body, unit, values) {
        window.clearTimeout(quoteTimer);
        const seq = ++quoteSeq;
        const nights = nightsBetween(values.check_in, values.check_out);
        const panel = body.querySelector('[data-quote]');

        if (!values.check_in || nights < 1 || values.check_in < config.today || nights > config.maxNights) {
            panel.innerHTML = `<p class="showcase-quote-hint">${esc(L.pick_dates)}</p>`;
            return;
        }

        panel.innerHTML = `<p class="showcase-quote-hint is-loading">${esc(L.checking)}</p>`;

        quoteTimer = window.setTimeout(async () => {
            try {
                const response = await fetch(config.quoteUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken || '' },
                    body: JSON.stringify({ unit_type_slug: unit.unit_type_slug, check_in: values.check_in, check_out: values.check_out, adults: values.adults, children: values.children, units: values.units, extra_bed: values.extra_bed, locale: config.locale }),
                });
                const data = await response.json().catch(() => ({}));
                if (seq !== quoteSeq) return;

                if (response.ok) {
                    renderQuote(body, data);
                } else {
                    const message = Object.values(data.errors || {})[0]?.[0] || data.message || L.error_generic;
                    panel.innerHTML = `<p class="showcase-quote-hint is-error">${esc(message)}</p>`;
                }
            } catch {
                if (seq === quoteSeq) panel.innerHTML = `<p class="showcase-quote-hint is-error">${esc(L.error_network)}</p>`;
            }
        }, 320);
    }

    function renderQuote(body, quote) {
        const panel = body.querySelector('[data-quote]');
        if (!panel) return;

        panel.innerHTML = `
            <dl>
                ${quote.nights ? `<div><dt>${esc(L.nights)}</dt><dd>${quote.nights}</dd></div>` : ''}
                ${quote.discount_percent > 0 ? `<div><dt>${esc(L.subtotal)}</dt><dd><s>${money(quote.subtotal ?? quote.grand_total)}</s></dd></div><div class="is-discount"><dt>${esc(L.discount)} −${quote.discount_percent}%</dt><dd>−${money(quote.discount_total ?? 0)}</dd></div>` : ''}
            </dl>
            <p class="showcase-quote-total"><span>${esc(L.estimated_total)}</span><strong>${money(quote.grand_total)}</strong></p>`;
    }

    // --- done ------------------------------------------------------------

    function presentDone(unit, values, result) {
        const nights = nightsBetween(values.check_in, values.check_out);
        const booking = {
            booking_reference: result.reference,
            unit_type_slug: unit.unit_type_slug,
            name: unit.name,
            check_in: values.check_in,
            check_out: values.check_out,
            nights,
            units: values.units,
            total_price: result.total,
            status: result.status,
        };

        window.dispatchEvent(new CustomEvent('showcase:reserved', { detail: { booking } }));
        presentBooking(booking, { guestName: values.guest_name, handover: result.handover, residents: values.adults + values.children });
    }

    function presentBooking(booking, { caption = '', guestName = '', handover = null, residents = null } = {}) {
        const links = handover
            ? [['whatsapp_url', L.send_whatsapp, 'is-primary'], ['phone_url', L.call_team, 'is-ghost'], ['email_url', L.email_team, 'is-ghost']].filter(([key]) => handover[key])
            : [];

        mount('done', caption, (body) => {
            body.innerHTML = `
                <div class="showcase-done">
                    <svg class="showcase-check-mark" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M15 27l8 8 15-17"/></svg>
                    <h2 class="showcase-title" data-showcase-focus tabindex="-1">${esc(L.done_title)}</h2>
                    ${guestName ? `<p class="showcase-done-line">${esc(tpl(L.done_line, { name: guestName }))}</p>` : ''}
                    <div class="showcase-ticket">
                        <p class="showcase-ticket-label">${esc(L.reference)}</p>
                        <p class="showcase-ticket-ref">${esc(booking.booking_reference)}</p>
                        <p class="showcase-ticket-status">${esc(L.awaiting)}</p>
                        <dl class="showcase-ticket-rows">
                            <div><dt>${esc(L.unit)}</dt><dd>${esc(booking.name)}${booking.units > 1 ? ` × ${booking.units}` : ''}</dd></div>
                            <div><dt>${esc(L.check_in)} → ${esc(L.check_out)}</dt><dd>${esc(longDate(booking.check_in))} → ${esc(longDate(booking.check_out))} · ${booking.nights} ${esc(L.nights)}</dd></div>
                            ${residents ? `<div><dt>${esc(L.guests)}</dt><dd>${residents}</dd></div>` : ''}
                            <div><dt>${esc(L.estimated_total)}</dt><dd><strong>${money(booking.total_price)}</strong></dd></div>
                        </dl>
                    </div>
                    ${links.length ? `<p class="showcase-done-hint">${esc(L.done_hint)}</p><div class="showcase-done-links">${links.map(([key, label, tone]) => `<a class="showcase-button ${tone}" href="${esc(handover[key])}" target="_blank" rel="noopener">${esc(label)}</a>`).join('')}</div>` : ''}
                    <button type="button" class="showcase-button is-ghost" data-done-close>${esc(L.back_to_chat)}</button>
                </div>`;

            body.querySelector('[data-done-close]').addEventListener('click', () => close());
        });

        return true;
    }

    // --- entry points ----------------------------------------------------

    /** The ui payload the concierge's tool returned → the right presentation. */
    function present(payload, { caption = '' } = {}) {
        if (payload?.type === 'unit_results') return presentResults(payload, { caption });
        if (payload?.type === 'unit_detail') return presentUnit(payload.unit, { caption });
        if (payload?.type === 'reservation_form') return presentForm(payload.unit, payload.prefill || {}, { caption });
        if (payload?.type === 'booking_confirmation') return presentBooking(payload.booking, { caption });

        return false;
    }

    window.apartmentShowcase = {
        present,
        presentUnit: (slug, options) => presentUnit(units.get(slug), options),
        presentForm: (slug, prefill, options) => presentForm(units.get(slug), prefill, options),
        remember,
        has: (slug) => units.has(slug),
        close,
        isOpen: () => isOpen,
    };
}

document.addEventListener('DOMContentLoaded', initShowcase);
