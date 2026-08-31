function lockButton(button) {
    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');
}

export function markAiModelsIndexUnavailable(root) {
    if (!root) return;

    root.querySelectorAll('[data-ai-model-test-button]').forEach(lockButton);
    const message = root.dataset.testInitializationError || '';
    root.querySelectorAll('[data-ai-model-test-status]').forEach((status) => {
        status.hidden = false;
        status.classList.remove('hidden');
        status.textContent = message;
    });
}

export async function loadAiModelsIndex(root, loader) {
    try {
        const module = await loader();
        module.initializeAiModelsIndex(root);

        return true;
    } catch {
        markAiModelsIndexUnavailable(root);

        return false;
    }
}
