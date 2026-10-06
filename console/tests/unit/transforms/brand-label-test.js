import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';
import config from '@fleetbase/console/config/environment';

module('Unit | Transform | brand-label', function (hooks) {
    setupTest(hooks);

    test('the upstream values the API sends are shown under the console brand', function (assert) {
        const transform = this.owner.lookup('transform:brand-label');
        const brand = config.APP.brandName;

        assert.strictEqual(transform.deserialize('FLB Managed'), `${brand} Managed`);
        assert.strictEqual(transform.deserialize('Fleetbase Managed'), `${brand} Managed`);
        assert.strictEqual(transform.deserialize('Fleetbase Developer'), `${brand} Developer`);
        assert.strictEqual(transform.deserialize('Policy for full access to Fleetbase extensions and resources.'), `Policy for full access to ${brand} extensions and resources.`);
    });

    test('any other value passes through unchanged', function (assert) {
        const transform = this.owner.lookup('transform:brand-label');

        assert.strictEqual(transform.deserialize('Organization Managed'), 'Organization Managed');
        assert.strictEqual(transform.deserialize('Fleetbase Ops team'), 'Fleetbase Ops team', 'only exact values change');
        assert.strictEqual(transform.deserialize(null), null);
        assert.strictEqual(transform.serialize('Dispatchers'), 'Dispatchers');
        assert.strictEqual(transform.serialize(undefined), undefined);
    });

    test('saving sends the API its own value back', function (assert) {
        const transform = this.owner.lookup('transform:brand-label');
        const brand = config.APP.brandName;

        assert.strictEqual(transform.serialize(`${brand} Managed`), 'FLB Managed');
        assert.strictEqual(transform.serialize(`${brand} Developer`), 'Fleetbase Developer');
        assert.strictEqual(transform.serialize(`Policy for full access to ${brand} extensions and resources.`), 'Policy for full access to Fleetbase extensions and resources.');
    });
});
