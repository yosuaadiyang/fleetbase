import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';

export default class InputLabelComponent extends Component {
    @tracked isHelpOpen = false;
    helpId = `input-help-${guidFor(this)}`;

    @action showHelp() {
        this.isHelpOpen = true;
    }

    @action hideHelp() {
        this.isHelpOpen = false;
    }

    @action closeOnEscape(event) {
        if (event.key === 'Escape' && this.isHelpOpen) {
            event.stopPropagation();
            this.hideHelp();
        }
    }
}
