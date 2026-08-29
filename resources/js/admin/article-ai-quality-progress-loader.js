function announceProgressUnavailable(root) {
    if (!root) return;

    root.setAttribute('aria-busy', 'false');
    const errorElement = root.querySelector('[data-ai-quality-progress-error]');
    if (!errorElement) return;

    errorElement.textContent = root.dataset.loadUnavailable || root.dataset.pollUnavailable || '';
    errorElement.classList.toggle('hidden', false);
}

export async function loadArticleAiQualityProgress(root, loader) {
    try {
        const module = await loader();
        if (typeof module.initializeArticleAiQualityProgress !== 'function') {
            throw new Error('Article AI quality progress initializer is unavailable.');
        }

        module.initializeArticleAiQualityProgress(root);

        return true;
    } catch {
        announceProgressUnavailable(root);

        return false;
    }
}
