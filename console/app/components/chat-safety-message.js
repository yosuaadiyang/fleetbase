import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { chatSafetyError } from '../utils/chat-safety-request';

export default class ChatSafetyMessageComponent extends Component {
    @service chatSafety;
    @service currentUser;
    @tracked failure = null;
    get visible() {
        return this.chatSafety.status?.enabled && typeof this.args.record?.id === 'string' && this.args.record?.sender?.user_uuid !== this.currentUser.id;
    }
    get error() {
        return this.failure?.marker === this.chatSafety.marker ? this.failure.text : '';
    }
    @action report() {
        try {
            this.chatSafety.selectReport({ type: 'message', id: this.args.record.id, name: this.args.record.sender?.name ?? 'Mantan anggota', preview: this.args.record.content });
        } catch (failure) {
            this.failure = { marker: this.chatSafety.marker, text: chatSafetyError(failure?.code).message };
        }
    }
}
