import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import config from '@fleetbase/console/config/environment';
import { chatSafetyRequest, chatSafetyError } from '../utils/chat-safety-request';

const same = (a, b) => a && b && ['host', 'token', 'user', 'company'].every((key) => a[key] === b[key]);

/** Private session-bound state. No consent/report/mutation is inferred or retried. */
export default class ChatSafetyService extends Service {
    @service session;
    @service currentUser;
    @service fetch;
    @tracked stored = null;
    @tracked failure = null;
    @tracked checkingMarker = null;
    @tracked busyMarker = null;
    @tracked selection = null;
    selectionSerial = 0;
    scopeMemo = null;
    markerMemo = null;
    readFlight = null;
    writeFlight = null;
    controllers = new Set();
    revision = 0;
    transport = (...args) => window.fetch(...args);

    capture() {
        return {
            host: this.fetch.host ?? config.API.host,
            token: this.session.isAuthenticated ? this.session.data?.authenticated?.token : null,
            user: this.currentUser.id,
            company: this.currentUser.companyId,
        };
    }

    get marker() {
        const scope = this.capture();
        if (!same(scope, this.scopeMemo)) {
            // These caches are intentionally not tracked writes during render.
            this.scopeMemo = scope;
            this.markerMemo = {};
        }
        return this.markerMemo;
    }

    get status() {
        return this.stored?.marker === this.marker ? this.stored.value : null;
    }
    get error() {
        return this.failure?.marker === this.marker ? this.failure.text : '';
    }
    get checking() {
        return this.checkingMarker === this.marker;
    }
    get busy() {
        return this.busyMarker === this.marker;
    }
    get canContribute() {
        return !!this.status && !this.checking && !this.error && (!this.status.enabled || this.status.consented === true);
    }

    assertCurrent(marker) {
        if (this.isDestroying || this.isDestroyed || marker !== this.marker || !this.session.isAuthenticated || !this.scopeMemo.user || !this.scopeMemo.company)
            throw chatSafetyError('CHAT_SAFETY_SESSION');
    }

    async request(action, input = {}, marker = this.marker) {
        this.assertCurrent(marker);
        const scope = this.capture();
        // Abort prior-scope requests without exposing their captured credentials.
        for (const pending of this.controllers) if (pending.marker !== marker) pending.controller.abort();
        const operation = { marker, controller: new AbortController() };
        this.controllers.add(operation);
        try {
            const value = await chatSafetyRequest({ host: scope.host, sessionToken: scope.token, action, input, fetchImpl: this.transport, signal: operation.controller.signal });
            this.assertCurrent(marker);
            return value;
        } finally {
            this.controllers.delete(operation);
        }
    }

    refresh() {
        const marker = this.marker;
        try {
            this.assertCurrent(marker);
        } catch (failure) {
            return Promise.reject(failure);
        }
        if (this.readFlight?.marker === marker) return this.readFlight.promise;
        const flight = { marker, promise: null };
        const revision = this.revision;
        this.checkingMarker = marker;
        this.failure = null;
        flight.promise = this.request('status', {}, marker)
            .then((value) => {
                if (this.marker === marker && this.revision === revision) this.stored = { marker, value };
                return value;
            })
            .catch((failure) => {
                if (!this.isDestroying && !this.isDestroyed && this.marker === marker && this.revision === revision) this.failure = { marker, text: chatSafetyError(failure?.code).message };
                throw failure;
            })
            .finally(() => {
                if (this.readFlight === flight) {
                    this.readFlight = null;
                    if (!this.isDestroying && !this.isDestroyed && this.marker === marker) this.checkingMarker = null;
                }
            });
        this.readFlight = flight;
        return flight.promise;
    }

    async perform(action, input, target = null, marker = this.marker) {
        this.assertCurrent(marker);
        if (!this.status?.enabled || this.writeFlight?.marker === marker) throw chatSafetyError();
        const flight = { marker };
        this.writeFlight = flight;
        this.busyMarker = marker;
        try {
            const result = await this.request(action, input, marker);
            this.assertCurrent(marker);
            if (['consent', 'block', 'unblock'].includes(action)) {
                this.revision++;
                const value = this.status;
                if (value) {
                    if (action === 'consent') this.stored = { marker, value: { ...value, consented: true } };
                    else {
                        const rows = value.blocked_users.filter((person) => person.id !== result.user);
                        if (action === 'block') rows.push({ id: result.user, name: typeof target?.name === 'string' ? target.name : 'Pengguna organisasi' });
                        rows.sort((a, b) => a.id.localeCompare(b.id));
                        const more = value.blocked_has_more || rows.length > 100;
                        this.stored = {
                            marker,
                            value: {
                                ...value,
                                blocked_users: rows.slice(0, 100),
                                blocked_has_more: more,
                                blocked_next_cursor: more ? (rows[Math.min(rows.length, 100) - 1]?.id ?? value.blocked_next_cursor) : null,
                            },
                        };
                    }
                }
            }
            return result;
        } finally {
            if (this.writeFlight === flight) {
                this.writeFlight = null;
                if (!this.isDestroying && !this.isDestroyed && this.marker === marker) this.busyMarker = null;
            }
        }
    }

    acceptPolicy(marker = this.marker) {
        if (!this.status?.enabled || !this.status.policy_version) return Promise.reject(chatSafetyError());
        return this.perform('consent', { policy_version: this.status.policy_version, accepted: true }, null, marker);
    }
    blockUser(person, marker = this.marker) {
        return this.perform('block', { user: person.id }, person, marker);
    }
    unblockUser(person, marker = this.marker) {
        return this.perform('unblock', { user: person.id }, null, marker);
    }
    report(input, marker = this.marker) {
        return this.perform('report', input, null, marker);
    }
    getBlockedPage(after = null, marker = this.marker) {
        return this.request('status', after === null ? {} : { blocked_after: after }, marker);
    }
    getReports(before = null, marker = this.marker) {
        return this.request('reports', before === null ? {} : { before }, marker);
    }
    review(input, marker = this.marker) {
        return this.perform('review', input, null, marker);
    }
    assertCanContribute() {
        if (!this.canContribute) throw chatSafetyError(this.status?.enabled && !this.status.consented ? 'CHAT_POLICY_REQUIRED' : 'CHAT_SAFETY_UNAVAILABLE');
    }

    selectReport(target, marker = this.marker) {
        this.assertCurrent(marker);
        if (!this.status?.enabled || !['user', 'message'].includes(target?.type) || typeof target.id !== 'string' || !/^[A-Za-z0-9_-]{1,150}$/.test(target.id)) throw chatSafetyError();
        this.selection = {
            marker,
            key: ++this.selectionSerial,
            target: {
                type: target.type,
                id: target.id,
                name: typeof target.name === 'string' ? target.name : 'Pengguna organisasi',
                preview: typeof target.preview === 'string' ? target.preview.slice(0, 4000) : '',
            },
        };
    }
    clearSelection(marker = this.marker) {
        if (this.selection?.marker === marker) this.selection = null;
    }
    get selections() {
        return this.status?.enabled && this.selection?.marker === this.marker ? [this.selection] : [];
    }

    willDestroy() {
        this.controllers.forEach(({ controller }) => controller.abort());
        this.controllers.clear();
        super.willDestroy(...arguments);
    }
}
