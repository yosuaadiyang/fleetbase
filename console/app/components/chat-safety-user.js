import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { chatSafetyError } from '../utils/chat-safety-request';

export default class ChatSafetyUserComponent extends Component {
    @service chatSafety;
    @service currentUser;
    @tracked confirmation = null;
    @tracked failure = null;
    pending = null;
    get person() {
        return { id: this.args.user?.public_id, name: this.args.user?.name };
    }
    get visible() {
        return this.chatSafety.status?.enabled && typeof this.person.id === 'string' && this.args.user?.id !== this.currentUser.id;
    }
    get confirming() {
        return this.confirmation?.marker === this.chatSafety.marker;
    }
    get error() {
        return this.failure?.marker === this.chatSafety.marker ? this.failure.text : '';
    }
    @action report() {
        try {
            this.chatSafety.selectReport({ type: 'user', ...this.person });
        } catch (failure) {
            this.failure = { marker: this.chatSafety.marker, text: chatSafetyError(failure?.code).message };
        }
    }
    @action confirm(remove) {
        this.confirmation = { marker: this.chatSafety.marker, person: this.person, remove };
    }
    @action close() {
        this.confirmation = null;
    }
    @action async save() {
        const chosen = this.confirmation;
        if (!chosen || chosen.marker !== this.chatSafety.marker || this.chatSafety.busy || this.pending === chosen) return;
        this.pending = chosen;
        try {
            await this.chatSafety[chosen.remove ? 'unblockUser' : 'blockUser'](chosen.person, chosen.marker);
            if (!this.isDestroying && !this.isDestroyed && chosen.marker === this.chatSafety.marker) {
                this.confirmation = null;
                this.failure = { marker: chosen.marker, text: chosen.remove ? 'Blokir dibuka.' : 'Blokir tersimpan.' };
            }
        } catch (failure) {
            if (!this.isDestroying && !this.isDestroyed && chosen.marker === this.chatSafety.marker) this.failure = { marker: chosen.marker, text: chatSafetyError(failure?.code).message };
        } finally {
            if (this.pending === chosen) this.pending = null;
        }
    }
}
