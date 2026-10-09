/**
 * Full-screen stage chrome shared by the opening screen and every floor:
 * opens the lift doors once the photo is ready, runs the floor display up or
 * down to the floor the guest chose, and closes the doors again before
 * following a link — so moving between pages feels like riding the lift
 * rather than loading a page.
 *
 * A link may carry `data-topic`: the question the guest picked from the menu.
 * It is handed to the AI concierge, which raises it once the next scene is up
 * (or straight away when the guest is already in that scene).
 */
const TOPIC_KEY = 'concierge_pending_topic';
const FLOOR_KEY = 'apartment_last_floor';
const FLOOR_ORDER = ['L', '02', '03', '04', '05', '06'];

/**
 * The floor display counts through every floor between the last one the guest
 * was on and this one, with the arrow pointing the way the lift travelled.
 */
function runFloorDisplay(stage, reducedMotion) {
    const current = stage.dataset.floor;
    const code = stage.querySelector('[data-floor-code]');
    const arrow = stage.querySelector('[data-floor-arrow]');
    let previous = null;

    try {
        previous = sessionStorage.getItem(FLOOR_KEY);
        sessionStorage.setItem(FLOOR_KEY, current);
    } catch { /* private mode: the display just shows the current floor */ }

    if (!code || !previous || previous === current) return;

    const from = FLOOR_ORDER.indexOf(previous);
    const to = FLOOR_ORDER.indexOf(current);
    if (from < 0 || to < 0) return;

    arrow?.classList.toggle('is-down', to < from);
    if (reducedMotion) return;

    const step = to > from ? 1 : -1;
    let index = from;
    code.textContent = FLOOR_ORDER[index];

    const timer = window.setInterval(() => {
        index += step;
        code.textContent = FLOOR_ORDER[index];
        if (index === to) window.clearInterval(timer);
    }, 180);
}

function initStage() {
    const stage = document.querySelector('.stage');
    if (!stage) return;

    const loader = stage.querySelector('[data-stage-loader]');
    const image = stage.querySelector('.stage-image');
    const tour = stage.querySelector('[data-stage-tour]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const showScene = () => loader?.classList.add('is-ready');

    runFloorDisplay(stage, reducedMotion);

    // On phones the lift panel is a scrolling strip; keep the lit button in view.
    // Scrolling the strip itself: scrollIntoView would also pan the stage and clip the header.
    const strip = stage.querySelector('.lift-buttons');
    const current = strip?.querySelector('.lift-button[aria-current]');
    if (strip && current) {
        const offset = current.getBoundingClientRect().left - strip.getBoundingClientRect().left;
        strip.scrollLeft += offset - (strip.clientWidth - current.offsetWidth) / 2;
    }

    if (!image || image.complete) {
        showScene();
    } else {
        image.addEventListener('load', showScene, { once: true });
        image.addEventListener('error', showScene, { once: true });
    }

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            leaving = false;
            stage.classList.remove('is-leaving');
            tour?.classList.remove('is-visible');
        }
    });

    let leaving = false;

    /** Ease the stage out, then walk the guest over to `href`. */
    function leave(href, line = null, topic = null) {
        if (leaving) return;
        leaving = true;

        try {
            if (topic) sessionStorage.setItem(TOPIC_KEY, topic);
            else sessionStorage.removeItem(TOPIC_KEY);
        } catch { /* private mode: the scene still changes, only the topic is dropped */ }

        // The concierge announces the floor while the lift doors close.
        if (line && tour) {
            tour.textContent = line;
            tour.classList.add('is-visible');
        }

        stage.classList.add('is-leaving');

        const chime = window.apartmentSound?.isEnabled() ?? false;
        if (chime) window.apartmentSound.play('enter');

        const hold = reducedMotion ? 0 : line ? 900 : 520;
        window.setTimeout(() => { window.location.href = href; }, hold);
    }

    document.querySelectorAll('[data-stage-exit]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
            if (event.defaultPrevented || modified || link.target === '_blank') return;

            event.preventDefault();

            // Already in this scene: no walk needed, the concierge just takes
            // the topic up where the guest is standing.
            const samePlace = link.pathname === window.location.pathname;
            if (samePlace && link.dataset.topic) {
                window.dispatchEvent(new CustomEvent('concierge:ask', { detail: { message: link.dataset.topic } }));
                return;
            }
            if (samePlace) return;

            leave(link.href, link.dataset.tourLine, link.dataset.topic);
        });
    });

    window.apartmentStage = { leave };
}

/** The topic the guest picked on the previous scene, if any (read once). */
window.takePendingConciergeTopic = () => {
    try {
        const topic = sessionStorage.getItem(TOPIC_KEY);
        sessionStorage.removeItem(TOPIC_KEY);
        return topic;
    } catch {
        return null;
    }
};

document.addEventListener('DOMContentLoaded', initStage);
