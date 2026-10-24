import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

// Mount once in the application, even if responsive headers render two trays.
export default class ChatSafetyReportHostComponent extends Component {
    @service chatSafety;
}
