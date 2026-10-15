export function initialize(appInstance) {
    appInstance.lookup('service:socket-session').start();
}
export default { initialize };
