import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['submit', 'all', 'total'];

    connect() {
        this.element.addEventListener('input', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.closest('.row-price, .row-suggested')) {
                this.sync();
            }
        });
        this.sync();
    }

    toggleAll(event) {
        const checked = event.target.checked;
        this.boxes().forEach((box) => {
            box.checked = checked;
        });
        this.sync();
    }

    sync() {
        const boxes = this.boxes();
        const selected = boxes.filter((box) => box.checked);
        if (this.hasAllTarget) {
            this.allTarget.checked = boxes.length > 0 && selected.length === boxes.length;
            this.allTarget.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
        this.renderTotal(selected);
    }

    renderTotal(selected) {
        if (!this.hasTotalTarget) {
            return;
        }
        if (selected.length === 0) {
            this.totalTarget.hidden = true;
            this.totalTarget.textContent = '';
            return;
        }
        let cents = 0;
        let suggested = 0;
        let missing = 0;
        selected.forEach((box) => {
            const price = this.centsFor(box, '.row-price input', 'cents');
            const offered = this.centsFor(box, '.row-suggested input', 'suggestedCents');
            if (price === null) {
                missing += 1;
            } else {
                cents += price;
            }
            if (offered !== null) {
                suggested += offered;
            }
        });
        const count = selected.length === 1 ? '1 selecionado' : `${selected.length} selecionados`;
        let text = `${count} · Total ${this.reais(cents)} · Sugerido ${this.reais(suggested)}`;
        if (missing > 0) {
            text += ` · sem preço: ${missing}`;
        }
        this.totalTarget.textContent = text;
        this.totalTarget.hidden = false;
    }

    centsFor(box, selector = '.row-price input', datasetKey = 'cents') {
        const row = box.closest('.record-row');
        const input = row?.querySelector(selector);
        if (input instanceof HTMLInputElement && input.value.trim() !== '') {
            const parsed = this.parseCents(input.value);
            if (parsed !== null) {
                return parsed;
            }
        }
        if (input instanceof HTMLInputElement && input.value.trim() === '') {
            return null;
        }
        const stored = row instanceof HTMLElement ? row.dataset[datasetKey] ?? '' : '';
        if (stored === '') {
            return null;
        }
        const parsed = Number(stored);

        return Number.isFinite(parsed) ? parsed : null;
    }

    parseCents(raw) {
        let value = raw.replace(/R\$/g, '').replace(/\s/g, '').trim();
        if (value === '') {
            return null;
        }
        if (value.includes(',')) {
            value = value.replace(/\./g, '').replace(',', '.');
        } else if (/^\d{1,3}(\.\d{3})+$/.test(value)) {
            value = value.replace(/\./g, '');
        }
        if (!/^\d+(\.\d{1,2})?$/.test(value)) {
            return null;
        }
        const [reais, fraction = '0'] = value.split('.');

        return (Number(reais) * 100) + Number(fraction.padEnd(2, '0').slice(0, 2));
    }

    reais(cents) {
        const formatted = (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        return `R$ ${formatted}`;
    }

    boxes() {
        return [...this.element.querySelectorAll('input[name="ids[]"]')];
    }
}
