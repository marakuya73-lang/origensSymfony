import { Controller } from '@hotwired/stimulus';

const MAX_AUDIO = 10 * 1024 * 1024;
const MAX_RECORDING = 180000;

export default class extends Controller {
    static targets = ['notes', 'record', 'stop', 'file', 'clear', 'preview', 'audioState', 'generate', 'error', 'status', 'chat', 'thread', 'composer', 'reply', 'send', 'done', 'resume'];
    static values = { url: String, token: String };

    connect() {
        this.clip = null;
        this.chunks = [];
        this.previewUrl = '';
        this.audioFresh = false;
        this.history = [];
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            this.recordTarget.hidden = true;
        }
    }

    disconnect() {
        this.stop();
        this.revokePreview();
    }

    async record() {
        if (this.recorder?.state === 'recording') {
            return;
        }
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

        this.stream = stream;
        this.chunks = [];
        this.recorder.addEventListener('dataavailable', (event) => {
            if (event.data.size > 0) {
                this.chunks.push(event.data);
            }
        });
        this.recorder.addEventListener('stop', () => this.finishRecording());
        this.recorder.start();
        this.recordTarget.hidden = true;
        this.stopTarget.hidden = false;
        this.setAudioState('Gravando…');
        this.limit = window.setTimeout(() => this.stop(), MAX_RECORDING);
    }

    stop() {
        window.clearTimeout(this.limit);
        if (this.recorder?.state === 'recording') {
            this.recorder.stop();
            return;
        }
        this.releaseStream();
    }

    finishRecording() {
        this.releaseStream();
        const type = this.recorder?.mimeType || 'audio/webm';
        const blob = new Blob(this.chunks, { type });
        this.chunks = [];
        this.recordTarget.hidden = false;
        this.stopTarget.hidden = true;
        if (!blob.size) {
            this.showError('A gravação ficou vazia. Tente novamente ou envie um arquivo.');
            return;
        }
        this.clip = blob;
        this.audioFresh = true;
        this.fileTarget.value = '';
        this.showClip(blob, 'Gravação pronta para enviar.');
    }

    chooseFile() {
        const file = this.fileTarget.files?.[0];
        if (!file) {
            return;
        }
        if (file.size > MAX_AUDIO) {
            this.fileTarget.value = '';
            this.showError('Use um áudio de até 10 MB.');
            return;
        }
        this.clip = null;
        this.audioFresh = true;
        this.showClip(file, file.name);
    }

    clearAudio() {
        this.clip = null;
        this.audioFresh = false;
        this.fileTarget.value = '';
        this.previewTarget.hidden = true;
        this.previewTarget.removeAttribute('src');
        this.clearTarget.hidden = true;
        this.audioStateTarget.hidden = true;
        this.revokePreview();
    }

    async generate(event) {
        event.preventDefault();
        this.history = [];
        await this.ask({ first: true });
    }

    async reply(event) {
        event.preventDefault();
        const answer = this.replyTarget.value.trim();
        if (!answer && !this.audioFor(false)) {
            this.showError('Escreva uma resposta ou envie um áudio.');
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
        body.append('notes', first ? this.notesTarget.value : '');
        body.append('answer', first ? '' : answer);
        body.append('history', JSON.stringify(this.history));
        body.append('form', JSON.stringify(typeof editor.currentForm === 'function' ? editor.currentForm() : {}));
        body.append('touched', JSON.stringify(typeof editor.touchedFields === 'function' ? editor.touchedFields() : []));
        if (audio) {
            const name = audio instanceof File && audio.name ? audio.name : this.filename(audio);
            body.append('audio', audio, name);
        }

        this.generateTarget.disabled = true;
        this.sendTarget.disabled = true;
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
            this.clearAudio();
            if (this.hasReplyTarget) {
                this.replyTarget.value = '';
            }
            this.showChat(payload);
            this.setStatus(payload.complete ? 'Perfil completo. Nada foi salvo.' : 'Perfil atualizado. Nada foi salvo.');
        } catch {
            this.showError('Não foi possível gerar o perfil agora. Tente novamente em instantes.');
        } finally {
            this.generateTarget.disabled = false;
            this.sendTarget.disabled = false;
        }
    }

    audioFor(first) {
        const audio = this.clip || this.fileTarget.files?.[0] || null;
        if (!audio) {
            return null;
        }

        return first || this.audioFresh ? audio : null;
    }

    remember(first, answer, hadAudio, payload) {
        let userText = first ? (this.notesTarget.value.trim() || 'Sem notas escritas.') : (answer || 'Resposta em áudio.');
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

    showClip(blob, label) {
        this.revokePreview();
        this.previewUrl = URL.createObjectURL(blob);
        this.previewTarget.src = this.previewUrl;
        this.previewTarget.hidden = false;
        this.clearTarget.hidden = false;
        this.setAudioState(label);
    }

    setAudioState(message) {
        this.audioStateTarget.hidden = false;
        this.audioStateTarget.textContent = message;
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

    revokePreview() {
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = '';
        }
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
