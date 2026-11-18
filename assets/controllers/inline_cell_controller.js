import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String, token: String, field: String };
    static targets = ['input', 'status'];

    connect() {
        this.saved = this.inputTarget.value;
        this.pending = null;
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
        if (event.key === 'Enter') {
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
                method: 'PATCH',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
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
                this.message(payload.error || 'Não foi possível salvar.', true);
                return;
            }
            this.saved = payload.value ?? '';
            this.inputTarget.value = this.saved;
            this.pending = null;
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
