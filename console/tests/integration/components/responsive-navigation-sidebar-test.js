import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import { render, settled, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

module('Integration | responsive navigation sidebar', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(function () {
        this.owner.register(
            'service:media',
            class extends Service {
                @tracked isMobile = false;
            }
        );
        this.media = this.owner.lookup('service:media');
        this.sidebar = this.owner.lookup('service:sidebar');
        this.sidebar.setEnabled(true);
        this.sidebar.setVisualState('visible');
    });

    test('initial mobile workspace does not open its navigation drawer', async function (assert) {
        this.media.isMobile = true;
        await render(hbs`<ResponsiveNavigationSidebar />`);
        assert.true(this.sidebar.isHidden);
        assert.dom('.next-sidebar').hasClass('sidebar-hidden');
        // Use the same service action as the real mobile Menu button.
        this.sidebar.toggle();
        await settled();
        assert.true(this.sidebar.isVisible);
        assert.dom('.next-sidebar').doesNotHaveClass('sidebar-hidden');
        this.sidebar.toggle();
        await settled();
        assert.true(this.sidebar.isHidden);
    });

    test('desktop keeps its visible sidebar and resize gutter', async function (assert) {
        await render(hbs`<ResponsiveNavigationSidebar />`);
        assert.true(this.sidebar.isVisible);
        assert.dom('.next-sidebar > .gutter').exists();
        assert.dom('.next-sidebar').doesNotHaveClass('sidebar-hidden');
    });

    test('crossing the mobile breakpoint closes the drawer and restores desktop choice', async function (assert) {
        this.sidebar.setVisualState('minimized');
        await render(hbs`<ResponsiveNavigationSidebar />`);
        assert.true(this.sidebar.isMinimized);
        this.media.isMobile = true;
        await settled();
        assert.true(this.sidebar.isHidden);
        this.sidebar.toggle();
        await settled();
        assert.true(this.sidebar.isVisible);
        this.media.isMobile = false;
        await settled();
        assert.true(this.sidebar.isMinimized);
        assert.dom('.next-sidebar').hasClass('sidebar-minimized');
    });

    test('a desktop hidden preference survives mobile open and close', async function (assert) {
        this.sidebar.setVisualState('hidden');
        await render(hbs`<ResponsiveNavigationSidebar />`);
        this.media.isMobile = true;
        await settled();
        this.sidebar.toggle();
        await settled();
        this.media.isMobile = false;
        await settled();
        assert.true(this.sidebar.isHidden);
    });

    test('route-disabled navigation cannot be re-enabled by responsive setup', async function (assert) {
        this.sidebar.disable();
        this.media.isMobile = true;
        await render(hbs`<ResponsiveNavigationSidebar />`);
        assert.true(this.sidebar.isDisabled);
        assert.true(this.sidebar.isHidden);
        this.media.isMobile = false;
        await settled();
        assert.true(this.sidebar.isDisabled);
        assert.true(this.sidebar.isHidden);
    });

    test('destroying the wrapper releases the actual sidebar context', async function (assert) {
        await render(hbs`<ResponsiveNavigationSidebar />`);
        assert.true(this.sidebar.hasContext);
        await clearRender();
        assert.false(this.sidebar.hasContext);
    });

    test('mobile CSS gives the workspace and opened drawer the full viewport', async function (assert) {
        this.media.isMobile = true;
        await render(hbs`
            <iframe title="Synthetic mobile navigation layout" style="width:390px;height:509px;border:0"></iframe>
            {{#if this.frameBody}}
                {{#in-element this.frameBody insertBefore=null}}
                    <main class="next-view-container">
                        <ResponsiveNavigationSidebar />
                        <section class="next-view-section"><button type="button">Synthetic workspace action</button></section>
                    </main>
                {{/in-element}}
            {{/if}}
        `);
        const frame = this.element.querySelector('iframe');
        const doc = frame.contentDocument;
        doc.body.classList.add('fleetbase-console');
        // Reuse the compiled styles already loaded by the real test app. A
        // second fetch from an about:blank iframe can resolve harness URLs
        // differently and does not exercise the layout being tested.
        const css = Array.from(document.styleSheets)
            .filter((sheet) => !sheet.href || new URL(sheet.href).origin === window.location.origin)
            .flatMap((sheet) => Array.from(sheet.cssRules, (rule) => rule.cssText))
            .join('\n');
        assert.true(css.includes('.next-sidebar'), 'actual application styles are available');
        const styleElement = doc.createElement('style');
        styleElement.textContent = css;
        doc.head.appendChild(styleElement);
        this.set('frameBody', doc.body);
        await settled();
        const nav = doc.querySelector('.next-sidebar');
        const workspace = doc.querySelector('.next-view-section');
        const style = () => frame.contentWindow.getComputedStyle(nav);
        assert.strictEqual(frame.contentWindow.innerWidth, 390);
        assert.strictEqual(style().visibility, 'hidden', 'initial drawer cannot cover workspace or receive focus');
        assert.strictEqual(Math.round(workspace.getBoundingClientRect().width), 390, 'workspace uses the mobile width');
        this.sidebar.toggle();
        await settled();
        assert.strictEqual(style().position, 'absolute');
        assert.strictEqual(style().visibility, 'visible');
        assert.strictEqual(Math.round(nav.getBoundingClientRect().width), 390, 'opened drawer overrides fixed desktop width');
        assert.strictEqual(Math.round(workspace.getBoundingClientRect().width), 390, 'opening does not squeeze workspace');
        assert.strictEqual(frame.contentWindow.getComputedStyle(doc.querySelector('.gutter')).display, 'none', 'desktop drag gutter is unavailable on mobile');
        this.sidebar.toggle();
        await settled();
        assert.strictEqual(style().visibility, 'hidden');
    });
});
