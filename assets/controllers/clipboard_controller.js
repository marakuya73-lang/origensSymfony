import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { text: String };
    static targets = ['idle', 'done'];

    async copy(event) {
        event.preventDefault();
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.idleTarget.hidden = true;
            this.doneTarget.hidden = false;
            window.setTimeout(() => {
                if (!this.element.isConnected) {
                    return;
                }
                this.idleTarget.hidden = false;
                this.doneTarget.hidden = true;
            }, 2200);
        } catch {
            if (!this.element.nextElementSibling?.matches('[data-copy-error]')) {
                this.element.insertAdjacentHTML('afterend', '<span data-copy-error role="alert" class="form-error">Selecione o texto para copiar.</span>');
            }
        }
    }
}
