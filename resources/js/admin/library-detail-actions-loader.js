function lockDestructiveButton(button) {
    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');
}

export function markLibraryDetailActionsUnavailable(root) {
    if (!root) return;

    root.querySelectorAll('[data-library-detail-destructive-submit]').forEach(lockDestructiveButton);
}

export async function loadLibraryDetailActions(root, loader) {
    markLibraryDetailActionsUnavailable(root);

    try {
        const module = await loader();
        if (typeof module.initializeLibraryDetailActions !== 'function') {
            throw new Error('Library detail actions initializer is unavailable.');
        }

        module.initializeLibraryDetailActions(root);

        return true;
    } catch {
        markLibraryDetailActionsUnavailable(root);

        return false;
    }
}
