import PaginationComponent from '@fleetbase/ember-ui/components/pagination';
import { action } from '@ember/object';

// Empty tables must report zero visible rows, including Laravel's null range.
export default class ContrustPaginationComponent extends PaginationComponent {
    get from() {
        return Number(this.args.meta?.total) === 0 ? 0 : (this.args.meta?.from ?? 1);
    }
    get to() {
        return Number(this.args.meta?.total) === 0 ? 0 : (this.args.meta?.to ?? 1);
    }
    @action incrementPage(step, event) {
        event?.preventDefault();
        // Mobile controls are anchors; aria-disabled alone does not block clicks.
        if ((step > 0 && !this.canStepForward) || (step < 0 && !this.canStepBackward)) return false;
        return super.incrementPage(step);
    }
}
