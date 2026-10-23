import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import ChatTray from '@fleetbase/console/components/chat-tray';

module('Integration | Component | Contrust chat directory', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(function () {
        const context = this;
        this.calls = [];
        this.owner.register(
            'service:chat',
            class extends Service {
                loadChannels = { perform() {} };
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, query) {
                    context.calls.push(query);
                    return context.response(query);
                }
            }
        );
        class CapturedTray extends ChatTray {
            constructor() {
                super(...arguments);
                context.tray = this;
            }
        }
        this.owner.register(
            'component:chat-tray',
            setComponentTemplate(hbs`<div>{{#each this.availableUsers as |user|}}<span data-test-contact>{{user.name}}</span>{{/each}}{{this.directoryError}}</div>`, CapturedTray)
        );
    });
    test('appends organization pages without duplicates or losing selected participants', async function (assert) {
        this.response = (query) =>
            Promise.resolve({
                users: query.cursor
                    ? [
                          { uuid: 'u2', name: 'Two' },
                          { uuid: 'u3', name: 'Three' },
                      ]
                    : [
                          { uuid: 'u1', name: 'One' },
                          { uuid: 'u2', name: 'Two' },
                      ],
                meta: { has_more: !query.cursor, next_cursor: query.cursor ? null : 'next' },
            });
        await render(hbs`<ChatTray />`);
        await this.tray.loadAvailableUsers.perform();
        this.tray.toggleSelectedUser(this.tray.availableUsers[0]);
        this.tray.loadMoreUsers();
        await settled();
        assert.deepEqual(
            this.tray.availableUsers.map((u) => u.id),
            ['u1', 'u2', 'u3']
        );
        assert.deepEqual(
            this.tray.selectedUsers.map((u) => u.id),
            ['u1']
        );
        assert.false(this.tray.directoryHasMore);
        assert.strictEqual(this.calls[1].cursor, 'next');
        assert.strictEqual(this.calls[1].limit, 25);
    });
    test('a slower old search cannot replace newer search results', async function (assert) {
        let resolveOld;
        this.response = (query) =>
            query.query === 'old'
                ? new Promise((r) => {
                      resolveOld = r;
                  })
                : Promise.resolve({ users: [{ uuid: 'new', name: 'New' }], meta: { has_more: false, next_cursor: null } });
        await render(hbs`<ChatTray />`);
        const old = this.tray.loadAvailableUsers.perform({ query: 'old' });
        old.catch(() => {});
        await this.tray.loadAvailableUsers.perform({ query: 'new' });
        resolveOld({ users: [{ uuid: 'old', name: 'Old' }], meta: { has_more: true, next_cursor: 'obsolete' } });
        await settled();
        assert.deepEqual(
            this.tray.availableUsers.map((u) => u.id),
            ['new']
        );
        assert.strictEqual(this.tray.directoryCursor, null);
    });
    test('failed continuation preserves loaded choices and permits retry', async function (assert) {
        let failing = false;
        this.response = () => (failing ? Promise.reject(new Error('offline')) : Promise.resolve({ users: [{ uuid: 'u1', name: 'One' }], meta: { has_more: true, next_cursor: 'retry' } }));
        await render(hbs`<ChatTray />`);
        await this.tray.loadAvailableUsers.perform();
        failing = true;
        await this.tray.loadAvailableUsers.perform({ cursor: 'retry' });
        assert.deepEqual(
            this.tray.availableUsers.map((u) => u.id),
            ['u1']
        );
        assert.strictEqual(this.tray.directoryCursor, 'retry');
        assert.ok(this.tray.directoryError);
        failing = false;
        await this.tray.loadAvailableUsers.perform({ cursor: 'retry' });
        assert.strictEqual(this.tray.directoryError, '');
    });
});
