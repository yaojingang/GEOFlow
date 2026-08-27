export function updaterReloadDelay(value) {
    const delay = Number.parseInt(String(value), 10);

    return Number.isInteger(delay) && delay >= 1000 && delay <= 60000 ? delay : null;
}

export function initializeSystemUpdaterAutoReload(
    root = document,
    schedule = window.setTimeout.bind(window),
    reload = () => window.location.reload(),
) {
    const element = root.querySelector('[data-system-updater-auto-reload]');
    const delay = updaterReloadDelay(element?.dataset.systemUpdaterAutoReload);
    if (delay === null) {
        return null;
    }

    return schedule(reload, delay);
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializeSystemUpdaterAutoReload();
}
