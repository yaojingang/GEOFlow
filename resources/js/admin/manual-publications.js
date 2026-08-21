document.addEventListener('click', async (event) => {
    const origin = event.target;
    if (!(origin instanceof Element)) {
        return;
    }

    const button = origin.closest('[data-copy-target]');
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const targetId = button.dataset.copyTarget;
    const target = targetId ? document.getElementById(targetId) : null;
    if (!target) {
        return;
    }

    const content = target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement
        ? target.value
        : target.textContent ?? '';
    const label = button.querySelector('span');
    const originalLabel = label?.textContent ?? '';

    try {
        await navigator.clipboard.writeText(content);
        if (label && button.dataset.successLabel) {
            label.textContent = button.dataset.successLabel;
            window.setTimeout(() => {
                label.textContent = originalLabel;
            }, 1600);
        }
    } catch {
        if (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement) {
            target.select();
        }
    }
});
