import { Controller } from '@hotwired/stimulus';

const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 10 * 1024 * 1024;

export default class extends Controller {
    static targets = ['list', 'file', 'materials', 'materialTemplate', 'photoTemplate', 'error', 'uploadLabel', 'status'];
    static values = { uploadUrl: String, token: String, persisted: Boolean, max: { type: Number, default: 12 } };

    connect() {
        this.savedSnapshot = this.snapshot();
    }

    guard(event) {
        if (this.uploading) {
            event.preventDefault();
        }
    }

    save(event) {
        const next = event?.relatedTarget;
        if (next instanceof Element && this.element.contains(next) && next.matches('[data-submit], a.btn')) {
            return;
        }
        if (event?.target instanceof HTMLInputElement && event.target.type === 'file') {
            return;
        }
        this.persist();
    }

    async upload() {
        const files = Array.from(this.fileTarget.files || []);
        this.fileTarget.value = '';
        if (!files.length) {
            return;
        }
        if (this.photos().length + files.length > this.maxValue) {
            this.showError('Selecione até 12 fotos por peça.');
            return;
        }

        this.uploading = (this.uploading || 0) + 1;
        this.uploadLabelTarget.textContent = 'Enviando…';
        try {
            for (const file of files) {
                if (!TYPES.includes(file.type) || file.size > MAX_BYTES) {
                    this.showError('Use fotos JPG, PNG ou WebP de até 10 MB.');
                    continue;
                }
                const body = new FormData();
                body.append('image', file);
                body.append('_token', this.tokenValue);
                const response = await fetch(this.uploadUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
                let payload = {};
                try {
                    payload = await response.json();
                } catch {
                    payload = {};
                }
                if (!response.ok || !payload.path) {
                    this.showError(payload.error || 'Não foi possível enviar a foto. Tente novamente.');
                    continue;
                }
                this.appendPhoto(payload.path, payload.url);
            }
        } finally {
            this.uploading -= 1;
            if (!this.uploading) {
                this.uploadLabelTarget.textContent = 'Adicionar fotos';
                this.persist();
            }
        }
    }

    appendPhoto(path, url) {
        const node = this.photoTemplateTarget.content.firstElementChild.cloneNode(true);
        node.dataset.path = path;
        node.querySelector('img').src = url;
        node.querySelector('input').value = path;
        this.listTarget.appendChild(node);
        this.refreshPhotos();
    }

    move(event) {
        const item = event.currentTarget.closest('[data-photo]');
        if (!item) {
            return;
        }
        const sibling = event.params.direction === 'up' ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling?.matches('[data-photo]')) {
            return;
        }
        if (event.params.direction === 'up') {
            item.parentElement.insertBefore(item, sibling);
        } else {
            item.parentElement.insertBefore(sibling, item);
        }
        this.refreshPhotos();
        this.persist();
    }

    remove(event) {
        event.currentTarget.closest('[data-photo]')?.remove();
        this.refreshPhotos();
        this.persist();
    }

    refreshPhotos() {
        const photos = this.photos();
        photos.forEach((photo, index) => {
            const label = photo.querySelector('[data-photo-label]');
            if (label) {
                label.textContent = index === 0 ? 'Principal' : `Foto ${index + 1}`;
            }
            const image = photo.querySelector('img');
            if (image) {
                image.alt = `Foto ${index + 1}`;
            }
            const up = photo.querySelector('[data-move-up]');
            const down = photo.querySelector('[data-move-down]');
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === photos.length - 1;
            }
        });
    }

    addMaterial() {
        const html = this.materialTemplateTarget.innerHTML.replaceAll('__INDEX__', String(this.materialRows().length));
        this.materialsTarget.insertAdjacentHTML('beforeend', html);
        this.refreshMaterials();
    }

    removeMaterial(event) {
        const rows = this.materialRows();
        if (rows.length === 1) {
            return;
        }
        event.currentTarget.closest('[data-material]')?.remove();
        this.refreshMaterials();
        this.persist();
    }

    refreshMaterials() {
        const rows = this.materialRows();
        rows.forEach((row, index) => {
            const label = row.querySelector('[data-material-label]');
            if (label) {
                label.firstChild.textContent = `Material ${index + 1}`;
            }
            row.querySelector('[data-name]').name = `materials[${index}][name]`;
            row.querySelector('[data-note]').name = `materials[${index}][note]`;
            const remove = row.querySelector('[data-remove-material]');
            if (remove) {
                remove.disabled = rows.length === 1;
                remove.setAttribute('aria-label', `Remover material ${index + 1}`);
            }
        });
    }

    photos() {
        return [...this.listTarget.querySelectorAll(':scope > [data-photo]')];
    }

    materialRows() {
        return [...this.materialsTarget.querySelectorAll(':scope > [data-material]')];
    }

    showError(message) {
        this.errorTarget.hidden = false;
        this.errorTarget.textContent = message;
        this.setStatus('');
    }

    persist() {
        this.wanted = true;
        if (this.saving) {
            return;
        }
        this.saving = true;
        this.drain();
    }

    async drain() {
        try {
            while (this.wanted) {
                this.wanted = false;
                await this.send();
            }
        } finally {
            this.saving = false;
            if (this.wanted) {
                this.drain();
            }
        }
    }

    async send() {
        if (this.uploading) {
            return;
        }
        const current = this.snapshot();
        if (current === this.savedSnapshot) {
            return;
        }
        if (!this.ready()) {
            if (this.persistedValue) {
                this.showError('Confira o nome, os materiais, os cuidados, a data e envie pelo menos uma foto.');
            }
            return;
        }

        this.errorTarget.hidden = true;
        this.setStatus('Salvando…');
        const response = await fetch(this.element.action, {
            method: 'POST',
            body: new FormData(this.element),
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Origens-Autosave': '1' },
        });
        let payload = {};
        try {
            payload = await response.json();
        } catch {
            payload = {};
        }
        if (!response.ok || !payload.ok) {
            this.showError(payload.error || 'Não foi possível salvar a peça. Nenhum código existente foi alterado.');
            return;
        }

        this.savedSnapshot = this.snapshot();
        this.remember(payload);
        this.setStatus('Salvo');
    }

    remember(payload) {
        if (this.persistedValue || !payload.editUrl) {
            return;
        }
        this.persistedValue = true;
        this.element.action = payload.editUrl;
        this.element.querySelectorAll('[data-identity]').forEach((field) => {
            field.disabled = true;
        });
        const title = this.element.querySelector('.editor-title');
        if (title) {
            title.textContent = 'Editar artefacto';
        }
        const kicker = this.element.querySelector('.editor-kicker');
        if (kicker && payload.code) {
            kicker.textContent = payload.code;
        }
        const button = this.element.querySelector('[data-submit]');
        if (button) {
            button.textContent = 'Salvar alterações';
        }
    }

    ready() {
        const value = (name) => this.element.querySelector(`[name="${name}"]`)?.value.trim() ?? '';
        const names = [...this.element.querySelectorAll('[data-name]')].some((input) => input.value.trim() !== '');

        return value('title') !== '' && value('record_date') !== '' && value('care') !== '' && value('handmade') !== '' && names && this.photos().length > 0;
    }

    snapshot() {
        const parts = [];
        for (const [key, value] of new FormData(this.element).entries()) {
            if (value instanceof File) {
                continue;
            }
            parts.push(`${key}=${value}`);
        }

        return parts.join('&');
    }

    setStatus(message) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.hidden = message === '';
        this.statusTarget.textContent = message;
    }
}
