import config from '@fleetbase/console/config/environment';

export function initialize(appInstance) {
    // Tests start the service explicitly with their own session/transport. Eager
    // lookup here would cache the real session before those doubles can register.
    if (config.environment === 'test') return;
    appInstance.lookup('service:socket-session').start();
}
export default { initialize };
