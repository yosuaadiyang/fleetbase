import { module, test } from 'qunit';
import fetchModule from 'fetch';
import lookupUserIp from '@fleetbase/ember-core/utils/lookup-user-ip';

module('Unit | Utility | GeoIP response deadline', function (hooks) {
    hooks.beforeEach(function () {
        this.originalFetch = globalThis.fetch;
        this.originalWarn = console.warn;
        console.warn = () => {};
    });
    hooks.afterEach(function () {
        globalThis.fetch = this.originalFetch;
        console.warn = this.originalWarn;
    });

    test('the built fetch module retains its callable export', function (assert) {
        assert.strictEqual(typeof fetchModule, 'function');
    });

    test('a stalled JSON body is aborted on both providers and falls back to browser defaults', async function (assert) {
        const signals = [];
        globalThis.fetch = async (_url, { signal }) => {
            signals.push(signal);
            return {
                ok: true,
                json: () =>
                    new Promise((_resolve, reject) => {
                        signal.addEventListener('abort', () => reject(new DOMException('Synthetic timeout', 'AbortError')), { once: true });
                    }),
            };
        };
        const result = await lookupUserIp({ timeout: 5, cache: false });
        assert.strictEqual(signals.length, 2);
        assert.true(signals.every((signal) => signal.aborted));
        assert.strictEqual(result._source, 'fallback');
        assert.strictEqual(result.timezone, Intl.DateTimeFormat().resolvedOptions().timeZone);
    });

    test('an unsuccessful API payload falls through to a valid provider', async function (assert) {
        let calls = 0;
        globalThis.fetch = async () => ({ ok: true, json: async () => (++calls === 1 ? { error: true } : { ip: '192.0.2.1', timezone: 'UTC', country_code: 'ID' }) });
        const result = await lookupUserIp({ timeout: 1000, cache: false });
        assert.strictEqual(calls, 2);
        assert.strictEqual(result._source, 'ipapi.co');
        assert.strictEqual(result.timezone, 'UTC');
    });
});
