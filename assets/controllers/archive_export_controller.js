import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['submit', 'all'];

    connect() {
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
        const selected = boxes.filter((box) => box.checked).length;
        if (this.hasAllTarget) {
            this.allTarget.checked = boxes.length > 0 && selected === boxes.length;
            this.allTarget.indeterminate = selected > 0 && selected < boxes.length;
        }
    }

    boxes() {
        return [...this.element.querySelectorAll('input[name="ids[]"]')];
    }
}
