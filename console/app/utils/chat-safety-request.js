// Same bounded transport contract as Contrust Navigator; no SDK retry or mutable bearer.
const actions = {
    status: ['GET', ''],
    consent: ['POST', '/consent'],
    block: ['POST', '/blocks'],
    unblock: ['DELETE', '/blocks'],
    report: ['POST', '/reports'],
    reports: ['GET', '/reports'],
    review: ['PATCH', '/reports'],
};
const id = (value) => typeof value === 'string' && /^[A-Za-z0-9_-]{1,150}$/.test(value);
const uuid = (value) => typeof value === 'string' && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(value);
const reasons = ['harassment', 'threat', 'illegal', 'privacy', 'spam', 'other'];
const exact = (value, fields) => value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).every((key) => fields.includes(key));
const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
const messages = {
    CHAT_SAFETY_UNAVAILABLE: 'Keamanan chat belum dapat diperiksa. Coba lagi.',
    CHAT_SAFETY_SESSION: 'Sesi chat berubah atau berakhir. Buka ulang halaman chat.',
    CHAT_POLICY_REQUIRED: 'Baca dan setujui syarat komunikasi terbaru sebelum mengirim.',
    CHAT_BLOCKED: 'Pesan langsung atau undangan tidak tersedia untuk peserta ini.',
    CHAT_REPORT_CONFLICT: 'Formulir laporan berubah. Periksa laporan sebelumnya sebelum membuat yang baru.',
};

export function chatSafetyError(code = 'CHAT_SAFETY_UNAVAILABLE') {
    const safeCode = own(messages, code) ? code : 'CHAT_SAFETY_UNAVAILABLE';
    return Object.assign(new Error(messages[safeCode]), { code: safeCode });
}

function validInput(action, input) {
    if (action === 'status') return exact(input, ['blocked_after']) && (input.blocked_after === undefined || id(input.blocked_after));
    if (action === 'reports') return exact(input, ['before']) && (input.before === undefined || uuid(input.before));
    if (action === 'review')
        return (
            exact(input, ['id', 'status', 'resolution']) &&
            uuid(input.id) &&
            ['reviewing', 'resolved', 'dismissed'].includes(input.status) &&
            ['no_violation', 'content_addressed', 'access_addressed', 'support_followup'].includes(input.resolution)
        );
    if (action === 'consent')
        return exact(input, ['policy_version', 'accepted']) && typeof input.policy_version === 'string' && /^\d{4}-\d{2}-\d{2}\.\d+$/.test(input.policy_version) && input.accepted === true;
    if (action === 'block' || action === 'unblock') return exact(input, ['user']) && id(input.user);
    return (
        exact(input, ['request_id', 'type', 'target', 'reason', 'details', 'share_context']) &&
        uuid(input.request_id) &&
        ['message', 'user'].includes(input.type) &&
        id(input.target) &&
        reasons.includes(input.reason) &&
        (input.details === undefined || (typeof input.details === 'string' && Array.from(input.details).length <= 2000)) &&
        input.share_context === true
    );
}

export const validChatSafetyReport = (input) => validInput('report', input);

function validStatus(result) {
    if (!result || typeof result.enabled !== 'boolean') return false;
    if (!result.enabled) return Object.keys(result).length === 1;
    return (
        typeof result.policy_version === 'string' &&
        /^\d{4}-\d{2}-\d{2}\.\d+$/.test(result.policy_version) &&
        result.policy_url === 'https://contrust.website/terms' &&
        typeof result.consented === 'boolean' &&
        typeof result.moderator === 'boolean' &&
        Array.isArray(result.blocked_users) &&
        result.blocked_users.length <= 100 &&
        result.blocked_users.every((person) => id(person?.id) && typeof person.name === 'string') &&
        typeof result.blocked_has_more === 'boolean' &&
        (result.blocked_has_more ? id(result.blocked_next_cursor) : result.blocked_next_cursor === null) &&
        result.block_scope === 'direct_messages_and_new_invitations' &&
        result.shared_groups_remain_visible === true
    );
}

function validReports(value) {
    return (
        value &&
        Array.isArray(value.reports) &&
        value.reports.length <= 25 &&
        typeof value.has_more === 'boolean' &&
        (value.has_more ? uuid(value.next_cursor) : value.next_cursor === null) &&
        value.reports.every(
            (row) =>
                uuid(row?.id) &&
                reasons.includes(row.reason) &&
                ['open', 'reviewing', 'resolved', 'dismissed'].includes(row.status) &&
                (row.resolution === null || ['no_violation', 'content_addressed', 'access_addressed', 'support_followup'].includes(row.resolution)) &&
                typeof row.created_at === 'string' &&
                row.context &&
                ['user', 'message'].includes(row.context.type) &&
                row.context.target &&
                (row.context.target.id === null || id(row.context.target.id)) &&
                typeof row.context.target.name === 'string' &&
                typeof row.context.details === 'string' &&
                Array.from(row.context.details).length <= 2000 &&
                (row.context.message === null || (id(row.context.message?.id) && typeof row.context.message.content === 'string'))
        )
    );
}

/** Captured session bearer, fixed routes, finite wait and no automatic write retry. */
export async function chatSafetyRequest({ host, sessionToken, action, input = {}, fetchImpl = fetch, timeoutMs = 12000, signal }) {
    if (
        typeof host !== 'string' ||
        !/^https:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?\/?$/.test(host) ||
        typeof sessionToken !== 'string' ||
        !/^[1-9][0-9]{0,17}\|[A-Za-z0-9]{20,}$/.test(sessionToken) ||
        !own(actions, action) ||
        !validInput(action, input) ||
        !Number.isFinite(timeoutMs) ||
        timeoutMs < 1 ||
        timeoutMs > 30000
    )
        throw chatSafetyError();
    if (signal?.aborted) throw chatSafetyError('CHAT_SAFETY_SESSION');
    const [method, path] = actions[action];
    const controller = new AbortController();
    let rejectDeadline;
    const deadline = new Promise((_, reject) => {
        rejectDeadline = reject;
    });
    const bounded = (promise) => Promise.race([promise, deadline]);
    const abort = () => {
        controller.abort();
        rejectDeadline(chatSafetyError());
    };
    const cancelSession = () => {
        controller.abort();
        rejectDeadline(chatSafetyError('CHAT_SAFETY_SESSION'));
    };
    signal?.addEventListener('abort', cancelSession, { once: true });
    const timer = setTimeout(abort, timeoutMs);
    try {
        const query =
            action === 'status' && input.blocked_after
                ? `?blocked_after=${encodeURIComponent(input.blocked_after)}`
                : action === 'reports' && input.before
                  ? `?before=${encodeURIComponent(input.before)}`
                  : '';
        const route = action === 'review' ? `${path}/${input.id}` : path;
        const payload = action === 'review' ? { status: input.status, resolution: input.resolution } : input;
        const response = await bounded(
            fetchImpl(`${host.replace(/\/$/, '')}/int/v1/contrust/chat-safety${route}${query}`, {
                method,
                signal: controller.signal,
                redirect: 'error',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Cache-Control': 'no-store', Authorization: `Bearer ${sessionToken}` },
                ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
            })
        );
        if (controller.signal.aborted) throw chatSafetyError();
        if (response.status === 401) throw chatSafetyError('CHAT_SAFETY_SESSION');
        if (!response.ok) {
            // Error bodies are untrusted and may contain PII or request secrets.
            // Only fixed status/code combinations may reach the user.
            let body;
            try {
                body = await bounded(response.json());
            } catch {}
            const code = body?.code;
            if (
                (response.status === 428 && code === 'CHAT_POLICY_REQUIRED') ||
                (response.status === 403 && code === 'CHAT_BLOCKED') ||
                (response.status === 409 && code === 'CHAT_REPORT_CONFLICT')
            )
                throw chatSafetyError(code);
            throw chatSafetyError();
        }
        const result = await bounded(response.json());
        const valid =
            action === 'status'
                ? validStatus(result)
                : action === 'reports'
                  ? validReports(result)
                  : action === 'review'
                    ? result?.id === input.id && result.status === input.status
                    : action === 'consent'
                      ? result?.consented === true && result.policy_version === input.policy_version
                      : action === 'report'
                        ? uuid(result?.id) && ['open', 'reviewing', 'resolved', 'dismissed'].includes(result.status)
                        : result?.user === input.user && result.blocked === (action === 'block');
        if (!valid || controller.signal.aborted) throw chatSafetyError();
        return result;
    } catch (error) {
        if (signal?.aborted) throw chatSafetyError('CHAT_SAFETY_SESSION');
        if (error && own(messages, error.code) && error.message === messages[error.code]) throw error;
        throw chatSafetyError();
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', cancelSession);
    }
}
