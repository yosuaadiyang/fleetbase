import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { chatSafetyError, validChatSafetyReport } from '../utils/chat-safety-request';

export default class ChatSafetyReportComponent extends Component {
    @service chatSafety;
    @tracked reason = '';
    @tracked details = '';
    @tracked share = false;
    @tracked feedback = '';
    @tracked saved = false;
    @tracked pending = false;
    @tracked unreadable = false;
    journal = null;
    storageKey = null;
    reasons = [
        { id: 'harassment', label: 'Pelecehan atau kebencian' },
        { id: 'threat', label: 'Ancaman' },
        { id: 'illegal', label: 'Materi ilegal atau eksploitasi' },
        { id: 'privacy', label: 'Pelanggaran privasi' },
        { id: 'spam', label: 'Spam atau penipuan' },
        { id: 'other', label: 'Alasan lain' },
    ];
    constructor() {
        super(...arguments);
        const target = this.args.selection.target;
        const scope = this.chatSafety.capture();
        this.storageKey = 'contrust:chat-report:' + encodeURIComponent(JSON.stringify([scope.host, scope.user, scope.company, target.type, target.id]));
        try {
            const raw = window.sessionStorage.getItem(this.storageKey);
            if (raw !== null) {
                const value = JSON.parse(raw);
                if (!validChatSafetyReport(value) || value.target !== target.id || value.type !== target.type) throw new Error();
                this.journal = value;
                this.reason = value.reason;
                this.details = value.details ?? '';
            }
        } catch {
            this.unreadable = true;
            this.feedback = 'Draf belum dapat dibaca. Hubungi dukungan untuk memeriksa penyimpanan browser.';
        }
    }
    get current() {
        return !this.isDestroying && !this.isDestroyed && this.args.selection.marker === this.chatSafety.marker;
    }
    get locked() {
        return !!this.journal || this.pending || this.saved || this.unreadable;
    }
    get cannotSubmit() {
        return (this.locked && !this.journal) || this.pending || this.saved || this.unreadable || !this.reason || !this.share || this.chatSafety.busy;
    }
    @action changeReason(event) {
        if (!this.locked) this.reason = event.target.value;
    }
    @action changeDetails(event) {
        if (!this.locked) this.details = event.target.value;
    }
    @action changeShare(event) {
        this.share = event.target.checked;
    }
    @action close() {
        this.chatSafety.clearSelection(this.args.selection.marker);
    }
    @action async submit() {
        if (!this.current || this.cannotSubmit) return;
        this.pending = true;
        this.feedback = '';
        try {
            const target = this.args.selection.target;
            const input = this.journal ?? { request_id: window.crypto.randomUUID(), type: target.type, target: target.id, reason: this.reason, details: this.details, share_context: true };
            if (!validChatSafetyReport(input)) throw chatSafetyError();
            const previous = window.sessionStorage.getItem(this.storageKey);
            if (previous !== null && previous !== JSON.stringify(input)) {
                // Another tab/form must never silently replace an uncertain operation.
                throw chatSafetyError('CHAT_REPORT_CONFLICT');
            }
            const raw = JSON.stringify(input);
            window.sessionStorage.setItem(this.storageKey, raw);
            if (window.sessionStorage.getItem(this.storageKey) !== raw) throw chatSafetyError();
            this.journal = input;
            await this.chatSafety.report(input, this.args.selection.marker);
            if (this.current) {
                this.saved = true;
                let cleaned = false;
                try {
                    if (window.sessionStorage.getItem(this.storageKey) === raw) {
                        window.sessionStorage.removeItem(this.storageKey);
                        cleaned = true;
                    }
                } catch {
                    /* Keep the acknowledged nonce if local cleanup fails. */
                }
                this.feedback = cleaned ? 'Laporan diterima pengelola organisasi.' : 'Laporan diterima. Draf lokal perlu diperiksa.';
            }
        } catch (failure) {
            if (this.current) this.feedback = chatSafetyError(failure?.code).message + ' Draf dipertahankan; periksa sebelum mengonfirmasi ulang.';
        } finally {
            if (this.current) this.pending = false;
        }
    }
}
