import Service, { inject as service } from '@ember/service';

export default class SocketSessionService extends Service {
    @service session;
    @service fetch;
    timer = null;
    pending = false;

    start() {
        if (this.timer) return;
        this.refresh();
        this.timer = setInterval(() => this.refresh(), 60000);
    }

    async refresh() {
        if (this.pending || !this.session.isAuthenticated || this.isDestroying || this.isDestroyed) return;
        this.pending = true;
        try {
            await this.fetch.get('contrust/socket-session', {}, { namespace: 'int/v1' });
        } catch {
            // Access failures close the socket server-side; retry on the next heartbeat.
        } finally {
            this.pending = false;
        }
    }

    willDestroy() {
        clearInterval(this.timer);
        super.willDestroy(...arguments);
    }
}
