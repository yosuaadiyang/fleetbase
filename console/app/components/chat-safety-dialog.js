import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class ChatSafetyDialogComponent extends Component {
    root = null;
    previousFocus = null;
    get portalRoot() {
        return document.getElementById('application-root-wormhole') ?? document.body;
    }
    @action enter(element) {
        this.root = element;
        this.previousFocus = element.ownerDocument.activeElement;
        element.querySelector('h2')?.focus();
    }
    @action keydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.args.close?.();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = [...this.root.querySelectorAll('a[href],button,input,select,textarea,[tabindex="0"]')].filter((node) => !node.disabled && node.getClientRects().length);
        const first = focusable[0],
            last = focusable.at(-1);
        if (!first) {
            event.preventDefault();
            return;
        }
        if (event.shiftKey && (event.target === first || event.target.tagName === 'H2')) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (event.target === last || event.target.tagName === 'H2')) {
            event.preventDefault();
            first.focus();
        }
    }
    willDestroy() {
        if (this.previousFocus?.isConnected) this.previousFocus.focus();
        super.willDestroy(...arguments);
    }
}
