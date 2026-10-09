// Display positions are always 1-based. Stored sort_order/priority values can
// contain gaps (0,1,2 or 10,20,30); changing their scale is unnecessary and risky.
export const byOrder = (field = 'sort_order') => (a, b) =>
    Number(a[field] ?? 0) - Number(b[field] ?? 0)
    || Number(a.id ?? 0) - Number(b.id ?? 0);

export const bySourcePriority = (a, b) =>
    Number(a.priority ?? 0) - Number(b.priority ?? 0)
    || Number(a.cost_idr ?? 0) - Number(b.cost_idr ?? 0)
    || Number(a.id ?? 0) - Number(b.id ?? 0);

export function displayPosition(items, row, compare = byOrder()) {
    if (!row || !items) return null;
    const ordered = [...items].sort(compare);
    const index = ordered.findIndex((item) => String(item.id) === String(row.id));
    return index === -1 ? null : index + 1;
}
