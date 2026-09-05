import { initializeAiModelTypeFields } from './ai-model-form';

const form = document.querySelector('[data-ai-model-create-form]');

if (form) {
    const fields = {
        name: form.querySelector('#name'),
        version: form.querySelector('#version'),
        modelId: form.querySelector('#model_id'),
        modelType: form.querySelector('#model_type'),
        apiUrl: form.querySelector('#api_url'),
        apiKey: form.querySelector('#api_key'),
    };
    const presetButtons = Array.from(form.querySelectorAll('[data-ai-model-preset]'));
    const syncMaxTokensVisibility = initializeAiModelTypeFields(form);

    const clearPresetSelection = () => {
        presetButtons.forEach((button) => {
            button.setAttribute('aria-pressed', 'false');
            button.classList.remove('border-blue-500', 'bg-blue-50', 'text-blue-700', 'ring-1', 'ring-blue-200');
            button.classList.add('border-gray-300', 'bg-white', 'text-gray-700');
        });
    };

    presetButtons.forEach((button) => {
        button.addEventListener('click', () => {
            clearPresetSelection();
            button.setAttribute('aria-pressed', 'true');
            button.classList.remove('border-gray-300', 'bg-white', 'text-gray-700');
            button.classList.add('border-blue-500', 'bg-blue-50', 'text-blue-700', 'ring-1', 'ring-blue-200');

            fields.name.value = button.dataset.presetName || '';
            fields.version.value = button.dataset.presetVersion || '';
            fields.modelId.value = button.dataset.presetModelId || '';
            fields.modelType.value = button.dataset.presetModelType || 'chat';
            fields.apiUrl.value = button.dataset.presetApiUrl || '';
            if (button.dataset.aiModelPreset === 'opencodex') {
                fields.apiKey.value = 'not-required';
            } else if (fields.apiKey.value === 'not-required') {
                fields.apiKey.value = '';
            }
            syncMaxTokensVisibility();
        });
    });

    [fields.name, fields.version, fields.modelId, fields.apiUrl].forEach((field) => {
        field?.addEventListener('input', clearPresetSelection);
    });

    fields.modelType?.addEventListener('change', () => {
        clearPresetSelection();
    });

    const apiKeyToggle = form.querySelector('[data-api-key-toggle]');
    apiKeyToggle?.addEventListener('click', () => {
        const revealing = fields.apiKey?.type === 'password';
        fields.apiKey.type = revealing ? 'text' : 'password';
        apiKeyToggle.setAttribute('aria-pressed', revealing ? 'true' : 'false');
        apiKeyToggle.textContent = revealing ? form.dataset.hideKeyLabel : form.dataset.showKeyLabel;
        fields.apiKey.focus({ preventScroll: true });
    });

    form.addEventListener('submit', () => {
        const button = form.querySelector('[data-model-submit]');
        const label = form.querySelector('[data-model-submit-label]');

        if (!button || !label) return;

        button.disabled = true;
        label.textContent = form.dataset.submittingLabel || label.textContent;
    });

}
