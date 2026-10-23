import BaseChatTray from '@fleetbase/ember-ui/components/chat-tray';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ChatTrayComponent extends BaseChatTray {
    @tracked directoryHasMore = false;
    @tracked directoryCursor = null;
    @tracked directoryError = '';

    @task({ restartable: true }) *loadAvailableUsers(params = {}) {
        const append = Boolean(params.cursor);
        if (!append) {
            this.directoryCursor = null;
            this.directoryHasMore = false;
        }
        this.directoryError = '';
        try {
            const page = yield this.fetch.get('chat-channels/available-participants', {
                query: this.contactSearchQuery,
                limit: 25,
                ...params,
            });
            const users = page.users.map((data) => this.store.push(this.store.normalize('user', data)));
            const merged = new Map((append ? this.availableUsers : []).map((user) => [user.id, user]));
            users.forEach((user) => merged.set(user.id, user));
            this.availableUsers = [...merged.values()];
            this.directoryHasMore = page.meta.has_more;
            this.directoryCursor = page.meta.next_cursor;
            return this.availableUsers;
        } catch {
            this.directoryError = 'Anggota belum bisa dimuat. Coba lagi.';
            if (!append) this.availableUsers = [];
        }
    }

    @action loadMoreUsers() {
        if (this.directoryHasMore) this.loadAvailableUsers.perform({ cursor: this.directoryCursor });
    }

    @action retryUsers() {
        this.loadAvailableUsers.perform();
    }
}
