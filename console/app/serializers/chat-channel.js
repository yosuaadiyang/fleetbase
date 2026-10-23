import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';
import { getOwner } from '@ember/application';
import { isArray } from '@ember/array';
import { mergeChatFeed } from '../utils/merge-chat-feed';

export default class ChatChannelSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            participants: { embedded: 'always' },
            last_message: { embedded: 'always' },
        };
    }

    serialize(snapshot) {
        let json = {
            name: snapshot.attr('name'),
            meta: snapshot.attr('meta'),
        };

        return json;
    }

    normalize(typeClass, hash) {
        if (isArray(hash.feed)) {
            const store = getOwner(this).lookup('service:store');
            const existing = store.peekRecord('chat-channel', hash.uuid ?? hash.id);
            hash.feed = this.serializeFeed(hash.feed);
            if (existing?.preserve_feed_window) {
                hash.feed = mergeChatFeed(existing.feed, hash.feed);
                hash.feed_has_more = existing.feed_has_more;
                hash.feed_next_cursor = existing.feed_next_cursor;
            }
        }

        return super.normalize(...arguments);
    }

    serializeFeed(feed = []) {
        return feed.map((item) => this.serializeItem(item)).sortBy('created_at');
    }

    serializeItem(item) {
        switch (item.type) {
            case 'message':
                return { ...item, record: this.serializeItemType('chat-message', item.data) };
            case 'log':
                return { ...item, record: this.serializeItemType('chat-log', item.data) };
            case 'attachment':
                return { ...item, record: this.serializeItemType('chat-attachment', item.data) };
            default:
                return null;
        }
    }

    serializeItemType(modelType, data) {
        const owner = getOwner(this);
        const store = owner.lookup('service:store');
        const normalized = store.normalize(modelType, data);
        return store.push(normalized);
    }
}
