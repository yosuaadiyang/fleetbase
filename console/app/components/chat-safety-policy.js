import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { chatSafetyError } from '../utils/chat-safety-request';

export default class ChatSafetyPolicyComponent extends Component {
    @service chatSafety;
    @service session;
    @tracked agreement = null;
    @tracked feedback = null;
    @action refresh() {
        Promise.resolve()
            .then(() => {
                if (!this.isDestroying && !this.isDestroyed && this.session.isAuthenticated) return this.chatSafety.refresh();
            })
            .catch(() => {});
    }
    get checked() {
        return this.agreement?.marker === this.chatSafety.marker && this.agreement?.version === this.chatSafety.status?.policy_version && this.agreement?.checked === true;
    }
    get error() {
        return this.feedback?.marker === this.chatSafety.marker ? this.feedback.text : '';
    }
    @action toggle(event) {
        this.agreement = { marker: this.chatSafety.marker, version: this.chatSafety.status?.policy_version, checked: event.target.checked };
    }
    @action async accept() {
        if (!this.checked || this.chatSafety.checking || this.chatSafety.busy) return;
        const marker = this.chatSafety.marker;
        try {
            await this.chatSafety.acceptPolicy(marker);
        } catch (failure) {
            if (!this.isDestroying && !this.isDestroyed && this.chatSafety.marker === marker) this.feedback = { marker, text: chatSafetyError(failure?.code).message };
        }
    }
}
