import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';

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
const reply = (value) => ({ ok: true, status: 200, json: async () => value });
const deferred = () => {
    let resolve;
    const promise = new Promise((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
};

module('Unit | Service | chat safety', function (hooks) {
    setupTest(hooks);
    hooks.beforeEach(function () {
        this.safety = this.owner.lookup('service:chat-safety');
        this.session = { isAuthenticated: true, data: { authenticated: { token: '1|SYNTHETICSESSIONTOKENABCDEFGHIJK' } } };
        this.user = { id: 'user_one', companyId: 'company_one' };
        this.safety.session = this.session;
        this.safety.currentUser = this.user;
        this.safety.fetch = { host: 'https://api.contrust.example.test' };
    });
    test('anonymous or incomplete sessions never request safety data', async function (assert) {
        let calls = 0;
        this.safety.transport = async () => {
            calls++;
            return reply(state());
        };
        this.session.isAuthenticated = false;
        await assert.rejects(this.safety.refresh(), (error) => error.code === 'CHAT_SAFETY_SESSION');
        assert.strictEqual(calls, 0);
        assert.false(this.safety.canContribute);
    });
    test('verified disabled backend preserves legacy chat; failed verification fails closed', async function (assert) {
        this.safety.transport = async () => reply({ enabled: false });
        await this.safety.refresh();
        assert.true(this.safety.canContribute);
        this.safety.transport = async () => {
            throw new Error('PRIVATE_PROVIDER_BODY');
        };
        await assert.rejects(this.safety.refresh());
        assert.false(this.safety.canContribute);
        assert.false(this.safety.error.includes('PRIVATE_PROVIDER_BODY'));
    });
    test('consent requires server ACK and duplicate mutations are rejected', async function (assert) {
        const pending = deferred();
        let writes = 0;
        this.safety.transport = async (_url, options) => {
            if (options.method === 'POST') {
                writes++;
                return pending.promise;
            }
            return reply(state());
        };
        await this.safety.refresh();
        assert.false(this.safety.canContribute);
        const action = this.safety.acceptPolicy();
        assert.false(this.safety.canContribute);
        await assert.rejects(this.safety.acceptPolicy());
        pending.resolve(reply({ consented: true, policy_version: '2026-10-23.1' }));
        await action;
        assert.true(this.safety.canContribute);
        assert.strictEqual(writes, 1);
    });
    test('late consent and stale callbacks cannot modify a replacement organization', async function (assert) {
        const pending = deferred();
        this.safety.transport = async (_url, options) => (options.method === 'POST' ? pending.promise : reply(state()));
        await this.safety.refresh();
        const marker = this.safety.marker;
        const action = this.safety.acceptPolicy(marker);
        action.catch(() => {});
        this.user.companyId = 'company_two';
        assert.strictEqual(this.safety.status, null);
        await this.safety.refresh();
        pending.resolve(reply({ consented: true, policy_version: '2026-10-23.1' }));
        await assert.rejects(action, (error) => error.code === 'CHAT_SAFETY_SESSION');
        await assert.rejects(this.safety.acceptPolicy(marker), (error) => error.code === 'CHAT_SAFETY_SESSION');
        assert.false(this.safety.canContribute);
        assert.false(this.safety.busy);
    });
    test('one shared refresh cannot overwrite a newer explicit acknowledgement', async function (assert) {
        const pending = deferred();
        let reads = 0;
        this.safety.transport = async (_url, options) =>
            options.method === 'POST' ? reply({ consented: true, policy_version: '2026-10-23.1' }) : ++reads === 1 ? reply(state()) : pending.promise;
        await this.safety.refresh();
        const first = this.safety.refresh();
        const second = this.safety.refresh();
        assert.strictEqual(first, second);
        await this.safety.acceptPolicy();
        pending.resolve(reply(state()));
        await first;
        assert.true(this.safety.canContribute);
        assert.strictEqual(reads, 2);
    });
    test('reading a later block page never replaces confirmed policy or the first page', async function (assert) {
        this.safety.transport = async (url) => reply(url.includes('blocked_after=') ? state({ blocked_users: [{ id: 'user_former', name: 'Former member' }] }) : state({ consented: true }));
        await this.safety.refresh();
        const page = await this.safety.getBlockedPage('user_cursor');
        assert.strictEqual(page.blocked_users[0].id, 'user_former');
        assert.strictEqual(this.safety.status.blocked_users.length, 0);
        assert.true(this.safety.canContribute);
    });
});
