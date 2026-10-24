import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import Service from '@ember/service';
import Evented from '@ember/object/evented';
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { setComponentTemplate } from '@ember/component';
import { render, click, fillIn, settled, waitUntil, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import ChatWindow from '@fleetbase/console/components/chat-window';
import { captureComponent } from '@fleetbase/console/tests/helpers/capture-component';

class Channel {
    id = 'room';
    name = 'Synthetic';
    created_by_uuid = 'viewer';
    participants = [{ id: 'participant', user_uuid: 'viewer', name: 'Synthetic' }];
    @tracked feed = [{ type: 'message', created_at: '2026-10-22T10:00:00Z', record: { id: 'new' } }];
    @tracked feed_has_more = true;
    @tracked feed_next_cursor = 'page-two';
}
module('Integration | Component | Contrust chat history', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(function () {
        this.owner.register(
            'service:chat-safety',
            class extends Service {
                status = { enabled: false };
                marker = {};
                canContribute = true;
            }
        );
        const state = this;
        this.sendCalls = [];
        this.owner.register(
            'service:chat',
            class extends Service.extend(Evented) {
                openChannels = [];
                sendMessage(...args) {
                    state.sendCalls.push(args);
                    return state.sendResponse();
                }
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                id = 'viewer';
            }
        );
        this.owner.register(
            'service:socket',
            class extends Service {
                killCount = 0;
                instance() {
                    return {
                        subscribe: () => ({
                            createConsumer: () => {
                                let finish;
                                return {
                                    next: () =>
                                        new Promise((resolve) => {
                                            finish = resolve;
                                        }),
                                    kill: () => {
                                        this.killCount++;
                                        finish?.({ done: true });
                                    },
                                    [Symbol.asyncIterator]() {
                                        return this;
                                    },
                                };
                            },
                        }),
                    };
                }
            }
        );
        const requests = (this.requests = []);
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, query) {
                    if (path.endsWith('available-participants')) return Promise.resolve({ users: [], meta: { has_more: false, next_cursor: null } });
                    requests.push(query);
                    return state.response();
                }
            }
        );
        this.owner.register(
            'component:chat-window/feed',
            setComponentTemplate(hbs`{{#each @channel.feed as |item|}}<div data-test-feed-item>{{item.record.id}}</div>{{/each}}`, class extends Component {})
        );
        this.channel = new Channel();
        this.captured = captureComponent(this.owner, 'chat-window', ChatWindow);
    });
    test('fetches an older page while retaining socket entries and scroll position', async function (assert) {
        let resolve;
        this.response = () =>
            new Promise((r) => {
                resolve = r;
            });
        await render(hbs`<ChatWindow @channel={{this.channel}} />`);
        const element = this.element.querySelector('.chat-window-messages-container');
        Object.defineProperty(element, 'scrollHeight', { configurable: true, get: () => this.channel.feed.length * 100 });
        // Synthetic layout: the fixture does not overflow a physical viewport.
        let scrollTop = 12;
        Object.defineProperty(element, 'scrollTop', {
            configurable: true,
            get: () => scrollTop,
            set: (value) => {
                scrollTop = value;
            },
        });
        const action = this.captured.instance.loadOlderHistory.perform();
        await waitUntil(() => this.requests.length === 1);
        this.channel.feed = [...this.channel.feed, { type: 'message', created_at: '2026-10-22T10:01:00Z', record: { id: 'live' } }];
        resolve({
            feed: [
                { type: 'message', created_at: '2026-10-21T10:00:00Z', data: { uuid: 'old' } },
                { type: 'message', created_at: '2026-10-22T10:00:00Z', data: { uuid: 'new' } },
            ],
            feed_has_more: false,
            feed_next_cursor: null,
        });
        await action;
        await settled();
        assert.deepEqual(
            this.channel.feed.map((x) => x.record.id),
            ['old', 'new', 'live']
        );
        assert.strictEqual(element.scrollTop, 112, 'prepend preserves viewport offset');
        assert.dom('[data-test-load-chat-history]').doesNotExist();
        assert.deepEqual(this.requests, [{ limit: 50, cursor: 'page-two' }]);
    });
    test('failed page preserves cursor/feed, shows a retry, and duplicate requests are dropped', async function (assert) {
        this.response = () => Promise.reject(new Error('offline'));
        await render(hbs`<ChatWindow @channel={{this.channel}} />`);
        await click('[data-test-load-chat-history]');
        assert.dom('[data-test-chat-history-error]').includesText('Periksa koneksi');
        assert.strictEqual(this.channel.feed_next_cursor, 'page-two');
        assert.strictEqual(this.channel.feed.length, 1);
        this.response = () => Promise.resolve({ feed: [], feed_has_more: false, feed_next_cursor: null });
        await click('[data-test-load-chat-history]');
        assert.dom('[data-test-chat-history-error]').doesNotExist();
        assert.strictEqual(this.requests.length, 2);
    });
    test('closing the window releases its socket consumer and chat event handler', async function (assert) {
        this.response = () => Promise.resolve({ feed: [], feed_has_more: false, feed_next_cursor: null });
        await render(hbs`<ChatWindow @channel={{this.channel}} />`);
        const socket = this.owner.lookup('service:socket');
        const chat = this.owner.lookup('service:chat');
        assert.true(this.channel.preserve_feed_window);
        await clearRender();
        assert.strictEqual(socket.killCount, 1);
        assert.false(chat.has('chat.closed'));
        assert.false(this.channel.preserve_feed_window);
    });
    test('pending message send locks controls and drops a second invocation', async function (assert) {
        let resolve;
        this.sendResponse = () => new Promise((r) => (resolve = r));
        await render(hbs`<ChatWindow @channel={{this.channel}} />`);
        await fillIn('textarea', 'Synthetic message');
        const first = this.captured.instance.sendMessage.perform();
        const second = this.captured.instance.sendMessage.perform();
        second.catch(() => {}); // Dropped task instances reject as cancelled.
        await waitUntil(() => this.sendCalls.length === 1);
        assert.dom('[data-test-chat-send]').isDisabled();
        assert.dom('textarea').isDisabled();
        resolve({});
        await first;
        await settled();
        assert.strictEqual(this.sendCalls.length, 1);
        assert.dom('textarea').hasValue('');
        assert.dom('[data-test-chat-send-error]').doesNotExist();
    });
    test('failed send retains draft and prevents blind resubmission until chat is reopened', async function (assert) {
        this.sendResponse = () => Promise.reject(new Error('Synthetic private transport failure'));
        await render(hbs`<ChatWindow @channel={{this.channel}} />`);
        await fillIn('textarea', 'Retained synthetic draft');
        await click('[data-test-chat-send]');
        assert.dom('textarea').hasValue('Retained synthetic draft');
        assert.dom('[data-test-chat-send-error]').includesText('Buka ulang chat');
        assert.dom('[data-test-chat-send-error]').doesNotIncludeText('Synthetic private transport');
        assert.dom('[data-test-chat-send]').isDisabled();
        await this.captured.instance.sendMessage.perform();
        assert.strictEqual(this.sendCalls.length, 1, 'uncertain send cannot be blindly retried in the same window');
    });
});
