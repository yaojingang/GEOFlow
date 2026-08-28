function announceProgressUnavailable(root) {
    if (!root) return;

    root.setAttribute('aria-busy', 'false');
    const errorElement = root.querySelector('[data-generation-error]');
    if (!errorElement) return;

    errorElement.textContent = root.dataset.loadUnavailable || root.dataset.pollUnavailable || '';
    errorElement.classList.toggle('hidden', false);
}

export async function loadTitleGenerationProgress(root, loader) {
    try {
        const module = await loader();
        if (typeof module.initializeTitleGenerationProgress !== 'function') {
            throw new Error('Title generation progress initializer is unavailable.');
        }

        module.initializeTitleGenerationProgress(root);

        return true;
    } catch {
        announceProgressUnavailable(root);

        return false;
    }
}
