/** Retain live socket entries while prepending history, keyed by type and stable record id. */
export function mergeChatFeed(current = [], incoming = []) {
    const entries = new Map();
    for (const item of [...incoming, ...(current ?? [])]) {
        if (item?.record?.id) entries.set(`${item.type}:${item.record.id}`, item);
    }
    return [...entries.values()].sort((a, b) => {
        const time = (Date.parse(a.created_at) || 0) - (Date.parse(b.created_at) || 0);
        return time || a.type.localeCompare(b.type) || a.record.id.localeCompare(b.record.id);
    });
}
