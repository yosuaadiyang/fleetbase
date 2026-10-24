import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { render, click, fillIn, settled, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const state = (props = {}) => ({
    enabled: true,
    policy_version: '2026-10-23.1',
    policy_url: 'https://contrust.website/terms',
    consented: false,
    moderator: false,
    blocked_users: [],
    blocked_has_more: false,
    blocked_next_cursor: null,
    block_scope: 'direct_messages_and_new_invitations',
    shared_groups_remain_visible: true,
    ...props,
});
const id = '00000000-0000-4000-8000-000000000001';
const response = (value, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => value });
const dialog = () => document.querySelector('.contrust-chat-safety-dialog');
const button = (text) => [...(dialog()?.querySelectorAll('button') ?? [])].find((node) => node.textContent.replace(/\s+/g, ' ').trim() === text);

module('Integration | Component | Contrust chat safety', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(async function () {
        this.owner.register(
            'service:session',
            class extends Service {
                @tracked isAuthenticated = true;
                data = { authenticated: { token: '1|SYNTHETICSESSIONTOKENABCDEFGHIJK' } };
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                @tracked id = 'viewer';
                @tracked companyId = 'company_one';
            }
        );
        this.safety = this.owner.lookup('service:chat-safety');
        this.user = this.owner.lookup('service:current-user');
        this.safety.fetch = { host: 'https://api.contrust.example.test' };
        this.writes = [];
        this.server = state();
        this.handler = () => response(this.server);
        this.safety.transport = async (url, options) => {
            const body = options.body ? JSON.parse(options.body) : null;
            if (options.method !== 'GET') this.writes.push({ url, method: options.method, body });
            return this.handler(url, options, body);
        };
        await this.safety.refresh();
    });
    hooks.afterEach(function () {
        for (const key of Object.keys(window.sessionStorage))
            if (key.startsWith('contrust:chat-report:') && decodeURIComponent(key).includes('api.contrust.example.test')) window.sessionStorage.removeItem(key);
    });
    test('policy renders an unchecked decision and sends only after explicit confirmation', async function (assert) {
        this.handler = (_url, options) => (options.method === 'POST' ? response({ consented: true, policy_version: this.server.policy_version }) : response(this.server));
        await render(hbs`<ChatSafetyPolicy />`);
        assert.dom('input[type=checkbox]').isNotChecked();
        assert.dom('button').isDisabled();
        assert.strictEqual(this.writes.length, 0);
        await click('input[type=checkbox]');
        assert.strictEqual(this.writes.length, 0);
        await click('button');
        assert.strictEqual(this.writes.length, 1);
        assert.true(this.safety.canContribute);
        assert.dom('input[type=checkbox]').doesNotExist();
    });
    test('policy agreement from one organization cannot remain checked in another', async function (assert) {
        await render(hbs`<ChatSafetyPolicy />`);
        await click('input[type=checkbox]');
        this.user.companyId = 'company_two';
        await settled();
        assert.dom('input[type=checkbox]').isNotChecked();
        assert.strictEqual(this.writes.length, 0);
    });
    test('report preview is escaped; opening, typing and checking alone never send', async function (assert) {
        await render(hbs`<p data-test-safety-background>Existing application content</p><ChatSafetyCenter /><ChatSafetyReportHost />`);
        this.safety.selectReport({ type: 'message', id: 'message_one', name: 'Synthetic', preview: '<img src=x onerror=alert(1)>' });
        await settled();
        assert.dom('[data-test-safety-background]').hasText('Existing application content');
        assert.dom('.contrust-chat-report-preview', document).includesText('<img');
        assert.dom('.contrust-chat-report-preview img', document).doesNotExist();
        await fillIn(dialog().querySelector('select'), 'privacy');
        await fillIn(dialog().querySelector('textarea'), 'Synthetic details');
        await click(dialog().querySelector('input[type=checkbox]'));
        assert.strictEqual(this.writes.length, 0);
        assert.dom(button('Konfirmasi kirim laporan')).isNotDisabled();
    });
    test('uncertain report survives reopening and explicitly retries the exact nonce/payload', async function (assert) {
        let fail = true;
        this.handler = (_url, options) => (options.method === 'POST' ? (fail ? response({}, 503) : response({ id, status: 'open' }, 201)) : response(this.server));
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        const target = { type: 'message', id: 'message_one', name: 'Synthetic', preview: 'Selected content' };
        this.safety.selectReport(target);
        await settled();
        await fillIn(dialog().querySelector('select'), 'spam');
        await fillIn(dialog().querySelector('textarea'), 'Synthetic details');
        await click(dialog().querySelector('input[type=checkbox]'));
        await click(button('Konfirmasi kirim laporan'));
        assert.strictEqual(this.writes.length, 1);
        const first = this.writes[0].body;
        assert.ok(first.request_id);
        await click(dialog().querySelector('[aria-label="Tutup keamanan chat"]'));
        this.safety.selectReport(target);
        await settled();
        assert.dom(dialog().querySelector('textarea')).hasValue('Synthetic details').isDisabled();
        assert.dom(dialog().querySelector('input[type=checkbox]')).isNotChecked();
        fail = false;
        await click(dialog().querySelector('input[type=checkbox]'));
        await click(button('Konfirmasi kirim laporan'));
        assert.deepEqual(this.writes[1].body, first);
        assert.dom(dialog()).includesText('Laporan diterima pengelola organisasi.');
    });
    test('changed organization closes the selected report and does not transmit it', async function (assert) {
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        this.safety.selectReport({ type: 'user', id: 'user_peer', name: 'Synthetic' });
        await settled();
        assert.ok(dialog());
        this.user.companyId = 'company_two';
        await settled();
        assert.notOk(dialog());
        assert.strictEqual(this.writes.length, 0);
    });
    test('stored block list can remove an anonymous former member only after confirmation', async function (assert) {
        this.server = state({ consented: true, blocked_users: [{ id: 'user_former', name: 'Former member' }] });
        await this.safety.refresh();
        this.handler = (_url, options) => (options.method === 'DELETE' ? response({ user: 'user_former', blocked: false }) : response(this.server));
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        await click('[aria-label="Keamanan chat"]');
        assert.dom(dialog()).includesText('Mantan anggota');
        await click(button('Buka blokir pengguna'));
        assert.strictEqual(this.writes.length, 0);
        await click(dialog().querySelector('input[type=checkbox]'));
        await click(button('Konfirmasi simpan'));
        assert.strictEqual(this.writes.length, 1);
        assert.dom(dialog()).includesText('Blokir dibuka.');
        assert.notOk(button('Buka blokir pengguna'));
    });
    test('block pagination reads the server cursor and failed page cannot show stale rows', async function (assert) {
        this.server = state({ blocked_users: [{ id: 'user_former', name: 'Former member' }], blocked_has_more: true, blocked_next_cursor: 'user_former' });
        await this.safety.refresh();
        this.handler = (url) => (url.includes('blocked_after=') ? response({}, 503) : response(this.server));
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        await click('[aria-label="Keamanan chat"]');
        await click(button('Halaman berikutnya'));
        assert.notOk(button('Buka blokir pengguna'));
        assert.dom(dialog()).includesText('belum dapat diperiksa');
        assert.strictEqual(this.writes.length, 0);
    });
    test('ordinary member has no moderation entry point or background review request', async function (assert) {
        let reportReads = 0;
        this.handler = (url) => {
            if (url.endsWith('/reports')) reportReads++;
            return response(this.server);
        };
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        await click('[aria-label="Keamanan chat"]');
        assert.notOk(button('Review laporan organisasi'));
        assert.strictEqual(reportReads, 0);
    });
    test('owner review displays bounded selected context as text and requires a separate confirmed outcome', async function (assert) {
        this.server = state({ moderator: true });
        await this.safety.refresh();
        const report = {
            id,
            reason: 'privacy',
            status: 'open',
            resolution: null,
            created_at: '2026-10-24',
            context: {
                type: 'message',
                target: { id: null, name: 'Former member' },
                details: '<script>plain text</script>',
                message: { id: 'message_one', content: 'Selected synthetic message' },
            },
        };
        this.handler = (url, options, body) =>
            options.method === 'PATCH'
                ? response({ id, status: body.status })
                : url.endsWith('/reports')
                  ? response({ reports: [report], has_more: false, next_cursor: null })
                  : response(this.server);
        await render(hbs`<ChatSafetyCenter /><ChatSafetyReportHost />`);
        await click('[aria-label="Keamanan chat"]');
        await click(button('Review laporan organisasi'));
        assert.dom(dialog()).includesText('Selected synthetic message');
        assert.dom('.contrust-chat-safety-row script', document).doesNotExist();
        await click(button('Catat hasil review'));
        assert.strictEqual(this.writes.length, 0);
        assert.dom(button('Konfirmasi simpan')).isDisabled();
        await click(dialog().querySelector('input[type=checkbox]'));
        await click(button('Konfirmasi simpan'));
        assert.strictEqual(this.writes[0].method, 'PATCH');
        assert.deepEqual(this.writes[0].body, { status: 'reviewing', resolution: 'support_followup' });
    });
    test('actual user controls disclose block scope and never write on initial click', async function (assert) {
        this.peer = { id: 'peer_uuid', public_id: 'user_peer', name: 'Synthetic peer' };
        await render(hbs`<ChatSafetyUser @user={{this.peer}} />`);
        const trigger = [...this.element.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Blokir pesan langsung');
        await click(trigger);
        assert.dom(dialog()).includesText('Grup operasional bersama tetap terlihat');
        assert.strictEqual(this.writes.length, 0);
        await click(dialog().querySelector('[aria-label="Tutup keamanan chat"]'));
        assert.strictEqual(document.activeElement, trigger, 'closing the dialog restores the invoking control');
        await clearRender();
        assert.notOk(dialog());
    });
    test('two responsive chat trays share exactly one application report form', async function (assert) {
        await render(hbs`<ChatSafetyCenter /><ChatSafetyCenter /><ChatSafetyReportHost />`);
        this.safety.selectReport({ type: 'user', id: 'user_peer', name: 'Synthetic peer' });
        await settled();
        assert.strictEqual(document.querySelectorAll('.contrust-chat-safety-dialog').length, 1);
        assert.strictEqual(this.writes.length, 0);
    });
});
