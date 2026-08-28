function lockButton(button) {
    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');
}

export function markAiSourceProvidersIndexUnavailable(root) {
    if (!root) return;

    root.querySelectorAll('[data-connection-test-button]').forEach(lockButton);
    root.querySelectorAll('[data-provider-delete-submit]').forEach(lockButton);

    const message = root.dataset.testInitializationError || root.dataset.testNetworkError || '';
    root.querySelectorAll('[data-connection-test-result]').forEach((resultElement) => {
        resultElement.textContent = message;
        resultElement.className = 'mt-2 break-all text-xs text-red-700';
    });
}

export async function loadAiSourceProvidersIndex(root, loader) {
    try {
        await loader();

        return true;
    } catch {
        markAiSourceProvidersIndexUnavailable(root);

        return false;
    }
}
