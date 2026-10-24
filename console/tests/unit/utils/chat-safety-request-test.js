import { module, test } from 'qunit';
import { chatSafetyRequest } from '@fleetbase/console/utils/chat-safety-request';
const identifier = '00000000-0000-4000-8000-000000000001';
const options = { host: 'https://api.contrust.example.test', sessionToken: '1|SYNTHETICSESSIONTOKENABCDEFGHIJK' };
const reply = (value, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => value });

module('Unit | Utility | chat safety request', function () {
    test('review uses a fixed own endpoint and excludes route ID from the request body', async function (assert) {
        let sent;
        await chatSafetyRequest({
            ...options,
            action: 'review',
            input: { id: identifier, status: 'resolved', resolution: 'support_followup' },
            fetchImpl: async (url, request) => {
                sent = { url, request };
                return reply({ id: identifier, status: 'resolved' });
            },
        });
        assert.strictEqual(sent.url, options.host + '/int/v1/contrust/chat-safety/reports/' + identifier);
        assert.deepEqual(JSON.parse(sent.request.body), { status: 'resolved', resolution: 'support_followup' });
        assert.strictEqual(sent.request.redirect, 'error');
        assert.strictEqual(sent.request.headers.Authorization, 'Bearer ' + options.sessionToken);
    });
    test('invalid identifiers or server-owned fields never produce a request', async function (assert) {
        let calls = 0;
        const fetchImpl = async () => {
            calls++;
            return reply({});
        };
        for (const input of [
            { id: '../foreign', status: 'resolved', resolution: 'support_followup' },
            { id: identifier, status: 'resolved', resolution: 'support_followup', company_uuid: 'foreign' },
        ])
            await assert.rejects(chatSafetyRequest({ ...options, action: 'review', input, fetchImpl }));
        await assert.rejects(chatSafetyRequest({ ...options, host: 'http://api.example.test', action: 'reports', fetchImpl }));
        assert.strictEqual(calls, 0);
    });
    test('owner report pages are bounded and use only the server cursor', async function (assert) {
        let url;
        const result = await chatSafetyRequest({
            ...options,
            action: 'reports',
            input: { before: identifier },
            fetchImpl: async (value) => {
                url = value;
                return reply({ reports: [], has_more: false, next_cursor: null });
            },
        });
        assert.strictEqual(url, options.host + '/int/v1/contrust/chat-safety/reports?before=' + identifier);
        assert.deepEqual(result.reports, []);
        await assert.rejects(chatSafetyRequest({ ...options, action: 'reports', fetchImpl: async () => reply({ reports: Array(26).fill({}), has_more: false, next_cursor: null }) }));
    });
    test('anonymous legacy authors are allowed without accepting malformed report context', async function (assert) {
        const report = {
            id: identifier,
            reason: 'spam',
            status: 'open',
            resolution: null,
            created_at: '2026-10-24',
            context: { type: 'message', target: { id: null, name: 'Former member' }, details: '', message: { id: 'message_old', content: '<script>escaped as text</script>' } },
        };
        const fetchImpl = async () => reply({ reports: [report], has_more: false, next_cursor: null });
        const value = await chatSafetyRequest({ ...options, action: 'reports', fetchImpl });
        assert.strictEqual(value.reports.length, 1);
        report.context.target.id = { unsafe: true };
        await assert.rejects(chatSafetyRequest({ ...options, action: 'reports', fetchImpl }));
    });
    test('provider failures are redacted and mutations are never automatically retried', async function (assert) {
        let calls = 0;
        await assert.rejects(
            chatSafetyRequest({
                ...options,
                action: 'unblock',
                input: { user: 'user_peer' },
                fetchImpl: async () => {
                    calls++;
                    return reply({ errors: ['PRIVATE_PROVIDER_BODY'] }, 503);
                },
            }),
            (failure) => failure.code === 'CHAT_SAFETY_UNAVAILABLE' && !failure.message.includes('PRIVATE_PROVIDER_BODY')
        );
        assert.strictEqual(calls, 1);
    });
    test('transport timeout remains finite even if fetch ignores cancellation', async function (assert) {
        await assert.rejects(
            chatSafetyRequest({ ...options, action: 'reports', timeoutMs: 5, fetchImpl: () => new Promise(() => {}) }),
            (failure) => failure.code === 'CHAT_SAFETY_UNAVAILABLE'
        );
    });
});
