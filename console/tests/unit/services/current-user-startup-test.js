import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';

function deferred() {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

module('Unit | Service | current-user startup', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.currentUser = this.owner.lookup('service:current-user');
    });

    test('authenticated user and organizations resolve while optional GeoIP is pending', async function (assert) {
        const service = this.currentUser;
        const location = deferred();
        const user = { id: 'synthetic-user' };
        let resolved = false;
        let organizationsLoaded = false;
        Object.defineProperty(service, 'session', { value: { isAuthenticated: true }, configurable: true });
        Object.defineProperty(service, 'store', { value: { queryRecord: async () => user }, configurable: true });
        service.setUser = async () => {};
        service.loadWhois = () => location.promise;
        service.loadOrganizations = async () => {
            organizationsLoaded = true;
        };
        const loading = service.promiseUser({
            onUserResolved() {
                resolved = true;
            },
        });
        try {
            for (let i = 0; i < 12; i++) {
                await Promise.resolve();
            }
            assert.true(resolved, 'account resolves before third-party lookup');
            assert.true(organizationsLoaded, 'organization selection is available');
        } finally {
            location.resolve({ _source: 'fallback' });
            assert.strictEqual(await loading, user);
        }
    });

    test('locale and organization preferences do not await optional GeoIP', async function (assert) {
        const service = this.currentUser;
        const location = deferred();
        const calls = [];
        service.loadLocale = async () => calls.push('locale');
        service.loadWhois = () => location.promise;
        service.loadOrganizations = async () => calls.push('organizations');
        let resolved = false;
        const loading = service.loadPreferences().then(() => {
            resolved = true;
        });
        try {
            for (let i = 0; i < 12; i++) {
                await Promise.resolve();
            }
            assert.true(resolved);
            assert.deepEqual(calls, ['locale', 'organizations']);
        } finally {
            location.resolve({ _source: 'fallback' });
            await loading;
        }
    });

    test('optional lookup failure is handled without rejecting authenticated preferences', async function (assert) {
        const service = this.currentUser;
        service.loadLocale = async () => {};
        service.loadOrganizations = async () => {};
        service.loadWhois = async () => {
            throw new Error('Synthetic offline GeoIP');
        };
        await service.loadPreferences();
        await Promise.resolve();
        assert.ok(true, 'optional failure is caught');
    });

    test('late GeoIP cannot write preferences after the authenticated owner changes', async function (assert) {
        const originalFetch = globalThis.fetch;
        const originalCache = localStorage.getItem('fleetbase:whois');
        localStorage.removeItem('fleetbase:whois');
        const response = deferred();
        const service = this.currentUser;
        let ownerId = 'synthetic-a';
        let writes = 0;
        Object.defineProperty(service, 'authenticatedOptionOwnerId', { configurable: true, get: () => ownerId });
        service.setOption = () => {
            writes++;
        };
        globalThis.fetch = () => response.promise;
        try {
            const loading = service.loadWhois();
            const concurrent = service.loadWhois();
            ownerId = 'synthetic-b';
            response.resolve({ ok: true, json: async () => ({ ip: '192.0.2.1', timezone_name: 'UTC' }) });
            await Promise.all([loading, concurrent]);
            assert.strictEqual(writes, 0, 'late response cannot enter the next user options');
            assert.deepEqual(service.whoisData, {});
            assert.strictEqual(service._whoisRequest, null, 'completed request released');
        } finally {
            globalThis.fetch = originalFetch;
            if (originalCache === null) {
                localStorage.removeItem('fleetbase:whois');
            } else {
                localStorage.setItem('fleetbase:whois', originalCache);
            }
        }
    });
});
