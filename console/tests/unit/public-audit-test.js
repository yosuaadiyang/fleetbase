import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';
import clientTokenExpiry, { parseUtcDateTime } from '@fleetbase/ember-core/utils/client-token-expiry';
import isValidCoordinates from '@fleetbase/ember-core/utils/is-valid-coordinates';

module('Unit | public audit | expiry', function () {
    test('legacy Carbon, UTC ISO and offset ISO preserve the same expiry instant', function (assert) {
        for (const stamp of ['2026-05-06 14:30:00', '2026-05-06T14:30:00Z', '2026-05-06T21:30:00+07:00']) {
            assert.strictEqual(parseUtcDateTime(stamp).toISOString(), '2026-05-06T14:30:00.000Z', stamp);
            assert.strictEqual(clientTokenExpiry(btoa(`${stamp}|session`)).toISOString(), '2026-05-06T14:30:00.000Z');
        }
    });

    test('invalid calendar values, bad tokens and malformed UTF-8 are rejected', function (assert) {
        for (const stamp of ['2026-02-30 12:00:00', '2026-05-06 25:00:00', '2026-13-01 00:00:00', 'not-a-date', '']) {
            assert.strictEqual(parseUtcDateTime(stamp), null, stamp);
        }
        for (const token of [undefined, null, 12, '', '!!!', btoa('missing-separator'), btoa('\xff|session')]) {
            assert.strictEqual(clientTokenExpiry(token), null);
        }
    });

    test('fractional seconds and valid leap days survive parsing', function (assert) {
        assert.strictEqual(parseUtcDateTime('2024-02-29 00:00:00.123').toISOString(), '2024-02-29T00:00:00.123Z');
    });
});

module('Unit | public audit | coordinates', function () {
    test('the equator and prime meridian are valid in every supported representation', function (assert) {
        for (const coordinates of [[0, 1], [1, 0], { lat: 0, lng: 1 }, { latitude: 1, longitude: 0 }]) {
            assert.true(isValidCoordinates(coordinates), JSON.stringify(coordinates));
        }
        assert.true(isValidCoordinates(0, 1));
        assert.true(isValidCoordinates('0', '1'));
        assert.true(isValidCoordinates(90, 180));
    });

    test('missing, blank, boolean and non-finite coordinates are not coerced to zero', function (assert) {
        for (const latitude of [null, undefined, '', ' ', false, NaN, Infinity, 91]) {
            assert.false(isValidCoordinates(latitude, 1));
        }
        assert.false(isValidCoordinates(0, 181));
    });
});

module('Unit | public audit | location models', function (hooks) {
    setupTest(hooks);

    test('valid zero-axis places remain in both payload coordinate orders', function (assert) {
        const store = this.owner.lookup('service:store');
        const pickup = store.createRecord('place', { location: { type: 'Point', coordinates: [1, 0] } });
        const dropoff = store.createRecord('place', { location: { type: 'Point', coordinates: [0, 1] } });
        const payload = store.createRecord('payload', { pickup, dropoff });
        assert.true(pickup.hasValidCoordinates);
        assert.true(dropoff.hasValidCoordinates);
        assert.deepEqual(
            payload.payloadCoordinates,
            [
                [1, 0],
                [0, 1],
            ],
            'GeoJSON longitude/latitude'
        );
        assert.deepEqual(
            payload.routeWaypoints,
            [
                [0, 1],
                [1, 0],
            ],
            'Leaflet latitude/longitude'
        );
        assert.false(store.createRecord('place').hasValidCoordinates, 'the unset (0,0) placeholder is still not a usable place');
    });
});

module('Unit | public audit | session loader', function (hooks) {
    setupTest(hooks);

    hooks.afterEach(function () {
        document.querySelectorAll('[data-session-loader-message]').forEach((element) => element.closest('.overloader').remove());
    });

    test('error messages render as text instead of markup', function (assert) {
        const service = this.owner.lookup('service:session');
        const message = '<img src="invalid" onerror="unexpected()">';
        const loader = service.showLoader(message);
        assert.strictEqual(loader.querySelector('[data-session-loader-message]').textContent, message);
        assert.strictEqual(loader.querySelector('img'), null);
        loader.remove();
    });

    test('rejected invalidation removes the overlay and permits a retry', async function (assert) {
        const service = this.owner.lookup('service:session');
        const error = new Error('logout unavailable');
        let attempts = 0;
        Object.defineProperty(service, 'session', {
            configurable: true,
            value: {
                invalidate() {
                    attempts++;
                    return attempts === 1 ? Promise.reject(error) : Promise.resolve();
                },
            },
        });
        await assert.rejects(service.invalidateWithLoader(), error);
        assert.false(service.isLoaderNodeOpen);
        assert.strictEqual(document.querySelector('[data-session-loader-message]'), null);
        await service.invalidateWithLoader();
        assert.strictEqual(attempts, 2);
        assert.false(service.isLoaderNodeOpen);
    });

    test('a synchronously throwing invalidation is also cleaned up', async function (assert) {
        const service = this.owner.lookup('service:session');
        Object.defineProperty(service, 'session', {
            configurable: true,
            value: {
                invalidate() {
                    throw new Error('offline');
                },
            },
        });
        await assert.rejects(service.invalidateWithLoader(), /offline/);
        assert.false(service.isLoaderNodeOpen);
        assert.strictEqual(document.querySelector('[data-session-loader-message]'), null);
    });
});
