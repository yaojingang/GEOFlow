function iconPlaceholders(root) {
    if (!root) return [];
    const placeholders = typeof root.matches === 'function' && root.matches('i[data-lucide]') ? [root] : [];
    if (typeof root.querySelectorAll === 'function') {
        placeholders.push(...root.querySelectorAll('i[data-lucide]'));
    }

    return placeholders;
}

const stabilizedRuntimes = new WeakSet();

export function stabilizeLucideRuntime(lucide, documentRoot = globalThis.document) {
    if (!lucide || typeof lucide.createIcons !== 'function' || stabilizedRuntimes.has(lucide)) return;

    const createIcons = lucide.createIcons;
    lucide.createIcons = function stableCreateIcons(...args) {
        const result = createIcons.apply(this, args);
        documentRoot?.querySelectorAll?.('svg[data-lucide]').forEach((icon) => icon.removeAttribute('data-lucide'));

        return result;
    };
    stabilizedRuntimes.add(lucide);
}

export function refreshIconPlaceholders(root, lucide, documentRoot = globalThis.document) {
    const pending = iconPlaceholders(root);
    if (pending.length === 0 || typeof lucide?.createIcons !== 'function') return 0;

    const pendingSet = new Set(pending);
    const deferred = [...(documentRoot?.querySelectorAll?.('i[data-lucide]') ?? [])]
        .filter((icon) => !pendingSet.has(icon))
        .map((icon) => ({ icon, name: icon.getAttribute('data-lucide') }))
        .filter(({ name }) => name);

    deferred.forEach(({ icon, name }) => {
        icon.removeAttribute('data-lucide');
        icon.setAttribute('data-gf-lucide-pending', name);
    });
    try {
        lucide.createIcons();
    } finally {
        deferred.forEach(({ icon, name }) => {
            icon.setAttribute('data-lucide', name);
            icon.removeAttribute('data-gf-lucide-pending');
        });
    }
    documentRoot?.querySelectorAll?.('svg[data-lucide]').forEach((icon) => icon.removeAttribute('data-lucide'));

    return pending.length;
}
