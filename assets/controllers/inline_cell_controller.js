import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String, token: String, field: String };
    static targets = ['input', 'status'];

    connect() {
        this.saved = this.inputTarget.value;
        this.pending = null;
        this.onOutside = (event) => {
            if (!(event.target instanceof Node) || this.element.contains(event.target)) {
                return;
            }
            this.commit();
        };
        document.addEventListener('touchend', this.onOutside, true);
    }

    disconnect() {
        document.removeEventListener('touchend', this.onOutside, true);
    }

    commit() {
        const value = this.inputTarget.value;
        if (value === this.saved || this.pending === value) {
            return;
        }
        this.pending = value;
        this.send(value);
    }

    keydown(event) {
        if (event.key === 'Enter' || event.keyCode === 13) {
            event.preventDefault();
            this.commit();
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            if (this.pending !== null) {
                return;
            }
            this.inputTarget.value = this.saved;
            this.message('');
        }
    }

    async send(value) {
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('field', this.fieldValue);
        body.append('value', value);
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
            });
            let payload = {};
            try {
                payload = await response.json();
            } catch {
                payload = {};
            }
            if (!response.ok || !payload.ok) {
                this.inputTarget.value = this.saved;
                this.pending = null;
                this.message(payload.error || `Não foi possível salvar (${response.status}).`, true);
                return;
            }
            this.saved = payload.value ?? '';
            this.inputTarget.value = this.saved;
            this.pending = null;
            this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
            this.message('salvo ✓', false);
        } catch {
            this.inputTarget.value = this.saved;
            this.pending = null;
            this.message('Não foi possível salvar.', true);
        }
    }

    message(text, error = false) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.textContent = text;
        this.statusTarget.hidden = text === '';
        this.statusTarget.classList.toggle('is-error', error);
    }
}
