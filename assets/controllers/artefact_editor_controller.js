import { Controller } from '@hotwired/stimulus';

const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 10 * 1024 * 1024;

export default class extends Controller {
    static targets = ['list', 'file', 'materials', 'materialTemplate', 'photoTemplate', 'error', 'uploadLabel', 'status', 'locked'];
    static values = { uploadUrl: String, token: String, persisted: Boolean, max: { type: Number, default: 12 } };

    connect() {
        this.savedSnapshot = this.snapshot();
        this.reviewing = false;
        this.touched = new Set();
        this.element.addEventListener('input', (event) => {
            this.noteTouch(event);
            this.refreshSuggestedWarning();
        });
        this.element.addEventListener('change', (event) => this.noteTouch(event));
        this.refreshSuggestedWarning();
        this.element.addEventListener('keydown', (event) => this.onKey(event));
        this.onOutside = (event) => {
            const active = document.activeElement;
            if (!(active instanceof HTMLElement) || !this.element.contains(active)) {
                return;
            }
            if (event.target instanceof Node && this.element.contains(event.target)) {
                return;
            }
            this.persist();
        };
        document.addEventListener('touchend', this.onOutside, true);
        this.element.querySelector('[data-submit]')?.addEventListener('touchend', () => this.blurFocused());
    }

    disconnect() {
        document.removeEventListener('touchend', this.onOutside, true);
    }

    refreshSuggestedWarning() {
        const warning = this.element.querySelector('[data-suggested-warning]');
        if (!(warning instanceof HTMLElement)) {
            return;
        }
        const price = this.parseCents(this.element.querySelector('[name="sale_price"]')?.value ?? '');
        const suggested = this.parseCents(this.element.querySelector('[name="suggested_price"]')?.value ?? '');
        const floor = price === null ? null : Math.floor((price * 13 + 5) / 10);
        warning.hidden = !(floor !== null && suggested !== null && suggested < floor);
    }

    parseCents(raw) {
        let value = String(raw).replace(/R\$/g, '').replace(/\s/g, '').trim();
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

    onKey(event) {
        if (event.key !== 'Enter' && event.keyCode !== 13) {
            return;
        }
        if (!(event.target instanceof HTMLInputElement) || event.target.type === 'file') {
            return;
        }
        this.persist();
    }

    blurFocused() {
        const active = document.activeElement;
        if (active instanceof HTMLElement && this.element.contains(active)) {
            active.blur();
        }
    }

    noteTouch(event) {
        if (!event.isTrusted || !(event.target instanceof Element) || event.target.closest('[data-assist]')) {
            return;
        }
        const field = this.fieldName(event.target);
        if (field) {
            this.touched.add(field);
            if (this.hasLockedTarget) {
                this.lockedTarget.value = JSON.stringify(this.touchedFields());
            }
        }
    }

    fieldName(target) {
        if (target.closest('[data-material]')) {
            return 'materials';
        }
        if (['title', 'description', 'symbolism', 'dimensions', 'care', 'handmade', 'size', 'category', 'sale_price', 'suggested_price'].includes(target.name)) {
            return target.name;
        }

        return null;
    }

    touchedFields() {
        return [...this.touched];
    }

    currentForm() {
        const value = (name) => this.element.querySelector(`[name="${name}"]`)?.value ?? '';
        const size = this.element.querySelector('input[name="size"]:checked');

        return {
            title: value('title'),
            description: value('description'),
            symbolism: value('symbolism'),
            dimensions: value('dimensions'),
            handmade: value('handmade'),
            care: value('care'),
            size: size instanceof HTMLInputElement ? size.value : '',
            materials: this.materialRows().map((row) => ({
                name: row.querySelector('[data-name]')?.value ?? '',
                note: row.querySelector('[data-note]')?.value ?? '',
            })),
        };
    }

    guard(event) {
        this.blurFocused();
        if (this.uploading) {
            event.preventDefault();
        }
    }

    save(event) {
        const next = event?.relatedTarget;
        if (next instanceof Element && this.element.contains(next) && next.matches('[data-submit], a.btn')) {
            return;
        }
        if (event?.target instanceof Element && event.target.closest('[data-assist]')) {
            return;
        }
        if (next instanceof Element && next.closest('[data-assist]')) {
            return;
        }
        if (event?.target instanceof HTMLInputElement && event.target.type === 'file') {
            return;
        }
        this.persist();
    }

    applyProfile(profile) {
        this.reviewing = true;
        this.fillEmpty('title', profile.title);
        this.fillEmpty('description', profile.description);
        this.fillEmpty('symbolism', profile.symbolism);
        this.fillEmpty('dimensions', profile.dimensions);
        this.fillEmpty('handmade', profile.handmade);
        const care = this.element.querySelector('[name="care"]');
        if (care instanceof HTMLTextAreaElement && care.value.trim() === '' && Array.isArray(profile.care)) {
            care.value = profile.care.filter((line) => typeof line === 'string' && line.trim() !== '').join('\n');
        }
        this.fillEmptyMaterialNotes(Array.isArray(profile.materials) ? profile.materials : []);
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = true;
        }
    }

    fillEmpty(name, value) {
        const field = this.element.querySelector(`[name="${name}"]`);
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
            return;
        }
        if (field.value.trim() !== '' || typeof value !== 'string' || value.trim() === '') {
            return;
        }
        field.value = value;
    }

    fillEmptyMaterialNotes(materials) {
        const rows = this.materialRows();
        const named = rows.some((row) => (row.querySelector('[data-name]')?.value || '').trim() !== '');
        if (!named) {
            this.replaceMaterials(materials);
            return;
        }
        rows.forEach((row) => {
            const name = (row.querySelector('[data-name]')?.value || '').trim().toLowerCase();
            const note = row.querySelector('[data-note]');
            if (!name || !(note instanceof HTMLTextAreaElement) || note.value.trim() !== '') {
                return;
            }
            const match = materials.find((item) => item && typeof item.name === 'string' && item.name.trim().toLowerCase() === name && typeof item.note === 'string' && item.note.trim() !== '');
            if (match) {
                note.value = match.note;
            }
        });
    }

    write(name, value) {
        const field = this.element.querySelector(`[name="${name}"]`);
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
            field.value = typeof value === 'string' ? value : '';
        }
    }

    replaceMaterials(materials) {
        const rows = materials
            .filter((row) => row && typeof row.name === 'string' && row.name.trim() !== '')
            .slice(0, 30);
        if (!rows.length) {
            return;
        }
        this.materialsTarget.replaceChildren();
        rows.forEach(() => this.addMaterial());
        this.materialRows().forEach((row, index) => {
            const name = row.querySelector('[data-name]');
            const note = row.querySelector('[data-note]');
            if (name) {
                name.value = rows[index].name;
            }
            if (note) {
                note.value = typeof rows[index].note === 'string' ? rows[index].note : '';
            }
        });
        this.refreshMaterials();
    }

    applySize(size) {
        if (size !== 'P' && size !== 'M' && size !== 'G') {
            return;
        }
        const input = this.element.querySelector(`input[name="size"][value="${size}"]`);
        if (!(input instanceof HTMLInputElement) || input.closest('fieldset')?.disabled) {
            return;
        }
        input.checked = true;
    }

    async prepareUpload(file) {
        if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') {
            return file;
        }
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const longest = Math.max(bitmap.width, bitmap.height);
            const scale = Math.min(1, 2000 / longest);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            const context = canvas.getContext('2d');
            if (!context) {
                bitmap.close?.();
                return file;
            }
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close?.();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
            if (!blob) {
                return file;
            }

            return new File([blob], 'foto.jpg', { type: 'image/jpeg' });
        } catch {
            return file;
        }
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
                const prepared = await this.prepareUpload(file);
                const body = new FormData();
                body.append('image', prepared);
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
                this.uploadLabelTarget.textContent = 'Adicionar outra foto';
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
        if (this.reviewing) {
            return;
        }
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
        const body = new FormData();
        for (const [key, value] of this.entries()) {
            body.append(key, value);
        }
        const response = await fetch(this.element.action, {
            method: 'POST',
            body,
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
        this.element.dispatchEvent(new CustomEvent('origens:saved', { detail: payload }));
        this.setStatus('Salvo');
    }

    remember(payload) {
        if (this.persistedValue || !payload.editUrl) {
            return;
        }
        this.persistedValue = true;
        this.element.action = payload.editUrl;
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

    entries() {
        const rows = [];
        for (const element of this.element.elements) {
            if (!(element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement)) {
                continue;
            }
            if (!element.name || element.disabled || element.type === 'file' || element.name === 'generate') {
                continue;
            }
            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
                continue;
            }
            rows.push([element.name, element.value]);
        }

        return rows;
    }

    snapshot() {
        return this.entries().map(([key, value]) => `${key}=${value}`).join('&');
    }

    setStatus(message) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.hidden = message === '';
        this.statusTarget.textContent = message;
    }
}
