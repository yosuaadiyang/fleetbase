import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/** Keep the mobile drawer out of the workspace without changing desktop choice. */
export default class ResponsiveNavigationSidebarComponent extends Component {
    @service sidebar;
    @service media;
    previousMobile = null;
    desktopState = 'visible';

    @action syncBreakpoint() {
        const mobile = this.media.isMobile === true;
        if (mobile === this.previousMobile) return;
        const initial = this.previousMobile === null;
        this.previousMobile = mobile;
        // Route-specific disabled navigation remains authoritative.
        if (this.sidebar.isDisabled) return;
        if (mobile) {
            this.desktopState = this.sidebar.state;
            this.sidebar.hideNow();
        } else if (!initial) {
            this.sidebar.transitionTo(this.desktopState, { immediate: this.desktopState === 'hidden' });
        }
    }
}
