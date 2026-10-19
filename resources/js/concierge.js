/**
 * AI Concierge chat panel. Vanilla JS, no framework: sends guest messages to
 * the Laravel backend and renders both plain text and the structured
 * ui_payload the AI's tools attach (unit cards, price quotes, booking
 * confirmations, handover notices) as real DOM, not markdown-in-a-bubble.
 */
function initConcierge() {
    const root = document.getElementById('concierge-app');
    const widget = document.querySelector('[data-chat-widget]');
    if (!root || !widget) return;

    const config = {
        startUrl: root.dataset.startUrl,
        messageUrl: root.dataset.messageUrl,
        historyUrl: root.dataset.historyUrl,
        storageKey: root.dataset.storageKey,
        locale: root.dataset.locale,
        currency: root.dataset.currency,
        lobbyUrl: root.dataset.lobbyUrl,
        unitUrlTemplate: root.dataset.unitUrl,
        staffUrl: root.dataset.staffUrl,
        leaveUrl: root.dataset.leaveUrl,
        reservationUrlTemplate: root.dataset.reservationUrl,
        labels: {
            placeholder: root.dataset.labelPlaceholder,
            leaveMessage: root.dataset.labelLeaveMessage,
            leaveEmail: root.dataset.labelLeaveEmail,
            leaveSend: root.dataset.labelLeaveSend,
            send: root.dataset.labelSend,
            open: root.dataset.labelOpen,
            close: root.dataset.labelClose,
            intro: root.dataset.labelIntro,
            newChatConfirm: root.dataset.labelNewChatConfirm,
            askUnit: root.dataset.labelAskUnit,
            breakfastIncluded: root.dataset.labelBreakfastIncluded,
            maxGuests: root.dataset.labelMaxGuests,
            unitOnly: root.dataset.labelUnitOnly,
            night: root.dataset.labelNight,
            month: root.dataset.labelMonth,
            year: root.dataset.labelYear,
            deposit: root.dataset.labelDeposit,
            studio: root.dataset.labelStudio,
            bedrooms: root.dataset.labelBedrooms,
            discount: root.dataset.labelDiscount,
            noAvailability: root.dataset.labelNoAvailability,
            bookingReceived: root.dataset.labelBookingReceived,
            reference: root.dataset.labelReference,
            viewSuffix: root.dataset.labelViewSuffix,
            thinking: root.dataset.labelThinking,
            handedOver: root.dataset.labelHandedOver,
            statusSent: root.dataset.labelStatusSent,
            statusWaiting: root.dataset.labelStatusWaiting,
            statusReplied: root.dataset.labelStatusReplied,
            draftTitle: root.dataset.labelDraftTitle,
            draftBody: root.dataset.labelDraftBody,
            draftKeep: root.dataset.labelDraftKeep,
            draftDiscard: root.dataset.labelDraftDiscard,
            viewDetails: root.dataset.labelViewDetails,
            unitDetailsQuestion: root.dataset.labelUnitDetailsQuestion,
            bookNow: root.dataset.labelBookNow,
            menuHeading: root.dataset.labelMenuHeading,
            staff: root.dataset.labelStaff,
            error: root.dataset.labelError,
            slow: root.dataset.labelSlow,
            retry: root.dataset.labelRetry,
            micStart: root.dataset.labelMicStart,
            micStop: root.dataset.labelMicStop,
            micListening: root.dataset.labelMicListening,
            micDenied: root.dataset.labelMicDenied,
            micError: root.dataset.labelMicError,
        },
        speechLang: root.dataset.speechLang,
    };

    /** Wording for the centre-stage showcase (shared with showcase.js). */
    const stageLabels = (() => {
        try {
            return JSON.parse(document.querySelector('[data-showcase-labels]')?.textContent || '{}');
        } catch {
            return {};
        }
    })();
    const showcase = () => window.apartmentShowcase;

    const messagesEl = root.querySelector('[data-messages]');
    const formEl = root.querySelector('[data-chat-form]');
    const inputEl = root.querySelector('[data-chat-input]');
    const submitEl = root.querySelector('[data-chat-submit]');
    const micEl = root.querySelector('[data-chat-mic]');
    const statusBanner = root.querySelector('[data-status-banner]');
    const chatStatus = root.querySelector('[data-chat-status]');
    const thinkingIndicator = root.querySelector('[data-thinking-indicator]');
    const draftDialog = root.querySelector('[data-draft-dialog]');
    const draftKeep = root.querySelector('[data-draft-keep]');
    const draftDiscard = root.querySelector('[data-draft-discard]');
    const chatLog = root.querySelector('#concierge-chat-log');
    const closeButton = widget.querySelector('[data-chat-close]');
    const newChatButton = widget.querySelector('[data-chat-new]');
    const launcher = widget.querySelector('[data-chat-launcher]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    let ready = false;
    let busy = false;
    let handedOver = false;
    let knownMessageCount = 0;
    let pollTimer = null;
    let isOpen = false;
    let draftDialogOpen = false;

    function setChatStatus(text, tone = '') {
        if (!chatStatus) return;

        chatStatus.textContent = text || '';
        chatStatus.className = `chat-status${text ? ` is-${tone}` : ''}`;
        chatStatus.hidden = !text;
    }

    function showDraftDialog() {
        if (!draftDialog) return;

        draftDialogOpen = true;
        draftDialog.hidden = false;
        draftDialog.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => draftKeep?.focus());
    }

    function hideDraftDialog({ focusInput = false } = {}) {
        if (!draftDialog) return;

        draftDialogOpen = false;
        draftDialog.hidden = true;
        draftDialog.setAttribute('aria-hidden', 'true');
        if (focusInput) inputEl.focus();
    }

    function syncPanelState() {
        root.classList.toggle('is-open', isOpen);
        root.dataset.chatState = isOpen ? 'open' : 'closed';
        chatLog?.setAttribute('aria-hidden', String(!isOpen));
        formEl?.setAttribute('aria-hidden', String(!isOpen));
        launcher?.setAttribute('aria-expanded', String(isOpen));
    }

    function openPanel({ focus = false } = {}) {
        isOpen = true;
        syncPanelState();
        scrollToBottom();

        if (focus) {
            requestAnimationFrame(() => inputEl.focus());
        }
    }

    function finishClosePanel({ restoreFocus = true } = {}) {
        isOpen = false;
        stopListening();
        syncPanelState();
        hideDraftDialog();
        if (restoreFocus) launcher?.focus();
    }

    function closePanel({ confirmDraft = true, restoreFocus = true } = {}) {
        if (confirmDraft && inputEl?.value.trim()) {
            showDraftDialog();
            return;
        }

        finishClosePanel({ restoreFocus });
    }

    closeButton?.addEventListener('click', () => closePanel());
    launcher?.addEventListener('click', () => openPanel({ focus: true }));
    draftKeep?.addEventListener('click', () => hideDraftDialog({ focusInput: true }));
    draftDiscard?.addEventListener('click', () => {
        inputEl.value = '';
        finishClosePanel();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        if (draftDialogOpen) {
            hideDraftDialog({ focusInput: true });
            return;
        }

        if (isOpen) closePanel();
    });
    syncPanelState();

    /**
     * Voice input through the browser's speech recognition: the guest's words
     * land in the text box so they can be checked and sent, never sent on
     * their own. The button stays hidden where the browser has no support.
     */
    const SpeechRecognitionClass = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let listening = false;

    function setListening(value) {
        listening = value;
        if (!micEl) return;

        const label = value ? config.labels.micStop : config.labels.micStart;
        micEl.classList.toggle('is-listening', value);
        micEl.setAttribute('aria-pressed', String(value));
        micEl.setAttribute('aria-label', label);
        micEl.title = label;
        inputEl.placeholder = value ? config.labels.micListening : config.labels.placeholder;
        setChatStatus(value ? config.labels.micListening : '', 'listening');
    }

    function stopListening() {
        if (!recognition) return;

        recognition.stop();
    }

    function startListening() {
        if (!SpeechRecognitionClass || listening || busy || !ready || handedOver) return;

        const baseText = inputEl.value.trim();
        const session = new SpeechRecognitionClass();
        let failed = false;

        session.lang = config.speechLang;
        session.interimResults = true;
        session.continuous = false;
        session.onresult = (event) => {
            const spoken = [...event.results].map((result) => result[0].transcript).join(' ').trim();
            inputEl.value = [baseText, spoken].filter(Boolean).join(' ');
        };
        session.onerror = (event) => {
            if (event.error === 'aborted' || event.error === 'no-speech') return;

            failed = true;
            const denied = event.error === 'not-allowed' || event.error === 'service-not-allowed';
            setChatStatus(denied ? config.labels.micDenied : config.labels.micError, 'error');
        };
        session.onend = () => {
            recognition = null;
            listening = false;
            if (micEl) {
                micEl.classList.remove('is-listening');
                micEl.setAttribute('aria-pressed', 'false');
                micEl.setAttribute('aria-label', config.labels.micStart);
                micEl.title = config.labels.micStart;
            }
            inputEl.placeholder = config.labels.placeholder;
            if (!failed) setChatStatus('');
            inputEl.focus();
        };

        try {
            session.start();
        } catch {
            return;
        }

        recognition = session;
        setListening(true);
    }

    if (micEl && SpeechRecognitionClass) {
        micEl.hidden = false;
        micEl.addEventListener('click', () => (listening ? stopListening() : startListening()));
    }

    function money(value) {
        const n = Number(value) || 0;
        const numberLocale = config.locale === 'ja' ? 'ja-JP' : config.locale === 'en' ? 'en-US' : 'id-ID';
        return `${config.currency} ${new Intl.NumberFormat(numberLocale, { maximumFractionDigits: 0 }).format(n)}`;
    }

    // Cloudflare drops a request that is still unanswered after 100s, so give
    // up just before that and let the guest retry instead of waiting forever.
    const API_TIMEOUT_MS = 95000;
    const CONVERSATION_MAX_IDLE_MS = 7 * 24 * 60 * 60 * 1000;

    async function api(url, body) {
        let response;

        try {
            response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify(body || {}),
                signal: AbortSignal.timeout(API_TIMEOUT_MS),
            });
        } catch (err) {
            const error = new Error(err.name === 'TimeoutError' ? 'Request timed out' : 'Network error');
            error.status = err.name === 'TimeoutError' ? 408 : 0;
            throw error;
        }

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const error = new Error(payload.message || `Request failed (${response.status})`);
            error.status = response.status;
            throw error;
        }

        return response.json();
    }

    function avatarMark() {
        const el = document.createElement('span');
        el.className = 'chat-host-avatar';
        el.setAttribute('aria-hidden', 'true');
        return el;
    }

    function bubble(role, text) {
        const wrap = document.createElement('div');
        wrap.className =
            role === 'guest' ? 'flex justify-end' : 'flex items-end justify-start gap-2';

        if (role !== 'guest') {
            wrap.appendChild(avatarMark());
        }

        const inner = document.createElement('div');
        inner.className = role === 'guest' ? 'chat-bubble is-guest' : 'chat-bubble is-host';
        inner.textContent = text;

        wrap.appendChild(inner);
        return wrap;
    }

    function card(children) {
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-start';
        const inner = document.createElement('div');
        inner.className = 'w-full max-w-[92%] space-y-2';
        children.forEach((c) => inner.appendChild(c));
        wrap.appendChild(inner);
        return wrap;
    }

    /** "Studio" or "2 BR" — the first thing anyone renting an apartment wants to know. */
    function layoutLabel(unit) {
        if (unit.bedrooms === undefined || unit.bedrooms === null) return '';
        return Number(unit.bedrooms) === 0 ? config.labels.studio : `${unit.bedrooms} ${config.labels.bedrooms}`;
    }

    /** "6 month(s)" or "2 year(s)" for a lease; null for a nightly stay. */
    function leaseTerm(item) {
        if (!item.rental_type || item.rental_type === 'daily' || !item.lease_months) return null;
        return item.rental_type === 'yearly' ? `${item.lease_months / 12} ${config.labels.year}` : `${item.lease_months} ${config.labels.month}`;
    }

    function unitResultCard(unit) {
        const el = document.createElement('div');
        el.className = 'chat-card';
        const facts = [layoutLabel(unit), unit.size_sqm ? `${unit.size_sqm} m²` : '', `${unit.max_adults + unit.max_children} ${config.labels.maxGuests}`].filter(Boolean).join(' · ');
        el.innerHTML = `
            ${unit.thumbnail_url ? `<img src="${unit.thumbnail_url}" alt="${unit.name}" class="h-28 w-full object-cover">` : ''}
            <div class="p-3">
                <p class="chat-card-tag">${facts}</p>
                <p class="mt-1 font-semibold">${unit.name}</p>
                <p class="mt-1 text-sm font-semibold">${unit.total_price !== undefined && unit.total_price !== null
                    ? `${money(unit.total_price)} <span class="font-normal opacity-60">/ ${leaseTerm(unit) || `${unit.nights} ${config.labels.night}`}</span>`
                    : `${money(unit.starting_price)} <span class="font-normal opacity-60">/ ${config.labels.night}</span>`}</p>
                ${unit.discount_percent > 0 ? `<p class="chat-card-tag mt-1">−${unit.discount_percent}% ${config.labels.discount}</p>` : ''}
                ${leaseTerm(unit) && unit.deposit_total ? `<p class="chat-card-tag mt-1">${config.labels.deposit}: ${money(unit.deposit_total)}</p>` : ''}
                <button type="button" class="chat-card-button" data-detail-slug="${unit.unit_type_slug}">
                    ${config.labels.viewDetails}
                </button>
            </div>
        `;
        el.querySelector('[data-detail-slug]').addEventListener('click', () => {
            if (showcase()?.has(unit.unit_type_slug)) {
                showcase().presentUnit(unit.unit_type_slug);
                return;
            }
            sendMessage(config.labels.unitDetailsQuestion.replace(':unit', unit.name));
        });
        return el;
    }

    function unitDetailCard(unit) {
        const el = document.createElement('div');
        el.className = 'chat-card';

        const images = (unit.images || [])
            .slice(0, 4)
            .map((img) => `<img src="${img.url}" alt="${img.alt || unit.name}" class="h-16 w-full rounded object-cover">`)
            .join('');

        el.innerHTML = `
            <div class="p-3">
                <p class="chat-card-tag">${[layoutLabel(unit), unit.floor_range ? `${unit.floor_range}F` : ''].filter(Boolean).join(' · ')}</p>
                <p class="mt-1 font-semibold">${unit.name}</p>
                <p class="mt-1 text-xs opacity-70">${unit.description || ''}</p>
                <div class="mt-2 grid grid-cols-4 gap-1">${images}</div>
                <dl class="mt-2 grid grid-cols-2 gap-1 text-xs opacity-70">
                    <div>${unit.size_sqm ?? '-'} m²</div>
                    <div>${unit.max_adults + unit.max_children} ${config.labels.maxGuests}</div>
                    ${unit.breakfast_included ? `<div>${config.labels.breakfastIncluded}</div>` : ''}
                    <div>${unit.view_type ? unit.view_type + ' ' + config.labels.viewSuffix : ''}</div>
                </dl>
                <button type="button" class="chat-card-button is-stage" data-stage-slug="${unit.unit_type_slug}">${stageLabels.show_again || config.labels.viewDetails}</button>
            </div>
        `;
        el.querySelector('[data-stage-slug]').addEventListener('click', () => showcase()?.presentUnit(unit.unit_type_slug));
        return el;
    }

    function availabilityCard(payload) {
        const el = document.createElement('div');
        el.className = 'chat-card p-3';

        if (!payload.available) {
            el.innerHTML = `<p class="text-sm opacity-70">${config.labels.noAvailability}</p>`;
            return el;
        }

        const q = payload.quote;
        el.innerHTML = `
            <p class="font-semibold">${q.name}</p>
            <p class="chat-card-tag mt-1">${q.check_in} → ${q.check_out} · ${leaseTerm(q) || `${q.nights} ${config.labels.night}`}</p>
            ${q.discount_percent > 0 ? `<p class="mt-1 text-xs opacity-70"><s>${money(q.subtotal)}</s> · −${q.discount_percent}% ${config.labels.discount}</p>` : ''}
            <p class="mt-1 text-base font-semibold">${money(q.grand_total)}</p>
            ${leaseTerm(q) && q.deposit_total ? `<p class="mt-1 text-xs opacity-70">${config.labels.deposit}: ${money(q.deposit_total)}</p>` : ''}
        `;
        return el;
    }

    function bookingConfirmationCard(payload) {
        const b = payload.booking;
        const el = document.createElement('div');
        el.className = 'chat-card p-3';
        el.style.borderLeft = '3px solid var(--signal-deep)';
        el.innerHTML = `
            <p class="text-sm font-semibold">${config.labels.bookingReceived}</p>
            <p class="chat-card-tag mt-1">${config.labels.reference}: ${b.booking_reference}</p>
            <p class="mt-1 text-xs opacity-70">${b.name} · ${b.check_in} → ${b.check_out} · ${leaseTerm(b) || `${b.nights} ${config.labels.night}`}</p>
            <p class="mt-1 text-sm font-semibold">${money(b.total_price)}</p>
            ${leaseTerm(b) && b.deposit_total ? `<p class="mt-1 text-xs opacity-70">${config.labels.deposit}: ${money(b.deposit_total)}</p>` : ''}
        `;
        return el;
    }

    function handoverCard(payload) {
        const el = document.createElement('div');
        el.className = 'chat-card p-3 text-xs';
        el.style.borderLeft = '3px solid #f3b743';
        el.textContent = config.labels.handedOver;
        return el;
    }

    /** A note in the chat that the stay request form was put on screen, with a way to bring it back. */
    function reservationFormCard(payload) {
        const el = document.createElement('div');
        el.className = 'chat-card p-3';
        el.style.borderLeft = '3px solid var(--signal-deep)';
        el.innerHTML = `
            <p class="chat-card-tag">${stageLabels.form_title || ''}</p>
            <p class="mt-1 text-sm font-semibold">${payload.unit.name}</p>
            <button type="button" class="chat-card-button is-stage" style="margin-top:10px" data-open-form>${stageLabels.reopen_form || ''}</button>
        `;
        el.querySelector('[data-open-form]').addEventListener('click', () => showcase()?.present(payload));
        return el;
    }

    /** Which of a reply's payloads the concierge should hold up to the guest, if any. */
    function presentablePayload(uiPayload) {
        for (const type of ['reservation_form', 'unit_detail', 'unit_results', 'booking_confirmation']) {
            const found = (uiPayload || []).find((payload) => payload.type === type);
            if (found) return found;
        }

        return null;
    }

    function renderUiPayload(uiPayload, { caption = '', live = false } = {}) {
        if (!uiPayload || !uiPayload.length) return;

        uiPayload.forEach((payload) => {
            if (payload.type === 'unit_results') {
                payload.units.forEach((unit) => showcase()?.remember(unit));
                messagesEl.appendChild(card(payload.units.map(unitResultCard)));
            } else if (payload.type === 'unit_detail') {
                showcase()?.remember(payload.unit);
                messagesEl.appendChild(card([unitDetailCard(payload.unit)]));
            } else if (payload.type === 'reservation_form') {
                showcase()?.remember(payload.unit);
                messagesEl.appendChild(card([reservationFormCard(payload)]));
            } else if (payload.type === 'availability') {
                messagesEl.appendChild(card([availabilityCard(payload)]));
            } else if (payload.type === 'booking_confirmation') {
                messagesEl.appendChild(card([bookingConfirmationCard(payload)]));
            } else if (payload.type === 'handover') {
                messagesEl.appendChild(card([handoverCard(payload)]));
            }
        });

        // A fresh answer is shown to the guest at centre stage, a beat after the words arrive.
        const presentable = live ? presentablePayload(uiPayload) : null;
        if (presentable && showcase()) {
            window.setTimeout(() => showcase().present(presentable, { caption }), 420);
        }
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function setBusy(value) {
        busy = value;
        const disabled = busy || !ready || handedOver;
        inputEl.disabled = disabled;
        submitEl.disabled = disabled;
        if (micEl) micEl.disabled = disabled;
        if (disabled) stopListening();
        if (launcher) launcher.disabled = busy || !ready;
        if (newChatButton) newChatButton.disabled = busy || !ready;
        root.setAttribute('aria-busy', String(busy));
        document.body.classList.toggle('is-thinking', busy);
        if (thinkingIndicator) {
            thinkingIndicator.hidden = !busy;
        }
        document.querySelectorAll('[data-hero-quick-message], [data-ask-ai-button], [data-quick-message]')
            .forEach((button) => { button.disabled = disabled; });
        submitEl.textContent = busy ? config.labels.thinking : config.labels.send;
        if (busy) scrollToBottom();
    }

    function showHandedOverBanner() {
        handedOver = true;
        statusBanner.textContent = config.labels.handedOver;
        statusBanner.classList.remove('hidden');
        setChatStatus(config.labels.statusWaiting, 'waiting');
        setBusy(busy);
        startPolling();
    }

    function clearHandedOverBanner() {
        handedOver = false;
        statusBanner.classList.add('hidden');
        setBusy(busy);
        stopPolling();
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(pollForStaffReplies, 4000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    async function pollForStaffReplies() {
        const guestToken = localStorage.getItem(config.storageKey);
        if (!guestToken) return;

        try {
            const response = await fetch(`${config.historyUrl}?guest_token=${encodeURIComponent(guestToken)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;

            const data = await response.json();

            if (data.messages.length > knownMessageCount) {
                const newMessages = data.messages.slice(knownMessageCount);
                newMessages.forEach(renderMessage);
                if (newMessages.some((message) => message.role === 'staff')) {
                    setChatStatus(config.labels.statusReplied, 'replied');
                }
                window.apartmentSound?.play('incoming');
                knownMessageCount = data.messages.length;
                scrollToBottom();
            }

            if (data.status !== 'handed_over') {
                clearHandedOverBanner();
            }
        } catch {
            // transient network hiccup — try again on the next tick
        }
    }

    function renderMessage(message, { live = false } = {}) {
        if (message.role === 'guest') {
            messagesEl.appendChild(bubble('guest', message.content));
        } else if (message.role === 'assistant') {
            if (message.content) {
                messagesEl.appendChild(bubble('assistant', message.content));
            }
            renderUiPayload(message.ui_payload, { caption: message.content || '', live });
            const chips = actionChips(message.suggested_actions);
            if (chips) messagesEl.appendChild(chips);
        } else if (message.role === 'staff') {
            messagesEl.appendChild(card([staffBubble(message.content)]));
            setChatStatus(config.labels.statusReplied, 'replied');
        } else if (message.role === 'system') {
            messagesEl.appendChild(bubble('assistant', message.content));
        }
    }

    function quickMenuCard() {
        const template = document.querySelector('[data-quick-menu-items]');
        if (!template) return null;

        const el = document.createElement('div');
        el.className = 'chat-card';

        const heading = document.createElement('p');
        heading.className = 'chat-card-tag border-b border-[var(--line)] px-3 py-2';
        heading.textContent = config.labels.menuHeading;
        el.appendChild(heading);

        const list = document.createElement('div');
        list.className = 'divide-y divide-[var(--line)]';

        template.content.querySelectorAll('[data-quick-message]').forEach((source) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'flex w-full items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-[#d7f75b33]';
            item.innerHTML = `<span>${source.textContent}</span><span class="opacity-40">→</span>`;
            item.addEventListener('click', () => sendMessage(source.dataset.quickMessage));
            list.appendChild(item);
        });

        el.appendChild(list);
        return el;
    }

    function staffBubble(text) {
        const el = document.createElement('div');
        el.className = 'chat-bubble is-host';
        el.style.borderLeft = '3px solid #5fb8e0';
        el.innerHTML = `<p class="chat-card-tag mb-0.5" style="color:#2f7fa3">${config.labels.staff}</p>`;
        el.append(document.createTextNode(text));
        return el;
    }

    const sceneNames = { home: 'reception', lobby: 'reception', info: 'reception', units: 'units', unit: 'unit_detail', facilities: 'facilities', facility: 'facility_detail', reservation: 'reservation', staff: 'handover' };

    /**
     * Where the guest is in the lobby, so the concierge can answer for "this
     * unit" or the reservation on screen. Never includes name or contact data.
     */
    function uiContext() {
        const pageScene = document.querySelector('[data-lobby]')?.dataset.scene || 'lobby';
        const context = { scene: sceneNames[pageScene] || 'reception' };

        const lastSegment = decodeURIComponent(window.location.pathname.split('/').pop());
        if (pageScene === 'unit') context.selected_unit = lastSegment;
        if (pageScene === 'facility') context.selected_facility = Number(lastSegment);

        if (pageScene === 'reservation') {
            try {
                const draft = JSON.parse(sessionStorage.getItem(`reservation_draft_${root.dataset.apartmentSlug}`) || 'null');
                const { check_in, check_out, adults, children, units, unit_type_slug } = draft?.values || {};
                context.reservation = { check_in, check_out, adults, children, units, unit_type_slug };
                if (unit_type_slug) context.selected_unit = unit_type_slug;
            } catch { /* no draft to share */ }
        }

        return context;
    }

    function actionChips(actions) {
        if (!actions || !actions.length) return null;

        const unitUrl = (slug) => config.unitUrlTemplate.replace('__SLUG__', encodeURIComponent(slug));
        const reservationUrl = (slug) => config.reservationUrlTemplate.replace('__SLUG__', encodeURIComponent(slug));
        const targets = {
            view_unit: [config.labels.viewDetails, (action) => unitUrl(action.unit)],
            reserve: [config.labels.bookNow, (action) => reservationUrl(action.unit)],
            staff: [config.labels.staff, () => config.staffUrl],
        };

        const wrap = document.createElement('div');
        wrap.className = 'flex flex-wrap gap-2 pl-10';

        actions.forEach((action) => {
            const target = targets[action.action];
            if (!target) return;
            const link = document.createElement('a');
            link.href = target[1](action);
            link.className = 'chat-chip';
            link.textContent = target[0];
            link.addEventListener('click', (event) => {
                if (action.action === 'reserve' && showcase()?.has(action.unit)) {
                    event.preventDefault();
                    showcase().presentForm(action.unit);
                    return;
                }

                closePanel({ confirmDraft: false, restoreFocus: false });

                // Another page: walk there the way the menu does. A panel on
                // this page is just a hash change, so leave it to the browser.
                if (link.pathname !== window.location.pathname && window.apartmentStage) {
                    event.preventDefault();
                    window.apartmentStage.leave(link.href);
                }
            });
            wrap.appendChild(link);
        });

        return wrap.childElementCount ? wrap : null;
    }

    function errorCard(text, err) {
        const el = document.createElement('div');
        el.className = 'chat-card p-3 text-sm';
        el.style.borderLeft = '3px solid var(--danger)';

        const message = document.createElement('p');
        message.textContent = err.status === 429 ? config.labels.slow : config.labels.error;

        const actions = document.createElement('div');
        actions.className = 'mt-3 flex flex-wrap gap-2';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'chat-mini-button is-primary';
        retry.textContent = config.labels.retry;
        retry.addEventListener('click', () => {
            el.remove();
            sendMessage(text, { retry: true });
        });

        const staff = document.createElement('a');
        staff.href = config.staffUrl;
        staff.className = 'chat-mini-button';
        staff.textContent = config.labels.staff;
        staff.addEventListener('click', (event) => {
            closePanel({ confirmDraft: false, restoreFocus: false });

            if (window.apartmentStage) {
                event.preventDefault();
                window.apartmentStage.leave(staff.href);
            }
        });

        actions.append(retry, staff);

        if (err.status !== 429) {
            const leave = document.createElement('button');
            leave.type = 'button';
            leave.className = 'chat-mini-button';
            leave.textContent = config.labels.leaveMessage;
            leave.addEventListener('click', () => {
                actions.remove();
                el.appendChild(leaveMessageForm(text, el));
            });
            actions.appendChild(leave);
        }

        el.append(message, actions);
        return el;
    }

    /**
     * When the AI cannot answer, the guest's message goes straight to the team.
     */
    function leaveMessageForm(text, errorEl) {
        const form = document.createElement('form');
        form.className = 'mt-3 flex flex-wrap gap-2';

        const email = document.createElement('input');
        email.type = 'email';
        email.maxLength = 255;
        email.autocomplete = 'email';
        email.placeholder = config.labels.leaveEmail;
        email.setAttribute('aria-label', config.labels.leaveEmail);
        email.className = 'min-w-0 flex-1 rounded border px-2 py-1 text-sm';

        const send = document.createElement('button');
        send.type = 'submit';
        send.className = 'chat-mini-button is-primary';
        send.textContent = config.labels.leaveSend;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            send.disabled = true;

            try {
                const data = await api(config.leaveUrl, {
                    guest_token: localStorage.getItem(config.storageKey),
                    message: text,
                    email: email.value.trim() || null,
                });
                errorEl.remove();
                renderMessage(data.message, { live: true });
                knownMessageCount += 2; // the guest message just left + the confirmation just rendered
                showHandedOverBanner();
            } catch {
                send.disabled = false;
                email.setCustomValidity(config.labels.error);
                email.reportValidity();
                email.setCustomValidity('');
            }
        });

        form.append(email, send);
        return form;
    }

    async function sendMessage(text, { retry = false } = {}) {
        if (!text.trim() || handedOver || busy || !ready) return;

        const guestToken = localStorage.getItem(config.storageKey);
        if (!guestToken) return;

        openPanel();
        if (!retry) messagesEl.appendChild(bubble('guest', text));
        window.apartmentSound?.play('sent');
        scrollToBottom();
        setBusy(true);

        try {
            const data = await api(config.messageUrl, { guest_token: guestToken, message: text, ...uiContext() });
            renderMessage(data.message, { live: true });
            setChatStatus(config.labels.statusSent, 'sent');
            window.apartmentSound?.play('incoming');
            knownMessageCount += 2; // the guest message just sent + the reply just rendered
            if (data.status === 'handed_over') {
                showHandedOverBanner();
            }
        } catch (err) {
            messagesEl.appendChild(errorCard(text, err));
        } finally {
            setBusy(false);
            scrollToBottom();
        }
    }

    async function boot() {
        let guestToken = localStorage.getItem(config.storageKey);

        if (!guestToken) {
            const data = await api(config.startUrl, { locale: config.locale });
            guestToken = data.guest_token;
            localStorage.setItem(config.storageKey, guestToken);
            messagesEl.appendChild(bubble('assistant', config.labels.intro));
            const menu = quickMenuCard();
            if (menu) messagesEl.appendChild(card([menu]));
            return;
        }

        try {
            const response = await fetch(`${config.historyUrl}?guest_token=${encodeURIComponent(guestToken)}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error('history_unavailable');

            const data = await response.json();
            const lastAt = data.messages.at(-1)?.created_at;

            if (data.status !== 'handed_over' && lastAt && Date.now() - Date.parse(lastAt) > CONVERSATION_MAX_IDLE_MS) {
                localStorage.removeItem(config.storageKey);
                return boot();
            }

            if (!data.messages.length) {
                messagesEl.appendChild(bubble('assistant', config.labels.intro));
                const menu = quickMenuCard();
                if (menu) messagesEl.appendChild(card([menu]));
            } else {
                data.messages.forEach(renderMessage);
            }
            knownMessageCount = data.messages.length;

            if (data.status === 'handed_over') {
                showHandedOverBanner();
            }
            if (data.messages.some((message) => message.role === 'staff')) {
                setChatStatus(config.labels.statusReplied, 'replied');
            }
        } catch {
            localStorage.removeItem(config.storageKey);
            return boot();
        }

        scrollToBottom();
    }

    /**
     * Drops the current conversation and starts a fresh one: the stored token
     * is forgotten, so boot() opens a new server-side conversation with the
     * page language and the intro greeting.
     */
    async function startNewChat() {
        if (busy || !ready) return;
        if (handedOver && !window.confirm(config.labels.newChatConfirm)) return;

        ready = false;
        stopPolling();
        stopListening();
        localStorage.removeItem(config.storageKey);
        messagesEl.replaceChildren();
        inputEl.value = '';
        knownMessageCount = 0;
        handedOver = false;
        statusBanner.classList.add('hidden');
        setChatStatus('');
        setBusy(true);

        try {
            await boot();
            ready = true;
        } catch (err) {
            statusBanner.textContent = err.status === 429 ? config.labels.slow : root.dataset.labelConnectionError;
            statusBanner.classList.remove('hidden');
            messagesEl.appendChild(bubble('assistant', config.labels.intro));
        } finally {
            setBusy(false);
            scrollToBottom();
            inputEl.focus({ preventScroll: true });
        }
    }

    newChatButton?.addEventListener('click', startNewChat);

    formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!ready || busy || handedOver || !inputEl.value.trim()) return;
        if (recognition) {
            recognition.onresult = null;
            recognition.abort();
        }
        const text = inputEl.value;
        inputEl.value = '';
        sendMessage(text);
    });

    document.querySelectorAll('[data-ask-ai-button]').forEach((button) => {
        button.addEventListener('click', () => {
            const unitCard = button.closest('[data-unit-card]');
            const unitName = unitCard?.dataset.unitName || '';
            openPanel();
            sendMessage(config.labels.askUnit.replace(':unit', unitName));
        });
    });

    // Hero quick-start menu — same topics as the in-chat menu, always
    // visible up front so guests see what the AI can do immediately.
    document.querySelectorAll('[data-hero-quick-message]').forEach((button) => {
        button.addEventListener('click', () => {
            openPanel();
            sendMessage(button.dataset.heroQuickMessage);
        });
    });

    // The guest sent a stay request from the form at centre stage: keep the receipt in the chat too.
    window.addEventListener('showcase:reserved', (event) => {
        messagesEl.appendChild(card([bookingConfirmationCard({ booking: event.detail.booking })]));
        knownMessageCount += 1;
        setChatStatus(config.labels.statusSent, 'sent');
        scrollToBottom();
    });

    // Closing the showcase hands the conversation back to the guest.
    window.addEventListener('showcase:closed', () => {
        if (isOpen && !inputEl.disabled) inputEl.focus({ preventScroll: true });
    });

    // A menu pick in the same scene: the concierge answers right where the guest stands.
    window.addEventListener('concierge:ask', (event) => sendMessage(event.detail.message));

    setBusy(true);
    boot().then(() => {
        ready = true;
        scrollToBottom();
    }).catch((err) => {
        statusBanner.textContent = err.status === 429 ? config.labels.slow : root.dataset.labelConnectionError;
        statusBanner.classList.remove('hidden');
        messagesEl.appendChild(bubble('assistant', config.labels.intro));
    }).finally(() => {
        setBusy(false);

        // The guest chose this topic from the menu on the previous scene:
        // let the new scene settle for a beat, then carry on the conversation.
        const topic = window.takePendingConciergeTopic?.();
        if (topic && ready) window.setTimeout(() => sendMessage(topic), 650);
    });
}

document.addEventListener('DOMContentLoaded', initConcierge);

function initUnitGallery() {
    document.querySelectorAll('[data-unit-gallery]').forEach((gallery) => {
        const main = gallery.querySelector('[data-unit-gallery-main]');
        const thumbs = [...gallery.querySelectorAll('[data-unit-thumb]')];
        if (!main) return;

        thumbs.forEach((thumb) => {
            thumb.addEventListener('click', () => {
                main.src = thumb.dataset.src;
                main.alt = thumb.dataset.alt || '';
                thumbs.forEach((other) => other.toggleAttribute('aria-current', other === thumb));
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', initUnitGallery);
