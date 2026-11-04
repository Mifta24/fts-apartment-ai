/**
 * The move-in date picker on the rental application wizard. It replaces the
 * browser's own date input, which ignores the applicant's language and cannot
 * show which days are fully taken. The chosen date lives in a hidden input, so
 * the wizard reads, saves and validates it as before.
 */
const DAY_MS = 86400000;

const toIso = (year, month, day) => `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
const parse = (iso) => iso.split('-').map(Number);
const addDays = (iso, days) => {
    const [year, month, day] = parse(iso);
    const date = new Date(Date.UTC(year, month - 1, day) + days * DAY_MS);
    return toIso(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
};

/**
 * @param {object} options
 * @param {HTMLElement} options.root  The [data-date-picker] element.
 * @param {HTMLInputElement} options.moveIn
 * @param {{today: string, locale: string}} options.config
 * @param {Record<string, string>} options.labels
 * @param {(iso: string) => string} options.formatDate
 * @param {() => void} options.onChange  Called after the applicant changes the date.
 */
export function createDatePicker({ root, moveIn, config, labels, formatDate, onChange }) {
    const grid = root.querySelector('[data-cal-grid]');
    const title = root.querySelector('[data-cal-title]');
    const weekdays = root.querySelector('[data-cal-weekdays]');
    const prevButton = root.querySelector('[data-cal-prev]');
    const nextButton = root.querySelector('[data-cal-next]');
    const slot = root.querySelector('[data-slot="move_in"]');

    /** @type {Set<string>|null} Open days, or null while unknown (then every future day is allowed). */
    let openDays = null;
    let lastDay = null;
    let [year, month] = parse(config.today);

    const monthTitle = new Intl.DateTimeFormat(config.locale, { month: 'long', year: 'numeric' });
    const dayLabel = new Intl.DateTimeFormat(config.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    // 2024-01-01 is a Monday, so seven days from it are the weekday names, Monday first.
    const weekdayFormat = new Intl.DateTimeFormat(config.locale, { weekday: 'short', timeZone: 'UTC' });
    weekdays.replaceChildren(...Array.from({ length: 7 }, (_, index) => {
        const name = document.createElement('span');
        name.textContent = weekdayFormat.format(new Date(Date.UTC(2024, 0, 1 + index)));
        return name;
    }));

    const isOpenDay = (iso) => iso >= config.today && (openDays === null || openDays.has(iso));

    function pick(iso) {
        if (!isOpenDay(iso)) return;
        moveIn.value = iso;
        onChange();
        render();
    }

    function moveFocus(cell, step) {
        const cells = [...grid.querySelectorAll('button:not(:disabled)')];
        const target = cells[cells.indexOf(cell) + step];
        if (target) {
            cell.tabIndex = -1;
            target.tabIndex = 0;
            target.focus();
        }
    }

    function render() {
        title.textContent = monthTitle.format(new Date(Date.UTC(year, month - 1, 1)));

        const first = new Date(Date.UTC(year, month - 1, 1));
        const offset = (first.getUTCDay() + 6) % 7;
        const total = new Date(Date.UTC(year, month, 0)).getUTCDate();
        const chosen = moveIn.value;
        const cells = [];
        let tabbable = null;

        for (let blank = 0; blank < offset; blank += 1) cells.push(document.createElement('span'));

        for (let day = 1; day <= total; day += 1) {
            const iso = toIso(year, month, day);
            const button = document.createElement('button');
            const allowed = isOpenDay(iso);

            button.type = 'button';
            button.textContent = String(day);
            button.dataset.date = iso;
            button.disabled = !allowed;
            button.tabIndex = -1;
            button.className = 'wizard-cal-day';

            const spoken = dayLabel.format(new Date(Date.UTC(year, month - 1, day)));
            button.setAttribute('aria-label', !allowed && iso >= config.today ? `${spoken}, ${labels.cal_full}` : spoken);

            if (iso < config.today) button.classList.add('is-past');
            else if (!allowed) button.classList.add('is-full');
            if (iso === chosen) {
                button.classList.add('is-start');
                button.setAttribute('aria-pressed', 'true');
            }

            if (allowed && (tabbable === null || iso === chosen)) tabbable = button;
            cells.push(button);
        }

        if (tabbable) {
            tabbable.tabIndex = 0;
            tabbable.dataset.calFocus = '';
        }

        grid.replaceChildren(...cells);

        const [currentYear, currentMonth] = parse(config.today);
        prevButton.disabled = year === currentYear && month === currentMonth;
        const ceiling = (lastDay ? addDays(lastDay, 1) : addDays(config.today, 366)).slice(0, 7);
        nextButton.disabled = toIso(year, month, 1).slice(0, 7) >= ceiling;

        slot.querySelector('span').textContent = labels.move_in;
        slot.querySelector('[data-slot-value]').textContent = chosen ? formatDate(chosen) : labels.cal_choose;
        slot.setAttribute('data-active', '');
    }

    function step(delta) {
        month += delta;
        if (month < 1) { month = 12; year -= 1; }
        if (month > 12) { month = 1; year += 1; }
        render();
    }

    grid.addEventListener('click', (event) => {
        const cell = event.target.closest('button[data-date]');
        if (cell && !cell.disabled) pick(cell.dataset.date);
    });

    grid.addEventListener('keydown', (event) => {
        const cell = event.target.closest('button[data-date]');
        const moves = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 };
        if (cell && moves[event.key]) {
            event.preventDefault();
            moveFocus(cell, moves[event.key]);
        }
    });

    prevButton.addEventListener('click', () => step(-1));
    nextButton.addEventListener('click', () => step(1));

    return {
        /** Applies the days the server says are open. A failed or empty answer leaves the picker permissive or closed, respectively. */
        setAvailability(dates) {
            openDays = new Set(dates);
            lastDay = dates.length ? dates[dates.length - 1] : null;
            render();
        },
        get hasOpenDays() {
            return openDays === null || openDays.size > 0;
        },
        /** Shows the month of the chosen move-in, or the current month. */
        show() {
            [year, month] = parse(moveIn.value || config.today);
            render();
        },
        clear() {
            moveIn.value = '';
            this.show();
        },
    };
}
