import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import config from '@fleetbase/console/config/environment';

class ModalsManagerStub extends Service {
    shown = [];
    show(component, options) {
        this.shown.push({ component, options });
    }
}

module('Integration | Component | fleetbase-attribution', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.original = { disabled: config.APP.disableFleetbaseAttribution, version: config.version };
    });

    hooks.afterEach(function () {
        config.APP.disableFleetbaseAttribution = this.original.disabled;
        config.version = this.original.version;
    });

    test('it shows the console brand and version, not the upstream one', async function (assert) {
        config.APP.disableFleetbaseAttribution = false;
        config.version = '1.2.3';

        await render(hbs`<FleetbaseAttribution @showPoweredBy={{true}} />`);

        assert.dom('[data-test-attribution-brand]').hasText(`Powered by ${config.APP.brandName}`);
        assert.dom('[data-test-attribution-brand]').hasAttribute('href', config.APP.sourceCodeUrl, 'the brand links to the source of this version');
        assert.dom('.fleetbase-attribution-version').hasText('v1.2.3');
        assert.dom('.fleetbase-attribution-notice').doesNotContainText('Fleetbase');
    });

    test('Legal opens the legal notice with the brand and the source link', async function (assert) {
        config.APP.disableFleetbaseAttribution = false;

        await render(hbs`<FleetbaseAttribution />`);
        await click('[data-test-attribution-legal]');

        const [shown] = this.owner.lookup('service:modals-manager').shown;
        assert.strictEqual(shown.component, 'modals/contrust-legal-notice');
        assert.strictEqual(shown.options.title, `${config.APP.brandName} Legal Notices`);
        assert.strictEqual(shown.options.brandName, config.APP.brandName);
        assert.strictEqual(shown.options.sourceCodeUrl, config.APP.sourceCodeUrl);
    });

    test('without a version only the brand and Legal are shown', async function (assert) {
        config.APP.disableFleetbaseAttribution = false;
        config.version = undefined;

        await render(hbs`<FleetbaseAttribution />`);

        assert.dom('[data-test-attribution-brand]').hasText(config.APP.brandName);
        assert.dom('.fleetbase-attribution-version').doesNotExist();
        assert.dom('[data-test-attribution-legal]').exists();
    });

    test('it renders nothing when attribution is disabled', async function (assert) {
        config.APP.disableFleetbaseAttribution = true;

        await render(hbs`<FleetbaseAttribution />`);

        assert.dom('.fleetbase-attribution-notice').doesNotExist();
    });
});
