import { Controller } from '@hotwired/stimulus';

const MAX_AUDIO = 10 * 1024 * 1024;
const MAX_RECORDING = 180000;

export default class extends Controller {
    static targets = ['notes', 'generate', 'toast', 'toastMessage', 'status', 'chat', 'thread', 'composer', 'reply', 'send', 'done', 'resume', 'link', 'badge', 'cancel'];
    static values = { url: String, token: String };

    connect() {
        this.history = [];
        this.slots = {
            intro: { clip: null, file: null, url: '' },
            reply: { clip: null, file: null, url: '' },
        };
        this.activeSlot = null;
        this.onVisibility = () => this.handleVisibility();
        document.addEventListener('visibilitychange', this.onVisibility);
        this.resumeJob();
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            this.element.querySelectorAll('.record-btn').forEach((button) => {
                button.hidden = true;
            });
        }
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.stopRecording();
        this.releaseWakeLock();
        Object.keys(this.slots).forEach((slot) => this.revokeSlot(slot));
    }

    toggleRecord(event) {
        const slot = this.slotOf(event);
        if (!slot) {
            return;
        }
        if (this.recorder?.state === 'recording') {
            if (this.activeSlot === slot) {
                this.stopRecording();
            }
            return;
        }
        this.startRecording(slot, event.currentTarget);
        this.holdScreen();
    }

    async startRecording(slot, button) {
        this.clearMessage();
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            this.showError('Não foi possível acessar o microfone. Você pode enviar um arquivo de áudio.');
            return;
        }

        const safari = this.isSafari();
        const types = safari
            ? ['audio/mp4', 'audio/aac', 'audio/webm']
            : ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
        const mime = types.find((type) => MediaRecorder.isTypeSupported(type)) || '';
        try {
            this.recorder = mime === '' ? new MediaRecorder(stream) : new MediaRecorder(stream, { mimeType: mime });
        } catch {
            stream.getTracks().forEach((track) => track.stop());
            this.showError('Este navegador não conseguiu gravar o áudio. Envie um arquivo.');
            return;
        }

        this.activeSlot = slot;
        this.recordButton = button;
        this.stream = stream;
        this.chunks = [];
        this.recorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                this.chunks.push(event.data);
            }
        });
        this.recorder.addEventListener('stop', () => {
            window.setTimeout(() => this.assembleClip(), 150);
        }, { once: true });
        stream.getAudioTracks().forEach((track) => {
            track.addEventListener('ended', () => {
                if (this.recorder?.state === 'recording') {
                    this.stopRecording();
                }
            });
        });
        try {
            this.recorder.start(1000);
        } catch {
            this.recorder.start();
        }
        this.slots[slot].file = null;
        button.classList.add('is-recording');
        button.classList.remove('has-clip');
        button.setAttribute('aria-pressed', 'true');
        this.startedAt = Date.now();
        this.paintTimer();
        this.timer = window.setInterval(() => this.paintTimer(), 250);
        this.limit = window.setTimeout(() => this.stopRecording(), MAX_RECORDING);
        this.setGenerateEnabled(false);
    }

    holdScreen() {
        if (!navigator.wakeLock?.request || this.wakeLock) {
            return;
        }
        navigator.wakeLock.request('screen').then((lock) => {
            this.wakeLock = lock;
        }).catch(() => {
            this.wakeLock = null;
        });
    }

    releaseWakeLock() {
        const lock = this.wakeLock;
        this.wakeLock = null;
        if (!lock) {
            return;
        }
        lock.release().catch(() => {});
    }

    handleVisibility() {
        if (document.visibilityState === 'hidden' && this.recorder?.state === 'recording') {
            this.stopRecording();
            return;
        }
        if (document.visibilityState === 'visible' && this.recorder?.state === 'recording') {
            this.holdScreen();
        }
    }

    setGenerateEnabled(on) {
        if (!this.hasGenerateTarget || this.busy) {
            return;
        }
        const clip = this.slots.intro?.clip;
        const file = this.slots.intro?.file;
        const broken = (clip && clip.size <= 0) || (file && file.size <= 0);
        this.generateTarget.disabled = !on || broken;
    }

    stopRecording() {
        window.clearInterval(this.timer);
        window.clearTimeout(this.limit);
        this.timer = null;
        const recorder = this.recorder;
        if (!recorder || recorder.state !== 'recording' || this.stopping) {
            return;
        }
        this.stopping = true;
        this.capturedMs = Math.max(0, Date.now() - (this.startedAt || Date.now()));
        try {
            recorder.stop();
        } catch {
            this.stopping = false;
            this.assembleClip();
        }
    }

    assembleClip() {
        const slot = this.activeSlot;
        const button = this.recordButton;
        const type = this.recorder?.mimeType || (this.isSafari() ? 'audio/mp4' : 'audio/webm');
        const blob = new Blob(this.chunks || [], { type: type || 'audio/mp4' });
        this.chunks = [];
        this.stopping = false;
        this.activeSlot = null;
        this.releaseStream();
        this.releaseWakeLock();
        if (button) {
            button.classList.remove('is-recording');
            button.setAttribute('aria-pressed', 'false');
        }
        if (!slot || blob.size <= 0) {
            this.paintIdle(slot, button);
            this.setGenerateEnabled(true);
            this.showError('A gravação ficou vazia. Grave de novo por alguns segundos, ou envie um arquivo.');
            return;
        }
        this.slots[slot].clip = blob;
        this.slots[slot].file = null;
        this.slots[slot].durationMs = this.capturedMs || 0;
        if (slot === 'intro') {
            this.attachAudio(new File([blob], 'audio.webm', { type: blob.type || 'audio/webm' }));
        }
        this.showReady(slot, blob);
        this.paintIdle(slot, button);
        this.setGenerateEnabled(true);
    }

    chooseFile(event) {
        const slot = this.slotOf(event);
        const file = event.target.files?.[0];
        if (!slot || !file) {
            return;
        }
        if (file.size <= 0) {
            event.target.value = '';
            this.showError('A gravação ficou vazia. Grave de novo por alguns segundos, ou envie um arquivo.');
            return;
        }
        if (file.size > MAX_AUDIO) {
            event.target.value = '';
            this.showError('Use um áudio de até 10 MB.');
            return;
        }
        this.slots[slot].clip = null;
        this.slots[slot].file = file;
        this.slots[slot].durationMs = 0;
        if (slot === 'intro') {
            this.attachAudio(file);
        }
        this.showReady(slot, file);
        this.paintIdle(slot, event.currentTarget.closest('.recorder')?.querySelector('.record-btn'));
    }

    discardClip(event) {
        const slot = this.slotOf(event);
        if (!slot) {
            return;
        }
        if (this.recorder?.state === 'recording' && this.activeSlot === slot) {
            this.stopRecording();
        }
        this.slots[slot].clip = null;
        this.slots[slot].file = null;
        const root = this.root(slot);
        const input = root?.querySelector('input[type="file"]');
        if (input) {
            input.value = '';
        }
        const ready = root?.querySelector('.record-ready');
        const player = root?.querySelector('audio');
        if (player) {
            player.removeAttribute('src');
        }
        if (ready) {
            ready.hidden = true;
        }
        this.revokeSlot(slot);
        this.paintIdle(slot, root?.querySelector('.record-btn'));
    }

    async generate(event) {
        event.preventDefault();
        await this.saveAndGenerate(event);
    }

    async saveAndGenerate(event) {
        event.preventDefault();
        if (this.busy) {
            return;
        }
        if (this.recorder?.state === 'recording') {
            this.showError('A gravação ainda está aberta. Toque de novo no microfone para parar e depois gere o perfil.');
            return;
        }
        if (!this.element.querySelector('[name="images[]"]')?.value) {
            this.showError('Falta a foto principal. Toque em “Tirar ou escolher foto”, acima, e tente de novo.');
            return;
        }
        this.attachAudio(this.audioFor(true));
        const body = new FormData(this.element);
        body.set('generate', '1');
        const record = this.element.dataset.recordId;
        if (record) {
            body.set('artefact', record);
        }
        this.setBusy(true);
        this.clearMessage();
        this.setStatus('Salvando…');
        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
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
            if (!response.ok || !payload.ok || !payload.url) {
                this.setBusy(false);
                this.showError(payload.error || 'Não foi possível salvar a peça.');
                return;
            }
            this.showSaved(payload);
            this.setBusy(false);
            this.setStatus('Gravado. Pode registrar outra peça.');
            if (payload.job) {
                this.jobId = payload.job;
                this.rememberJob(payload.job);
                this.showCancel(true);
                this.poll(payload.job);
            }
        } catch {
            this.setBusy(false);
            this.showError('Não foi possível salvar a peça.');
        }
    }

    async reply(event) {
        event.preventDefault();
        const answer = this.hasReplyTarget ? this.replyTarget.value.trim() : '';
        if (!answer && !this.audioFor(false)) {
            this.showError('Escreva uma resposta ou grave um áudio.');
            return;
        }
        await this.ask({ first: false, answer });
    }

    endChat() {
        this.composerTarget.hidden = true;
        this.resumeTarget.hidden = false;
        this.setStatus('Conversa encerrada. Revise os campos e salve quando quiser.');
    }

    resume() {
        this.composerTarget.hidden = false;
        this.resumeTarget.hidden = true;
        this.doneTarget.hidden = true;
        this.replyTarget.focus();
    }

    async ask({ first, answer = '' }) {
        if (this.busy) {
            return;
        }
        this.pending = { first, answer };
        if (this.recorder?.state === 'recording') {
            this.showError('A gravação ainda está aberta. Toque de novo no microfone para parar e depois gere o perfil.');
            return;
        }

        const image = this.element.querySelector('[name="images[]"]')?.value || '';
        if (!image) {
            this.showError('Falta a foto principal. Toque em “Tirar ou escolher foto”, acima, e tente de novo.');
            return;
        }

        const editor = this.application.getControllerForElementAndIdentifier(this.element, 'artefact-editor');
        if (!editor || typeof editor.applyProfile !== 'function') {
            this.showError('Não foi possível preencher o formulário. Recarregue a página.');
            return;
        }
        if (editor.uploading) {
            this.showError('A foto ainda está a caminho. Espere a miniatura aparecer e toque em Gerar perfil de novo.');
            return;
        }

        const audio = this.audioFor(first);
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('image', image);
        body.append('notes', first && this.hasNotesTarget ? this.notesTarget.value : '');
        body.append('answer', first ? '' : answer);
        body.append('history', JSON.stringify(this.history));
        body.append('form', JSON.stringify(typeof editor.currentForm === 'function' ? editor.currentForm() : {}));
        body.append('touched', JSON.stringify(typeof editor.touchedFields === 'function' ? editor.touchedFields() : []));
        const record = this.element.dataset.recordId;
        if (record) {
            body.append('artefact', record);
        }
        if (audio) {
            const name = audio instanceof File && audio.name ? audio.name : this.filename(audio);
            body.append('audio', audio, name);
        }

        this.pending = { first, answer, hadAudio: Boolean(audio) };
        this.setBusy(true);
        this.dismissToast();
        this.clearMessage();
        this.setStatus(first ? 'Na fila…' : 'Atualizando…');
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
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
            if (!response.ok || !payload.ok || !payload.job) {
                this.setBusy(false);
                this.showError(payload.error || 'Não foi possível gerar o perfil agora. Tente novamente em instantes.');
                return;
            }
            this.rememberJob(payload.job);
            this.poll(payload.job);
        } catch {
            this.setBusy(false);
            this.showError('Não foi possível gerar o perfil agora. Tente novamente em instantes.');
        }
    }

    rememberJob(id) {
        const pending = this.pending || { first: true, answer: '', hadAudio: false };
        const recordId = this.element.dataset.recordId || '';
        localStorage.setItem('origens-profile-job', JSON.stringify({ id, recordId, ...pending }));
    }

    resumeJob() {
        const recordId = this.element.dataset.recordId || '';
        if (recordId === '') {
            localStorage.removeItem('origens-profile-job');
            return;
        }
        let saved = null;
        try {
            saved = JSON.parse(localStorage.getItem('origens-profile-job') || 'null');
        } catch {
            saved = null;
        }
        if (!saved?.id || saved.recordId !== recordId) {
            return;
        }
        this.pending = { first: saved.first !== false, answer: saved.answer || '', hadAudio: Boolean(saved.hadAudio) };
        this.poll(saved.id);
    }

    poll(id) {
        window.clearTimeout(this.jobTimer);
        this.jobTimer = window.setTimeout(() => this.readJob(id), 2500);
    }

    async readJob(id) {
        try {
            const response = await fetch(`${this.urlValue}/${id}`, {
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
                this.setBusy(false);
                this.showError(payload.error || 'Não foi possível gerar o perfil agora. Tente novamente em instantes.');
                return;
            }
            if (payload.status === 'cancelled') {
                this.clearJob();
                this.setStatus('Cancelado.');
                return;
            }
            if (payload.status === 'done' && payload.profile) {
                localStorage.removeItem('origens-profile-job');
                this.showCancel(false);
                this.finish(payload);
                return;
            }
            if (payload.status === 'failed') {
                localStorage.removeItem('origens-profile-job');
                this.jobId = id;
                this.setBusy(false);
                this.showCancel(false);
                this.paintBadge('falhou');
                this.showError(payload.error || 'Não foi possível gerar o perfil.');
                return;
            }
            this.showCancel(payload.status === 'queued' || payload.status === 'running');
            this.paintBadge(payload.status === 'done' ? 'pronto' : (payload.status === 'queued' ? 'aguardando IA' : 'gerando'));
            this.setStatus(payload.message || 'Gerando…');
            this.poll(id);
        } catch {
            this.poll(id);
        }
    }

    finish(payload) {
        const editor = this.application.getControllerForElementAndIdentifier(this.element, 'artefact-editor');
        const pending = this.pending || { first: true, answer: '', hadAudio: false };
        if (editor && typeof editor.applyProfile === 'function') {
            editor.applyProfile(payload.profile);
        }
        this.remember(pending.first, pending.answer, pending.hadAudio, payload);
        this.discardQuiet(pending.first ? 'intro' : 'reply');
        if (this.hasReplyTarget) {
            this.replyTarget.value = '';
        }
        this.showChat(payload);
        this.openPanel();
        this.setStatus(payload.complete ? 'Perfil completo. Nada foi salvo.' : 'Perfil atualizado. Nada foi salvo.');
        this.setBusy(false);
    }

    async cancel(event) {
        event.preventDefault();
        if (!this.jobId) {
            return;
        }
        window.clearTimeout(this.jobTimer);
        const body = new FormData();
        body.append('_token', this.tokenValue);
        try {
            await fetch(`${this.urlValue}/${this.jobId}/cancel`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
        } catch {
            this.showError('Não foi possível cancelar.');
            return;
        }
        this.clearJob();
        this.setStatus('Cancelado. Pode gerar de novo quando quiser.');
    }

    clearJob() {
        window.clearTimeout(this.jobTimer);
        this.jobId = null;
        localStorage.removeItem('origens-profile-job');
        this.showCancel(false);
        this.paintBadge('');
        this.setBusy(false);
    }

    showCancel(on) {
        if (this.hasCancelTarget) {
            this.cancelTarget.hidden = !on;
        }
    }

    async retry(event) {
        event.preventDefault();
        if (this.busy) {
            return;
        }
        if (!this.jobId) {
            if (this.pending) {
                this.ask(this.pending);
            }
            return;
        }
        const body = new FormData();
        body.append('_token', this.tokenValue);
        this.dismissToast();
        this.setStatus('Na fila…');
        this.paintBadge('aguardando IA');
        try {
            const response = await fetch(`${this.urlValue}/${this.jobId}/retry`, {
                method: 'POST',
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
                this.showError(payload.error || 'Não foi possível gerar o perfil.');
                return;
            }
            this.rememberJob(this.jobId);
            this.poll(this.jobId);
        } catch {
            this.showError('Não foi possível gerar o perfil.');
        }
    }

    showSaved(payload) {
        if (payload.id) {
            this.element.dataset.recordId = payload.id;
        }
        if (payload.editUrl) {
            this.element.action = payload.editUrl;
        }
        const editor = this.application.getControllerForElementAndIdentifier(this.element, 'artefact-editor');
        if (editor && typeof editor.remember === 'function') {
            editor.remember(payload);
        }
        if (this.hasLinkTarget && payload.url) {
            this.linkTarget.hidden = false;
            this.linkTarget.textContent = payload.url;
        }
        this.paintBadge(payload.ai || 'aguardando IA');
    }

    paintBadge(label) {
        if (!this.hasBadgeTarget || !label) {
            return;
        }
        this.badgeTarget.hidden = false;
        this.badgeTarget.textContent = label;
    }

    attachAudio(file) {
        if (!(file instanceof Blob) || file.size <= 0) {
            return;
        }
        let input = this.element.querySelector('input[data-saved-audio]');
        if (!(input instanceof HTMLInputElement)) {
            input = document.createElement('input');
            input.type = 'file';
            input.name = 'audio';
            input.hidden = true;
            input.dataset.savedAudio = '1';
            this.element.appendChild(input);
        }
        const named = file instanceof File ? file : new File([file], 'audio.webm', { type: file.type || 'audio/webm' });
        const transfer = new DataTransfer();
        transfer.items.add(named);
        input.files = transfer.files;
    }

    dismissToast() {
        if (this.hasToastTarget) {
            this.toastTarget.hidden = true;
        }
    }

    setBusy(on) {
        this.busy = on;
        if (this.hasGenerateTarget) {
            this.generateTarget.disabled = on;
            this.generateTarget.classList.toggle('is-busy', on);
            this.generateTarget.setAttribute('aria-busy', on ? 'true' : 'false');
            const label = this.generateTarget.querySelector('[data-generate-label]');
            if (label) {
                label.textContent = on ? 'Salvando…' : 'Salvar e gerar com IA';
            }
        }
        this.sendTargets.forEach((button) => {
            button.disabled = on;
        });
    }

    isSafari() {
        return /safari/i.test(navigator.userAgent) && !/chrome|crios|android|edg/i.test(navigator.userAgent);
    }

    audioFor(first) {
        const slot = this.slots[first ? 'intro' : 'reply'];
        if (!slot) {
            return null;
        }

        if (slot.clip && slot.clip.size > 0) {
            return slot.clip;
        }
        if (slot.file && slot.file.size > 0) {
            return slot.file;
        }

        return null;
    }

    remember(first, answer, hadAudio, payload) {
        let userText = first ? (this.hasNotesTarget ? this.notesTarget.value.trim() : '') || 'Sem notas escritas.' : (answer || 'Resposta em áudio.');
        if (hadAudio) {
            userText = first || !answer ? `${userText}\n(enviou um áudio)` : `${answer}\n(também enviou um áudio)`;
        }
        const questions = Array.isArray(payload.questions) ? payload.questions.filter((question) => typeof question === 'string' && question.trim() !== '') : [];
        const assistantText = payload.complete ? 'O perfil está completo.' : (questions.join('\n') || 'Sem perguntas novas.');
        this.history.push({ role: 'user', text: userText }, { role: 'assistant', text: assistantText });
        if (this.history.length > 16) {
            this.history = this.history.slice(-16);
        }
    }

    showChat(payload) {
        this.threadTarget.replaceChildren();
        this.history.forEach((turn) => {
            const bubble = document.createElement('p');
            bubble.className = `chat-bubble ${turn.role === 'assistant' ? 'assistant' : 'user'}`;
            bubble.textContent = turn.text;
            this.threadTarget.appendChild(bubble);
        });
        this.chatTarget.hidden = false;
        const complete = payload.complete === true;
        this.composerTarget.hidden = complete;
        this.doneTarget.hidden = !complete;
        this.resumeTarget.hidden = !complete;
        this.threadTarget.scrollTop = this.threadTarget.scrollHeight;
    }

    openPanel() {
        const panel = this.element.querySelector('details.assist-fold');
        if (panel) {
            panel.open = true;
        }
    }

    showReady(slot, blob) {
        const root = this.root(slot);
        const player = root?.querySelector('audio');
        const ready = root?.querySelector('.record-ready');
        if (!player || !ready) {
            return;
        }
        this.revokeSlot(slot);
        this.slots[slot].url = URL.createObjectURL(blob);
        player.src = this.slots[slot].url;
        ready.hidden = false;
        const duration = root.querySelector('[data-record-duration]');
        if (duration) {
            const ms = this.slots[slot].durationMs || 0;
            duration.hidden = ms <= 0;
            duration.textContent = ms > 0 ? this.clock(ms) : '';
        }
    }

    clock(ms) {
        const seconds = Math.max(0, Math.round(ms / 1000));

        return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    }

    paintTimer() {
        if (!this.recordButton || !this.startedAt) {
            return;
        }
        const elapsed = Math.min(MAX_RECORDING, Date.now() - this.startedAt);
        const label = this.recordButton.querySelector('[data-record-label]');
        if (label) {
            label.textContent = `Gravando… ${this.clock(elapsed)}`;
        }
    }

    paintIdle(slot, button) {
        if (!button) {
            return;
        }
        const label = button.querySelector('[data-record-label]');
        const hasClip = Boolean(slot && (this.slots[slot].clip || this.slots[slot].file));
        button.classList.toggle('has-clip', hasClip);
        button.classList.remove('is-recording');
        if (label) {
            label.textContent = hasClip ? 'Regravar' : 'Gravar';
        }
    }

    discardQuiet(slot) {
        const root = this.root(slot);
        this.slots[slot].clip = null;
        this.slots[slot].file = null;
        const input = root?.querySelector('input[type="file"]');
        if (input) {
            input.value = '';
        }
        const ready = root?.querySelector('.record-ready');
        const player = root?.querySelector('audio');
        if (player) {
            player.removeAttribute('src');
        }
        if (ready) {
            ready.hidden = true;
        }
        this.revokeSlot(slot);
        this.paintIdle(slot, root?.querySelector('.record-btn'));
    }

    slotOf(event) {
        return event.currentTarget?.closest('[data-slot]')?.dataset.slot || null;
    }

    root(slot) {
        return this.element.querySelector(`.recorder[data-slot="${slot}"]`);
    }

    revokeSlot(slot) {
        if (this.slots[slot]?.url) {
            URL.revokeObjectURL(this.slots[slot].url);
            this.slots[slot].url = '';
        }
    }

    filename(blob) {
        const type = blob.type || '';
        if (type.includes('mp4') || type.includes('m4a') || type.includes('aac')) {
            return 'descricao.m4a';
        }
        if (type.includes('mpeg') || type.includes('mp3')) {
            return 'descricao.mp3';
        }
        if (type.includes('ogg')) {
            return 'descricao.ogg';
        }
        if (type.includes('wav')) {
            return 'descricao.wav';
        }

        return 'descricao.webm';
    }

    releaseStream() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }

    showError(message) {
        if (this.hasToastMessageTarget) {
            this.toastMessageTarget.textContent = message;
        }
        if (this.hasToastTarget) {
            this.toastTarget.hidden = false;
        }
        this.setStatus('');
    }

    clearMessage() {
        this.dismissToast();
        if (this.hasToastMessageTarget) {
            this.toastMessageTarget.textContent = '';
        }
    }

    setStatus(message) {
        this.statusTarget.hidden = message === '';
        this.statusTarget.textContent = message;
    }
}
