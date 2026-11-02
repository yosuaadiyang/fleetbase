import { module, test } from 'qunit';
import { chatSafetyRequest } from '@fleetbase/console/utils/chat-safety-request';
const uuid = '00000000-0000-4000-8000-000000000001';
const options = { host: 'https://api.contrust.example.test', sessionToken: '1|SYNTHETICSESSIONTOKENABCDEFGHIJK' };
const reply = (value, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => value });
const types = ['order_comment', 'issue', 'fuel_report', 'proof'];
const preview = (type) => ({
    type,
    target: 'selected_synthetic',
    policy_version: '2026-11-01.1',
    preview_receipt: 'eyJ2IjoxfQ.' + 'a'.repeat(64),
    expires_in: 600,
    context: { type, target: { id: null, name: 'Unattributed' }, message: { id: 'selected_synthetic', content: 'Selected only' }, order_id: null, policy_version: '2026-11-01.1' },
});
module('Unit | Utility | operational safety', function () {
    test('all four previews use fixed routes and bind returned type, ID and minimal context', async function (assert) {
        for (const type of types) {
            const input = { type, target: 'selected_synthetic' },
                value = preview(type);
            let url;
            const result = await chatSafetyRequest({
                ...options,
                action: 'contentPreview',
                input,
                fetchImpl: async (path) => {
                    url = path;
                    return reply(value);
                },
            });
            assert.strictEqual(result.context.message.id, input.target);
            assert.strictEqual(url, options.host + '/int/v1/contrust/content-safety/preview?type=' + type + '&target=' + input.target);
            for (const altered of [
                { ...value, target: 'different_record' },
                { ...value, context: { ...value.context, location: 'private' } },
                { ...value, expires_in: 601 },
            ])
                await assert.rejects(chatSafetyRequest({ ...options, action: 'contentPreview', input, fetchImpl: async () => reply(altered) }));
        }
    });
    test('server-owned injected fields never trigger a preview request', async function (assert) {
        let calls = 0;
        for (const extra of ['company_uuid', 'author_uuid', 'order_uuid'])
            await assert.rejects(
                chatSafetyRequest({
                    ...options,
                    action: 'contentPreview',
                    input: { type: 'issue', target: 'selected_synthetic', [extra]: 'foreign' },
                    fetchImpl: async () => {
                        calls++;
                        return reply({});
                    },
                })
            );
        assert.strictEqual(calls, 0);
    });
    test('owner review permits operational context but refuses private URLs and metadata', async function (assert) {
        for (const type of types) {
            const context = { ...preview(type).context, details: 'Synthetic' };
            const row = { id: uuid, reason: 'other', status: 'open', resolution: null, created_at: '2026-11-01', context };
            const page = { reports: [row], has_more: false, next_cursor: null };
            const result = await chatSafetyRequest({ ...options, action: 'reports', fetchImpl: async () => reply(page) });
            assert.strictEqual(result.reports[0].context.type, type);
            await assert.rejects(
                chatSafetyRequest({ ...options, action: 'reports', fetchImpl: async () => reply({ ...page, reports: [{ ...row, context: { ...context, file_url: 'private' } }] }) })
            );
        }
    });
    test('an explicit content report sends its fixed receipt and ID once, with no automatic retry', async function (assert) {
        const value = preview('issue'),
            input = {
                type: value.type,
                target: value.target,
                request_id: uuid,
                reason: 'other',
                details: 'Synthetic',
                share_context: true,
                preview_receipt: value.preview_receipt,
                policy_version: value.policy_version,
            };
        let calls = 0;
        await assert.rejects(
            chatSafetyRequest({
                ...options,
                action: 'contentReport',
                input,
                fetchImpl: async (url, request) => {
                    calls++;
                    assert.strictEqual(url, options.host + '/int/v1/contrust/content-safety/reports');
                    assert.deepEqual(JSON.parse(request.body), input);
                    return reply({ code: 'CONTENT_REPORT_CHANGED', private_body: 'must-not-surface' }, 409);
                },
            }),
            /Konten atau masa berlaku/
        );
        assert.strictEqual(calls, 1);
    });
});
