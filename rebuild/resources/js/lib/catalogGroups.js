// Group headings are independent of individual nominal order. An empty name is Umum.
export const normalizePackageGroup = value => {
    const name = String(value ?? '').trim();
    return name === '' ? 'Umum' : name;
};

export const orderedPackageGroups = (packages = [], preferred = [], registry = []) => {
    const grouped = new Map();
    for (const item of packages || []) {
        const name = normalizePackageGroup(item.group_name);
        if (!grouped.has(name)) grouped.set(name, []);
        grouped.get(name).push(item);
    }
    const names = (registry || []).length ? registry.map(group => group.name) : preferred;
    const priority = new Map((names || []).map((name, index) => [normalizePackageGroup(name), index]));
    const metadata = new Map((registry || []).map(group => [normalizePackageGroup(group.name), group]));
    const score = name => priority.has(name) ? priority.get(name) : Number.MAX_SAFE_INTEGER;
    // JS stable sort preserves existing group order for groups not yet configured.
    return [...grouped.entries()]
        .map(([name, items]) => ({ name, items, image_url: metadata.get(name)?.image_url || '' }))
        .sort((a, b) => score(a.name) - score(b.name));
};

export const movePackageGroup = (names, index, offset) => {
    const nextIndex = index + offset;
    if (index < 0 || nextIndex < 0 || nextIndex >= names.length) return [...names];
    const result = [...names];
    [result[index], result[nextIndex]] = [result[nextIndex], result[index]];
    return result;
};
