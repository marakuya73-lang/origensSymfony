import { Controller } from '@hotwired/stimulus';

const supportsSpeech = () => typeof window.speechSynthesis !== 'undefined'
    && typeof window.SpeechSynthesisUtterance === 'function';

let generation = 0;
let current = null;
let watching = false;

function stopSpeaking() {
    generation += 1;
    const previous = current;
    current = null;
    if (previous) {
        previous.mark(false);
    }
    if (supportsSpeech()) {
        window.speechSynthesis.cancel();
    }
}

function watchPage() {
    if (watching || !supportsSpeech()) {
        return;
    }
    watching = true;
    window.speechSynthesis.getVoices();
    window.speechSynthesis.addEventListener('voiceschanged', () => {
        window.speechSynthesis.getVoices();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopSpeaking();
        }
    });
    window.addEventListener('pagehide', () => stopSpeaking());
}

function pickVoice(voices) {
    const brazilian = voices.filter((voice) => /^pt[-_]?BR$/i.test(voice.lang));
    const score = (voice) => {
        const name = voice.name.toLowerCase();
        let rank = 0;
        if (name.includes('natural')) {
            rank += 4;
        }
        if (/neural|premium|enhanced|wavenet/.test(name)) {
            rank += 3;
        }
        if (voice.default) {
            rank += 1;
        }
        return rank;
    };

    return brazilian.sort((left, right) => score(right) - score(left))[0] || null;
}

function sentences(text) {
    const normalized = text.replace(/\s+/g, ' ').trim();
    if (normalized === '') {
        return [];
    }
    const parts = [];
    let buffer = '';
    for (const character of normalized) {
        buffer += character;
        const ended = /[.!?…]/.test(character);
        if (ended || buffer.length >= 160) {
            if (!ended) {
                const space = buffer.lastIndexOf(' ');
                if (space > 40) {
                    parts.push(buffer.slice(0, space).trim());
                    buffer = buffer.slice(space + 1);
                    continue;
                }
            }
            const piece = buffer.trim();
            if (piece !== '') {
                parts.push(piece);
            }
            buffer = '';
        }
    }
    if (buffer.trim() !== '') {
        parts.push(buffer.trim());
    }

    return parts;
}

export default class extends Controller {
    connect() {
        this.supported = supportsSpeech();
        if (!this.supported) {
            return;
        }
        this.element.classList.add('can-speak');
        watchPage();
    }

    disconnect() {
        if (current === this) {
            stopSpeaking();
        }
    }

    toggle(event) {
        if (!this.supported) {
            return;
        }
        if (event.target instanceof Element && event.target.closest('a, button')) {
            return;
        }
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed && this.element.contains(selection.anchorNode)) {
            return;
        }
        if (current === this) {
            stopSpeaking();
            return;
        }
        this.start();
    }

    start() {
        stopSpeaking();
        const chunks = sentences(this.plain());
        if (chunks.length === 0) {
            return;
        }
        const mine = generation;
        current = this;
        this.mark(true);
        window.speechSynthesis.resume();
        const voice = pickVoice(window.speechSynthesis.getVoices());
        chunks.forEach((chunk, index) => {
            const utterance = new SpeechSynthesisUtterance(chunk);
            utterance.lang = 'pt-BR';
            utterance.rate = 0.95;
            if (voice) {
                utterance.voice = voice;
            }
            utterance.onend = () => {
                if (mine !== generation || index !== chunks.length - 1) {
                    return;
                }
                this.mark(false);
                if (current === this) {
                    current = null;
                }
            };
            utterance.onerror = () => {
                if (mine !== generation) {
                    return;
                }
                this.mark(false);
                if (current === this) {
                    current = null;
                }
            };
            window.speechSynthesis.speak(utterance);
        });
        if (window.speechSynthesis.paused) {
            window.speechSynthesis.resume();
        }
    }

    plain() {
        const copy = this.element.cloneNode(true);
        copy.querySelectorAll('br').forEach((br) => br.replaceWith(' '));

        return copy.textContent || '';
    }

    mark(reading) {
        this.element.classList.toggle('is-reading', reading);
    }
}
