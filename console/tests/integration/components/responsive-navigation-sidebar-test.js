import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import { render, settled, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import Evented from '@ember/object/evented';

module('Integration | responsive navigation sidebar', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(function () {
        this.owner.register(
            'service:media',
            class extends Service.extend(Evented) {
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
        this.noop = () => {};
        await render(hbs`
            <iframe title="Synthetic mobile navigation layout" style="width:390px;height:509px;border:0"></iframe>
            {{#if this.frameBody}}
                {{#in-element this.frameBody insertBefore=null}}
                    <main class="next-view-container">
                        <ResponsiveNavigationSidebar />
                        <section class="next-view-section">
                            <Layout::Section::Header @title="Service Rates" @onSearch={{this.noop}}>
                                <Button @text="Filter" @onClick={{this.noop}} />
                                <Button @text="Columns" @onClick={{this.noop}} />
                                <Button @text="Refresh" @onClick={{this.noop}} />
                                <Button @text="New" @onClick={{this.noop}} />
                            </Layout::Section::Header>
                            <div class="next-table-wrapper">
                                <table style="width:600px"><tbody><tr><td class="next-table-empty-state-cell">
                                    <Table::EmptyState
                                        @title="Set up your first service rate"
                                        @description="Synthetic layout only; no records are created."
                                        @primaryText="New service rate"
                                        @primaryAction={{this.noop}}
                                        @secondaryText="Refresh"
                                        @secondaryAction={{this.noop}}
                                    />
                                </td></tr></tbody></table>
                            </div>
                        </section>
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
        const controls = doc.querySelectorAll('.next-view-section-subheader-actions button, .next-table-empty-state-actions button');
        assert.strictEqual(controls.length, 6);
        for (const control of controls) {
            const bounds = control.getBoundingClientRect();
            assert.true(bounds.left >= 0 && bounds.right <= 390, `${control.textContent.trim()} stays reachable without horizontal scrolling`);
            const hit = doc.elementFromPoint(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
            assert.true(control === hit || control.contains(hit), `${control.textContent.trim()} receives a real center click`);
        }
        const header = doc.querySelector('.next-view-section-subheader').getBoundingClientRect();
        const toolbar = doc.querySelector('.next-view-section-subheader-actions').getBoundingClientRect();
        assert.true(toolbar.bottom <= header.bottom, 'header grows around wrapped toolbar so table cannot cover its actions');
        // Test both sides of the drawer breakpoint with actual compiled CSS.
        for (const width of [320, 767, 768, 1023, 1366]) {
            frame.style.width = `${width}px`;
            this.media.isMobile = width < 768;
            await settled();
            const viewportWidth = frame.contentWindow.innerWidth;
            assert.strictEqual(viewportWidth, width);
            for (const control of controls) {
                const bounds = control.getBoundingClientRect();
                const hit = doc.elementFromPoint(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
                assert.true(bounds.left >= 0 && bounds.right <= width, `${width}px: ${control.textContent.trim()} fits`);
                assert.true(control === hit || control.contains(hit), `${width}px: ${control.textContent.trim()} receives a real click`);
            }
            const headerBounds = doc.querySelector('.next-view-section-subheader').getBoundingClientRect();
            const toolbarBounds = doc.querySelector('.next-view-section-subheader-actions').getBoundingClientRect();
            assert.true(toolbarBounds.bottom <= headerBounds.bottom, `${width}px: toolbar stays inside header`);
        }
        frame.style.width = '390px';
        this.media.isMobile = true;
        await settled();
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
