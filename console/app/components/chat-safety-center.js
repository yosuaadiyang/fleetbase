import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { chatSafetyError } from '../utils/chat-safety-request';

export default class ChatSafetyCenterComponent extends Component {
    @service chatSafety;
    @service session;
    @tracked panel = null;
    @tracked editor = null;
    flight = null;
    get visible() {
        return this.panel?.marker === this.chatSafety.marker && this.chatSafety.status?.enabled;
    }
    get editing() {
        return this.editor?.marker === this.chatSafety.marker;
    }
    @action refresh() {
        Promise.resolve()
            .then(() => {
                if (!this.isDestroying && !this.isDestroyed && this.session.isAuthenticated) return this.chatSafety.refresh();
            })
            .catch(() => {});
    }
    statuses = [
        { id: 'reviewing', label: 'Sedang ditinjau' },
        { id: 'resolved', label: 'Selesai ditangani' },
        { id: 'dismissed', label: 'Tidak dilanjutkan' },
    ];
    resolutions = [
        { id: 'support_followup', label: 'Perlu tindak lanjut dukungan' },
        { id: 'no_violation', label: 'Tidak ditemukan pelanggaran' },
        { id: 'content_addressed', label: 'Konten sudah ditangani' },
        { id: 'access_addressed', label: 'Akses sudah ditangani' },
    ];
    @action open() {
        return this.load('blocks', null);
    }
    @action close() {
        this.flight = null;
        this.panel = null;
        this.editor = null;
    }
    current(operation) {
        return !this.isDestroying && !this.isDestroyed && this.flight === operation && operation.marker === this.chatSafety.marker;
    }
    @action async load(kind, after = null) {
        if (!this.chatSafety.status?.enabled || this.chatSafety.busy || this.flight?.marker === this.chatSafety.marker || (kind === 'reports' && !this.chatSafety.status.moderator)) return;
        const operation = { marker: this.chatSafety.marker };
        this.flight = operation;
        this.editor = null;
        this.panel = { marker: operation.marker, kind, after, rows: [], next: null, loading: true, feedback: '' };
        try {
            const page = kind === 'reports' ? await this.chatSafety.getReports(after, operation.marker) : await this.chatSafety.getBlockedPage(after, operation.marker);
            if (kind === 'blocks' && !page.enabled) throw chatSafetyError();
            if (this.current(operation))
                this.panel = {
                    ...this.panel,
                    rows: kind === 'reports' ? page.reports : page.blocked_users,
                    next: kind === 'reports' ? page.next_cursor : page.blocked_next_cursor,
                    loading: false,
                };
        } catch (failure) {
            if (this.current(operation)) this.panel = { ...this.panel, loading: false, feedback: chatSafetyError(failure?.code).message };
        } finally {
            if (this.flight === operation) this.flight = null;
        }
    }
    @action confirm(row) {
        if (!this.visible || this.panel.loading || this.chatSafety.busy) return;
        this.editor = { marker: this.chatSafety.marker, id: row.id, name: row.name, status: 'reviewing', resolution: 'support_followup', confirmed: false };
    }
    @action changeStatus(event) {
        if (this.editing) this.editor = { ...this.editor, status: event.target.value, confirmed: false };
    }
    @action changeResolution(event) {
        if (this.editing) this.editor = { ...this.editor, resolution: event.target.value, confirmed: false };
    }
    @action changeConfirmation(event) {
        if (this.editing) this.editor = { ...this.editor, confirmed: event.target.checked };
    }
    @action async save() {
        if (!this.visible || !this.editing || !this.editor.confirmed || this.chatSafety.busy || this.flight) return;
        const chosen = this.editor,
            kind = this.panel.kind;
        const operation = { marker: this.chatSafety.marker };
        this.flight = operation;
        try {
            if (kind === 'blocks') await this.chatSafety.unblockUser({ id: chosen.id }, operation.marker);
            else await this.chatSafety.review({ id: chosen.id, status: chosen.status, resolution: chosen.resolution }, operation.marker);
            if (this.current(operation)) {
                const rows =
                    kind === 'blocks'
                        ? this.panel.rows.filter((row) => row.id !== chosen.id)
                        : this.panel.rows.map((row) => (row.id === chosen.id ? { ...row, status: chosen.status, resolution: chosen.resolution } : row));
                this.panel = { ...this.panel, rows, feedback: kind === 'blocks' ? 'Blokir dibuka.' : 'Hasil review tercatat. Tindakan operasional tetap perlu dilaksanakan.' };
                this.editor = null;
            }
        } catch (failure) {
            if (this.current(operation)) this.panel = { ...this.panel, feedback: chatSafetyError(failure?.code).message };
        } finally {
            if (this.flight === operation) this.flight = null;
        }
    }
}
