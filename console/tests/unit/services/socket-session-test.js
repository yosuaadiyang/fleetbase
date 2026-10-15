import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';

module('Unit | Service | socket-session', function (hooks) {
    setupTest(hooks);
    hooks.beforeEach(function () {
        this.calls = [];
        this.session = { isAuthenticated: false };
        this.service = this.owner.lookup('service:socket-session');
        this.service.session = this.session;
        const calls = this.calls;
        this.service.fetch = { get(...args) { calls.push(args); return Promise.resolve({ status: 'ok' }); } };
    });
    test('anonymous sessions never request a socket credential', async function (assert) {
        await this.service.refresh();
        assert.strictEqual(this.calls.length, 0);
    });
    test('authenticated refresh uses the private API namespace', async function (assert) {
        this.session.isAuthenticated = true;
        await this.service.refresh();
        assert.deepEqual(this.calls, [['contrust/socket-session', {}, { namespace: 'int/v1' }]]);
    });
    test('provider failure clears the pending state for a subsequent refresh', async function (assert) {
        this.session.isAuthenticated = true;
        this.service.fetch.get = () => Promise.reject(new Error('unavailable'));
        const service = this.service;
        await service.refresh();
        assert.false(service.pending);
        this.service.fetch.get = () => Promise.resolve({ status: 'ok' });
        await service.refresh();
        assert.false(service.pending);
    });
});
