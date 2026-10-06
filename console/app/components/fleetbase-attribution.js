import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import config from '@fleetbase/console/config/environment';

/**
 * Replaces @fleetbase/ember-ui's <FleetbaseAttribution> (an app component takes
 * precedence over an addon's) with this console's brand. Keeps the "Legal" link: the
 * AGPL-3.0 requires a modified version to keep showing the Appropriate Legal Notices
 * the original shows, and to offer its users the modified source (sections 5d and 13).
 */
export default class FleetbaseAttributionComponent extends Component {
    @service modalsManager;

    brandName = config.APP?.brandName;
    sourceCodeUrl = config.APP?.sourceCodeUrl;

    get disabled() {
        return config.APP?.disableFleetbaseAttribution === true;
    }

    get appVersion() {
        return config.version ? `v${config.version}` : null;
    }

    @action openLegalNotice() {
        this.modalsManager.show('modals/contrust-legal-notice', {
            title: `${this.brandName} Legal Notices`,
            brandName: this.brandName,
            sourceCodeUrl: this.sourceCodeUrl,
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            modalClass: 'modal-md fleetbase-legal-notice-modal',
        });
    }
}
