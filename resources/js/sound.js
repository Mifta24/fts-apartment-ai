/**
 * Small UI sounds, synthesised with the Web Audio API (no audio files):
 * a lift button beep and arrival chime when moving between floors, a soft "ding" for incoming messages and a
 * short "pop" when sending or toggling. Sound only ever follows a guest action, never plays on
 * its own, and can be muted with the header toggle (remembered per browser).
 */
const STORAGE_KEY = 'apartment_sound';

let context = null;
let master = null;

function isEnabled() {
    try {
        return localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

function ensureContext() {
    if (!context) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return null;

        context = new AudioContextClass();
        master = context.createGain();
        master.gain.value = 0.6;
        master.connect(context.destination);
    }

    if (context.state === 'suspended') context.resume().catch(() => {});

    return context;
}

function tone(frequency, start, duration, gain) {
    const oscillator = context.createOscillator();
    const amp = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    amp.gain.setValueAtTime(0.0001, start);
    amp.gain.exponentialRampToValueAtTime(gain, start + 0.012);
    amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    oscillator.connect(amp).connect(master);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.05);
}

/** A struck-bell voice: the note plus a quieter, shorter inharmonic partial. */
function bell(frequency, start, gain, duration) {
    tone(frequency, start, duration, gain);
    tone(frequency * 2.76, start, duration * 0.45, gain * 0.28);
}

/** A short rounded click: a sine that drops quickly in pitch, like a water-drop pop. */
function pop(fromFrequency, toFrequency, start, duration, gain) {
    const oscillator = context.createOscillator();
    const amp = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(fromFrequency, start);
    oscillator.frequency.exponentialRampToValueAtTime(toFrequency, start + duration);
    amp.gain.setValueAtTime(0.0001, start);
    amp.gain.exponentialRampToValueAtTime(gain, start + 0.004);
    amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    oscillator.connect(amp).connect(master);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.03);
}

const sounds = {
    /** Lift call: the button beep, then the two-tone arrival chime. */
    enter(now) {
        tone(1568, now, 0.09, 0.07);
        bell(1318.51, now + 0.16, 0.13, 0.9);
        bell(987.77, now + 0.5, 0.13, 1.1);
    },
    incoming(now) {
        bell(880, now, 0.11, 0.55);
        bell(1174.66, now + 0.09, 0.08, 0.5);
    },
    sent(now) {
        pop(1200, 420, now, 0.07, 0.16);
    },
    /** The concierge unveils something: a soft rising three-note shimmer. */
    reveal(now) {
        bell(784, now, 0.08, 0.5);
        bell(1046.5, now + 0.1, 0.08, 0.55);
        bell(1568, now + 0.2, 0.07, 0.8);
    },
};

function play(name) {
    if (!isEnabled() || !sounds[name]) return;

    try {
        const audio = ensureContext();
        if (audio) sounds[name](audio.currentTime + 0.02);
    } catch {
        /* audio unavailable: the interface works the same without it */
    }
}

function syncToggles() {
    const enabled = isEnabled();

    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(enabled));
        button.setAttribute('aria-label', enabled ? button.dataset.labelOn : button.dataset.labelOff);
        button.classList.toggle('is-muted', !enabled);
    });
}

function initSoundToggle() {
    syncToggles();

    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            try {
                localStorage.setItem(STORAGE_KEY, isEnabled() ? 'off' : 'on');
            } catch { /* preference just won't persist */ }

            syncToggles();
            play('sent');
        });
    });
}

window.apartmentSound = { play, isEnabled };

document.addEventListener('DOMContentLoaded', initSoundToggle);
