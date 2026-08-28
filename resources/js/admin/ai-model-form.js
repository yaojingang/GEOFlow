export const initializeAiModelTypeFields = (form) => {
    if (!form) return () => {};

    const modelType = form.querySelector('[name="model_type"]');
    const maxTokensField = form.querySelector('[data-max-tokens-field]');
    const maxTokens = form.querySelector('[name="max_tokens"]');
    const supportsMaxTokens = form.dataset.supportsMaxTokens === 'true';

    const sync = () => {
        if (!maxTokensField || !maxTokens) return;

        const visible = supportsMaxTokens && modelType?.value === 'chat';
        maxTokensField.classList.toggle('hidden', !visible);
        maxTokens.disabled = !visible;

        if (!visible) maxTokens.value = '';
    };

    modelType?.addEventListener('change', sync);
    sync();

    return sync;
};
