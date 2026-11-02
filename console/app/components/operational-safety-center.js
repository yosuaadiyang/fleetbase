import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { chatSafetyError, validOperationalPreview, validOperationalReport } from '../utils/chat-safety-request';

const types = [
    { id: 'order_comment', label: 'Komentar order' },
    { id: 'issue', label: 'Laporan kendala' },
    { id: 'fuel_report', label: 'Laporan bahan bakar' },
    { id: 'proof', label: 'Referensi POD' },
];
const identifier = (value) => typeof value === 'string' && /^[A-Za-z0-9_-]{1,150}$/.test(value);
const prefix = 'contrust:operational-report:v1:';
const validDraft = (value, type, target) =>
    value?.version === 1 &&
    validOperationalReport(value.data) &&
    value.data.type === type &&
    value.data.target === target &&
    validOperationalPreview(value.preview, { type, target }) &&
    value.preview.preview_receipt === value.data.preview_receipt &&
    value.preview.policy_version === value.data.policy_version;

/** A fixed, discoverable entry point across engine pages. It never submits on open. */
export default class OperationalSafetyCenterComponent extends Component {
    @service chatSafety;
    @service session;
    @tracked panel = null;
    @tracked pendingDrafts = [];
    flight = null;
    types = types;
    reasons = [
        { id: 'harassment', label: 'Pelecehan atau kebencian' },
        { id: 'threat', label: 'Ancaman' },
        { id: 'illegal', label: 'Materi ilegal atau eksploitasi' },
        { id: 'privacy', label: 'Pelanggaran privasi' },
        { id: 'spam', label: 'Spam atau penipuan' },
        { id: 'other', label: 'Alasan lain' },
    ];
    get available() {
        return this.chatSafety.status?.enabled && Array.isArray(this.chatSafety.status.operational_report_types);
    }
    get visible() {
        return this.available && this.panel?.marker === this.chatSafety.marker;
    }
    get locked() {
        return !!this.panel?.preview || this.panel?.loading || this.panel?.saved;
    }
    get canPreview() {
        return this.visible && !this.locked && types.some((item) => item.id === this.panel.type) && identifier(this.panel.target) && !this.chatSafety.busy;
    }
    get canSubmit() {
        return (
            this.visible &&
            this.panel.preview &&
            !this.panel.saved &&
            !this.panel.loading &&
            !this.panel.stale &&
            this.panel.reason &&
            this.panel.share &&
            this.chatSafety.canContribute &&
            this.panel.preview.policy_version === this.chatSafety.status.policy_version &&
            !this.chatSafety.busy
        );
    }
    @action refresh() {
        Promise.resolve()
            .then(() => {
                if (!this.isDestroying && !this.isDestroyed && this.session.isAuthenticated) return this.chatSafety.refresh();
            })
            .catch(() => {});
    }
    current(operation) {
        return !this.isDestroying && !this.isDestroyed && this.flight === operation && operation.marker === this.chatSafety.marker && this.panel?.marker === operation.marker;
    }
    key(type, target) {
        const scope = this.chatSafety.capture();
        return prefix + encodeURIComponent(JSON.stringify([scope.host, scope.user, scope.company, type, target]));
    }
    listPending() {
        const scope = this.chatSafety.capture(),
            rows = [];
        try {
            for (const key of Object.keys(window.sessionStorage)) {
                if (!key.startsWith(prefix)) continue;
                const parts = JSON.parse(decodeURIComponent(key.slice(prefix.length)));
                if (
                    !Array.isArray(parts) ||
                    parts.length !== 5 ||
                    parts[0] !== scope.host ||
                    parts[1] !== scope.user ||
                    parts[2] !== scope.company ||
                    !types.some((item) => item.id === parts[3]) ||
                    !identifier(parts[4])
                )
                    continue;
                rows.push({ type: parts[3], target: parts[4], label: types.find((item) => item.id === parts[3]).label });
            }
            this.pendingDrafts = rows.slice(0, 100);
        } catch {
            this.panel = { ...this.panel, feedback: 'Penyimpanan draf browser belum dapat diperiksa. Hubungi dukungan.' };
        }
    }
    @action open() {
        if (!this.available) return;
        this.flight = null;
        this.panel = {
            marker: this.chatSafety.marker,
            type: '',
            target: '',
            reason: '',
            details: '',
            share: false,
            preview: null,
            journal: null,
            loading: false,
            stale: false,
            saved: false,
            feedback: '',
        };
        this.listPending();
    }
    @action close() {
        this.panel = null;
        this.flight = null;
    }
    @action changeType(event) {
        if (!this.locked) this.panel = { ...this.panel, type: event.target.value, feedback: '' };
    }
    @action changeTarget(event) {
        if (!this.locked) this.panel = { ...this.panel, target: event.target.value.trim(), feedback: '' };
    }
    @action changeReason(event) {
        if (!this.panel.journal && !this.panel.loading && !this.panel.saved) this.panel = { ...this.panel, reason: event.target.value };
    }
    @action changeDetails(event) {
        if (!this.panel.journal && !this.panel.loading && !this.panel.saved) this.panel = { ...this.panel, details: event.target.value };
    }
    @action changeShare(event) {
        if (!this.panel.loading && !this.panel.saved) this.panel = { ...this.panel, share: event.target.checked };
    }
    @action async resume(row) {
        if (!this.visible || this.panel.loading) return;
        this.panel = { ...this.panel, type: row.type, target: row.target, preview: null, journal: null, saved: false, stale: false, share: false };
        await this.preview();
    }
    @action async preview() {
        if (!this.canPreview || this.flight) return;
        const operation = { marker: this.chatSafety.marker };
        const { type, target } = this.panel;
        this.flight = operation;
        this.panel = { ...this.panel, loading: true, feedback: '', share: false };
        try {
            const raw = window.sessionStorage.getItem(this.key(type, target));
            const journal = raw === null ? null : JSON.parse(raw);
            if (raw !== null && !validDraft(journal, type, target)) throw chatSafetyError();
            const preview = journal?.preview ?? (await this.chatSafety.previewOperationalContent({ type, target }, operation.marker));
            if (this.current(operation)) this.panel = { ...this.panel, preview, journal, reason: journal?.data.reason ?? '', details: journal?.data.details ?? '', loading: false };
        } catch (failure) {
            if (this.current(operation))
                this.panel = { ...this.panel, loading: false, feedback: chatSafetyError(failure?.code).message + ' Referensi harus tersedia pada halaman yang dapat Anda akses.' };
        } finally {
            if (this.flight === operation) this.flight = null;
        }
    }
    @action async submit() {
        if (!this.canSubmit || this.flight) return;
        const operation = { marker: this.chatSafety.marker };
        this.flight = operation;
        this.panel = { ...this.panel, loading: true, feedback: '' };
        const selected = this.panel,
            key = this.key(selected.type, selected.target);
        try {
            const journal = selected.journal ?? {
                version: 1,
                preview: selected.preview,
                data: {
                    request_id: window.crypto.randomUUID(),
                    type: selected.type,
                    target: selected.target,
                    reason: selected.reason,
                    details: selected.details,
                    share_context: true,
                    preview_receipt: selected.preview.preview_receipt,
                    policy_version: selected.preview.policy_version,
                },
            };
            if (!validDraft(journal, selected.type, selected.target)) throw chatSafetyError();
            const raw = JSON.stringify(journal),
                previous = window.sessionStorage.getItem(key);
            if (previous !== null && previous !== raw) throw chatSafetyError('CHAT_REPORT_CONFLICT');
            window.sessionStorage.setItem(key, raw);
            if (window.sessionStorage.getItem(key) !== raw) throw chatSafetyError();
            if (!this.current(operation)) return;
            this.panel = { ...this.panel, journal };
            await this.chatSafety.reportOperationalContent(journal.data, operation.marker);
            if (this.current(operation)) {
                let cleared = false;
                try {
                    if (window.sessionStorage.getItem(key) === raw) {
                        window.sessionStorage.removeItem(key);
                        cleared = true;
                    }
                } catch {
                    /* ACK retained even if local cleanup fails. */
                }
                this.panel = {
                    ...this.panel,
                    loading: false,
                    saved: true,
                    feedback: cleared ? 'Laporan diterima pengelola organisasi.' : 'Laporan diterima. Jangan membuat ulang; draf lokal perlu diperiksa.',
                };
                this.listPending();
            }
        } catch (failure) {
            if (this.current(operation))
                this.panel = {
                    ...this.panel,
                    loading: false,
                    stale: failure?.code === 'CONTENT_REPORT_CHANGED',
                    feedback: chatSafetyError(failure?.code).message + ' Draf dipertahankan; pengiriman ulang memerlukan konfirmasi dan memakai ID yang sama.',
                };
        } finally {
            if (this.flight === operation) this.flight = null;
        }
    }
    @action async newPreview() {
        if (!this.visible || !this.panel.stale || this.panel.loading || this.flight) return;
        try {
            const key = this.key(this.panel.type, this.panel.target);
            if (window.sessionStorage.getItem(key) !== JSON.stringify(this.panel.journal)) throw chatSafetyError('CHAT_REPORT_CONFLICT');
            window.sessionStorage.removeItem(key);
            if (window.sessionStorage.getItem(key) !== null) throw chatSafetyError();
            this.panel = { ...this.panel, preview: null, journal: null, share: false, reason: '', details: '', stale: false };
            this.listPending();
            await this.preview();
        } catch (failure) {
            this.panel = { ...this.panel, feedback: chatSafetyError(failure?.code).message };
        }
    }
}
