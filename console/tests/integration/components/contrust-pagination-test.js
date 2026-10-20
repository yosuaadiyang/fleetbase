import { module, test } from 'qunit';
import { setupRenderingTest } from 'ember-qunit';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
module('Integration | Component | contrust pagination', function (hooks) {
    setupRenderingTest(hooks);
    test('empty backend ranges display zero rows and mobile next cannot advance', async function (assert) {
        this.meta = { total: 0, from: null, to: null, current_page: 1, last_page: 1 };
        let changes = 0;
        this.onPage = () => changes++;
        await render(hbs`<Pagination @meta={{this.meta}} @onPageChange={{this.onPage}} />`);
        assert.dom('.pagination-showing').hasText('Showing 0 to 0 of 0 results');
        await click('#fleetbase-pagination-next-button');
        assert.strictEqual(changes, 0, 'aria-disabled link also rejects the action');
        assert.dom('#fleetbase-pagination-forward-button').isDisabled();
    });
    test('populated backend ranges remain accurate', async function (assert) {
        this.meta = { total: 24, from: 11, to: 20, current_page: 2, last_page: 3 };
        await render(hbs`<Pagination @meta={{this.meta}} @currentPage={{2}} />`);
        assert.dom('.pagination-showing').hasText('Showing 11 to 20 of 24 results');
        assert.dom('#fleetbase-pagination-forward-button').isNotDisabled();
    });
    test('zero last-page metadata cannot advance or move backward', async function (assert) {
        this.meta = { total: 0, from: 0, to: 0, current_page: 1, last_page: 0 };
        let changes = 0;
        this.onPage = () => changes++;
        await render(hbs`<Pagination @meta={{this.meta}} @onPageChange={{this.onPage}} />`);
        await click('#fleetbase-pagination-next-button');
        await click('#fleetbase-pagination-previous-button');
        assert.strictEqual(changes, 0);
        assert.dom('.pagination-showing').hasText('Showing 0 to 0 of 0 results');
    });
});
