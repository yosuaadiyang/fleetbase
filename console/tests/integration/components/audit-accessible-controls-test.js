import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | audit accessible controls', function (hooks) {
    setupRenderingTest(hooks);

    test('icon button exposes its existing help text to assistive technology', async function (assert) {
        await render(hbs`<Button @icon="refresh" @helpText="Refresh records" />`);
        assert.dom('button').hasAttribute('aria-label', 'Refresh records');
    });
    test('a caller can override the icon button accessible name', async function (assert) {
        await render(hbs`<Button @icon="refresh" @helpText="Refresh" aria-label="Reload driver list" />`);
        assert.dom('button').hasAttribute('aria-label', 'Reload driver list');
    });
    test('an icon dropdown has a fallback action name', async function (assert) {
        await render(hbs`<DropdownButton @icon="ellipsis-h" />`);
        assert.dom('button').hasAttribute('aria-label', 'More actions');
    });
    test('icon filters retain a meaningful name', async function (assert) {
        await render(hbs`<FiltersPicker::Button @buttonComponentArgs={{hash iconOnly=true activeFilters=(array)}} @text="Filter records" />`);
        assert.dom('button').hasAttribute('aria-label', 'Filter records');
    });
    test('icon column picker retains a meaningful name', async function (assert) {
        this.set('columns', []);
        await render(hbs`<VisibleColumnPicker @iconOnly={{true}} @text="Choose columns" @columns={{this.columns}} />`);
        assert.dom('button').hasAttribute('aria-label', 'Choose columns');
    });
    test('sidebar toggle exposes name and current expanded state', async function (assert) {
        const sidebar = this.owner.lookup('service:sidebar');
        sidebar.setEnabled(true);
        await render(hbs`<Layout::Header::SidebarToggle />`);
        assert.dom('button').hasAttribute('aria-label', 'Toggle navigation sidebar');
        assert.dom('button').hasAttribute('aria-expanded', sidebar.isVisible ? 'true' : 'false');
        await click('button');
        assert.dom('button').hasAttribute('aria-expanded', sidebar.isVisible ? 'true' : 'false');
    });
    test('pagination arrow buttons have names and keep disabled boundaries', async function (assert) {
        await render(hbs`<Pagination @meta={{hash current_page=1 last_page=1 total=0}} />`);
        assert.dom('#fleetbase-pagination-backward-button').hasAttribute('aria-label', 'Previous page').isDisabled();
        assert.dom('#fleetbase-pagination-forward-button').hasAttribute('aria-label', 'Next page').isDisabled();
    });
});
