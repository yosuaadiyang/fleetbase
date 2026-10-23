import { module, test } from 'qunit';
import { mergeChatFeed } from '@fleetbase/console/utils/merge-chat-feed';
module('Unit | Utility | merge chat feed', function () {
    test('history cannot erase a socket message or duplicate the overlapping boundary', function (assert) {
        const item = (id, date, type = 'message') => ({ type, created_at: date, record: { id } });
        const live = item('live', '2026-10-22T10:01:00Z');
        const overlap = item('same', '2026-10-22T10:00:00Z');
        const result = mergeChatFeed([overlap, live], [item('old', '2026-10-21T10:00:00Z'), { ...overlap }]);
        assert.deepEqual(
            result.map((x) => x.record.id),
            ['old', 'same', 'live']
        );
        assert.strictEqual(result[1], overlap, 'existing live instance wins');
    });
    test('equal timestamps have stable type/id order and ids from different types remain distinct', function (assert) {
        const at = '2026-10-22T10:00:00Z';
        const result = mergeChatFeed(
            [],
            ['message', 'log', 'attachment'].map((type) => ({ type, created_at: at, record: { id: 'shared' } }))
        );
        assert.deepEqual(
            result.map((x) => x.type),
            ['attachment', 'log', 'message']
        );
        assert.deepEqual(mergeChatFeed(null, []), []);
    });
});
