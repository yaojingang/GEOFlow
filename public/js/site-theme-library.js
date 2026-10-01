(() => {
    const root = document.querySelector('[data-theme-library]');
    if (!root) return;
    const toggle = root.querySelector('[data-theme-batch-toggle]');
    const panel = root.querySelector('[data-theme-batch-panel]');
    const choices = [...root.querySelectorAll('[data-theme-select]')];
    const confirmation = root.querySelector('[data-theme-batch-confirm]');
    let managing = false;

    const updateSelection = () => {
        if (!panel) return;
        const count = choices.filter(choice => choice.checked && !choice.disabled).length;
        root.querySelector('[data-theme-selected-count]').textContent = root.dataset.selectedLabel.replace(':count', String(count));
        root.querySelector('[data-theme-batch-review]').disabled = count === 0;
        const visible = choices.filter(choice => !choice.disabled && choice.getClientRects().length);
        const selectPage = root.querySelector('[data-theme-select-page]');
        selectPage.checked = visible.length > 0 && visible.every(choice => choice.checked);
        selectPage.indeterminate = visible.some(choice => choice.checked) && !selectPage.checked;
        confirmation.hidden = true;
    };

    toggle?.addEventListener('click', () => {
        managing = !managing;
        toggle.textContent = managing ? toggle.dataset.endLabel : toggle.dataset.startLabel;
        toggle.setAttribute('aria-pressed', String(managing));
        panel.hidden = !managing;
        root.querySelectorAll('[data-theme-batch-choice]').forEach(element => { element.hidden = !managing; });
        if (!managing) choices.forEach(choice => { choice.checked = false; });
        updateSelection();
    });
    choices.forEach(choice => choice.addEventListener('change', updateSelection));
    root.querySelector('[data-theme-select-page]')?.addEventListener('change', event => {
        choices.filter(choice => !choice.disabled && choice.getClientRects().length).forEach(choice => { choice.checked = event.target.checked; });
        updateSelection();
    });
    root.querySelector('[data-theme-batch-review]')?.addEventListener('click', () => {
        const count = choices.filter(choice => choice.checked && !choice.disabled).length;
        if (!count) return;
        const action = panel.elements.library_action.value;
        root.querySelector('[data-theme-batch-confirm-text]').textContent = root.dataset[action + 'Confirm'].replace(':count', String(count));
        confirmation.hidden = false;
    });
    root.querySelector('[data-theme-batch-cancel]')?.addEventListener('click', () => { confirmation.hidden = true; });
    panel?.addEventListener('submit', event => {
        if (confirmation.hidden || !choices.some(choice => choice.checked && !choice.disabled)) event.preventDefault();
    });
    root.querySelectorAll('[data-theme-cancel-activation]').forEach(button => button.addEventListener('click', () => { button.closest('[data-theme-activation]').open = false; }));
})();
