import { Controller } from '@hotwired/stimulus';

const MAX_AUDIO = 10 * 1024 * 1024;
const MAX_RECORDING = 180000;

export default class extends Controller {
    static targets = ['notes', 'generate', 'error', 'status', 'chat', 'thread', 'composer', 'reply', 'send', 'done', 'resume'];
    static values = { url: String, token: String };

    connect() {
        this.history = [];
        this.slots = {
            intro: { clip: null, file: null, url: '' },
            reply: { clip: null, file: null, url: '' },
        };
        this.activeSlot = null;
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            this.element.querySelectorAll('.record-btn').forEach((button) => {
                button.hidden = true;
            });
        }
    }

    disconnect() {
        this.stopRecording();
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

        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type)) || '';
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
            if (event.data.size > 0) {
                this.chunks.push(event.data);
            }
        });
        this.recorder.addEventListener('stop', () => this.finishRecording());
        this.recorder.start();
        this.slots[slot].file = null;
        button.classList.add('is-recording');
        button.classList.remove('has-clip');
        button.setAttribute('aria-pressed', 'true');
        this.startedAt = Date.now();
        this.paintTimer();
        this.timer = window.setInterval(() => this.paintTimer(), 250);
        this.limit = window.setTimeout(() => this.stopRecording(), MAX_RECORDING);
    }

    stopRecording() {
        window.clearInterval(this.timer);
        window.clearTimeout(this.limit);
        this.timer = null;
        if (this.recorder?.state === 'recording') {
            this.recorder.stop();
            return;
        }
        this.releaseStream();
    }

    finishRecording() {
        const slot = this.activeSlot;
        const button = this.recordButton;
        this.releaseStream();
        window.clearInterval(this.timer);
        if (button) {
            button.classList.remove('is-recording');
            button.setAttribute('aria-pressed', 'false');
        }
        const type = this.recorder?.mimeType || 'audio/webm';
        const blob = new Blob(this.chunks || [], { type });
        this.chunks = [];
        this.activeSlot = null;
        if (!slot || !blob.size) {
            this.paintIdle(slot, button);
            this.showError('A gravação ficou vazia. Tente novamente ou envie um arquivo.');
            return;
        }
        this.slots[slot].clip = blob;
        this.slots[slot].file = null;
        this.showReady(slot, blob);
        this.paintIdle(slot, button);
    }

    chooseFile(event) {
        const slot = this.slotOf(event);
        const file = event.target.files?.[0];
        if (!slot || !file) {
            return;
        }
        if (file.size > MAX_AUDIO) {
            event.target.value = '';
            this.showError('Use um áudio de até 10 MB.');
            return;
        }
        this.slots[slot].clip = null;
        this.slots[slot].file = file;
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

    sendClip(event) {
        event.preventDefault();
        const slot = this.slotOf(event);
        if (slot === 'reply') {
            this.reply(event);
            return;
        }
        if (!this.audioFor(true)) {
            this.showError('Grave ou escolha um áudio antes de enviar.');
            return;
        }
        this.history = [];
        this.ask({ first: true });
    }

    async generate(event) {
        event.preventDefault();
        this.history = [];
        await this.ask({ first: true });
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
        if (this.recorder?.state === 'recording') {
            this.showError('Pare a gravação antes de continuar.');
            return;
        }

        const image = this.element.querySelector('[name="images[]"]')?.value || '';
        if (!image) {
            this.showError('Envie pelo menos uma foto da peça antes de gerar o perfil.');
            return;
        }

        const editor = this.application.getControllerForElementAndIdentifier(this.element, 'artefact-editor');
        if (!editor || typeof editor.applyProfile !== 'function') {
            this.showError('Não foi possível preencher o formulário. Recarregue a página.');
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
        if (audio) {
            const name = audio instanceof File && audio.name ? audio.name : this.filename(audio);
            body.append('audio', audio, name);
        }

        this.generateTarget.disabled = true;
        this.sendTargets.forEach((button) => {
            button.disabled = true;
        });
        this.clearMessage();
        this.setStatus(first ? 'Gerando perfil…' : 'Atualizando perfil…');
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
            if (!response.ok || !payload.ok || !payload.profile) {
                this.showError(payload.error || 'Não foi possível gerar o perfil agora. Tente novamente em instantes.');
                return;
            }
            editor.applyProfile(payload.profile);
            this.remember(first, answer, Boolean(audio), payload);
            this.discardQuiet(first ? 'intro' : 'reply');
            if (this.hasReplyTarget) {
                this.replyTarget.value = '';
            }
            this.showChat(payload);
            this.openPanel();
            this.setStatus(payload.complete ? 'Perfil completo. Nada foi salvo.' : 'Perfil atualizado. Nada foi salvo.');
        } catch {
            this.showError('Não foi possível gerar o perfil agora. Tente novamente em instantes.');
        } finally {
            this.generateTarget.disabled = false;
            this.sendTargets.forEach((button) => {
                button.disabled = false;
            });
        }
    }

    audioFor(first) {
        const slot = this.slots[first ? 'intro' : 'reply'];
        if (!slot) {
            return null;
        }

        return slot.clip || slot.file || null;
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
    }

    paintTimer() {
        if (!this.recordButton || !this.startedAt) {
            return;
        }
        const elapsed = Math.min(MAX_RECORDING, Date.now() - this.startedAt);
        const seconds = Math.floor(elapsed / 1000);
        const label = this.recordButton.querySelector('[data-record-label]');
        if (label) {
            label.textContent = `Gravando… ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
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
        this.errorTarget.hidden = false;
        this.errorTarget.textContent = message;
        this.setStatus('');
    }

    clearMessage() {
        this.errorTarget.hidden = true;
        this.errorTarget.textContent = '';
    }

    setStatus(message) {
        this.statusTarget.hidden = message === '';
        this.statusTarget.textContent = message;
    }
}
