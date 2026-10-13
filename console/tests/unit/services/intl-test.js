import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';

module('Unit | Service | intl locale loading', function (hooks) {
    setupTest(hooks);
    hooks.beforeEach(function () {
        this.constructors = Object.fromEntries(['NumberFormat', 'DateTimeFormat', 'PluralRules', 'RelativeTimeFormat'].map((name) => [name, Intl[name]]));
    });

    test('a later locale selection wins while extension locale data is loading', async function (assert) {
        const intl = this.owner.lookup('service:intl');
        intl.setLocale('en-us');
        assert.strictEqual(Intl.NumberFormat, this.constructors.NumberFormat);
        const earlier = intl.setLocale('mn-mn');
        const later = intl.setLocale('en-us');
        await Promise.all([earlier, later]);
        assert.strictEqual(intl.primaryLocale, 'en-us');
        assert.strictEqual(intl.formatRelative(-1, { unit: 'day' }), '1 day ago');
    });

    test('English stays synchronous and a missing locale is usable after selection', async function (assert) {
        const intl = this.owner.lookup('service:intl');
        intl.setLocale('en-us');
        assert.strictEqual(intl.primaryLocale, 'en-us');
        assert.strictEqual(Intl.NumberFormat, this.constructors.NumberFormat);
        await intl.setLocale('mn-mn');
        assert.strictEqual(intl.primaryLocale, 'mn-mn');
        assert.strictEqual(Intl.NumberFormat.supportedLocalesOf('mn').length, 1);
        assert.strictEqual(Intl.DateTimeFormat.supportedLocalesOf('mn').length, 1);
        assert.strictEqual(typeof intl.formatNumber(1234), 'string');
    });
});
