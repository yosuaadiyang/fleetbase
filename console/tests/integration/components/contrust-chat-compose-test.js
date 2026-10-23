import { module, test } from 'qunit';
import { setupRenderingTest } from '@fleetbase/console/tests/helpers';
import Service from '@ember/service';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | Contrust real chat compose', function (hooks) {
    setupRenderingTest(hooks);
    hooks.beforeEach(function () {
        this.owner.register(
            'service:chat',
            class extends Service {
                loadChannels = { isIdle: true, perform() {} };
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                get() {
                    return Promise.resolve({
                        users: [{ uuid: 'synthetic-recipient', name: 'Synthetic Recipient', email: 'recipient@example.test' }],
                        meta: { has_more: false, next_cursor: null },
                    });
                }
            }
        );
    });

    test('actual inbox New Chat opens the owned compose template and loads selectable members', async function (assert) {
        // Use the real tray and nested templates. Replacing either template
        // would hide the colocated-template/re-export regression.
        await render(hbs`<ChatTray />`);
        await click('[aria-label="Open chat inbox"]');
        await click('.chat-inbox-panel-header button');
        assert.dom('.chat-compose-panel').exists();
        assert.dom('#chat-compose-name').exists();
        assert.dom('#chat-compose-search').exists();
        assert.dom('.chat-compose-contact-list').includesText('Synthetic Recipient');
        assert.dom('[data-test-create-chat]').isDisabled();
        await click('.chat-compose-contact-list button');
        assert.dom('.chat-compose-selected-users').includesText('Synthetic Recipient');
        assert.dom('[data-test-create-chat]').isNotDisabled();
        await click('[aria-label="Close new chat"]');
        assert.dom('.chat-compose-panel').doesNotExist();
    });
});
