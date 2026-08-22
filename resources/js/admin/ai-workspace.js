function initializeAiWorkspace() {
    const workspace = document.querySelector('[data-ai-workspace]');
    if (!workspace) return;

    const start = workspace.querySelector('[data-ai-start]');
    const conversation = workspace.querySelector('[data-ai-conversation]');
    const form = workspace.querySelector('[data-ai-form]');
    const input = workspace.querySelector('[data-ai-input]');
    const userMessage = workspace.querySelector('[data-ai-user-message]');
    const result = workspace.querySelector('[data-ai-result]');
    const confirmButton = workspace.querySelector('[data-ai-confirm]');
    const autoConfirm = workspace.querySelector('[data-ai-auto-confirm]');
    const stages = [...workspace.querySelectorAll('[data-ai-stage]')];
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const timers = [];
    const confirmButtonContent = confirmButton?.innerHTML ?? '';
    const maxPromptLength = Number(input?.maxLength) > 0 ? Number(input.maxLength) : 4000;
    const scrollContainer = workspace.closest('.gf-main') ?? document.scrollingElement;

    const scrollToTop = () => {
        scrollContainer?.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    };

    const resetConfirmation = () => {
        if (!confirmButton) return;
        confirmButton.disabled = false;
        confirmButton.classList.remove('is-confirmed');
        confirmButton.innerHTML = confirmButtonContent;
        window.lucide?.createIcons?.();
    };

    const confirmResult = () => {
        if (!confirmButton || confirmButton.disabled) return;
        confirmButton.disabled = true;
        confirmButton.classList.add('is-confirmed');
        confirmButton.textContent = workspace.dataset.confirmed;
    };

    const clearTimers = () => {
        while (timers.length > 0) window.clearTimeout(timers.pop());
    };

    const resetStages = () => {
        clearTimers();
        result.classList.remove('is-visible');
        result.hidden = true;
        resetConfirmation();
        stages.forEach((stage) => {
            stage.classList.remove('is-active', 'is-complete');
            stage.querySelector('[data-ai-stage-status]').textContent = workspace.dataset.statusPending;
        });
    };

    const completeStage = (stage) => {
        stage.classList.remove('is-active');
        stage.classList.add('is-complete');
        stage.querySelector('[data-ai-stage-status]').textContent = workspace.dataset.statusCompleted;
    };

    const runStages = () => {
        resetStages();
        const interval = reduceMotion ? 80 : 900;
        stages.forEach((stage, index) => {
            timers.push(window.setTimeout(() => {
                if (index > 0) completeStage(stages[index - 1]);
                stage.classList.add('is-active');
                stage.querySelector('[data-ai-stage-status]').textContent = workspace.dataset.statusRunning;
            }, index * interval));
        });
        timers.push(window.setTimeout(() => {
            completeStage(stages[stages.length - 1]);
            result.hidden = false;
            window.requestAnimationFrame(() => {
                result.classList.add('is-visible');
                result.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'nearest' });
            });
            if (autoConfirm?.checked && result.dataset.requiresConfirmation !== 'true') {
                timers.push(window.setTimeout(() => {
                    if (autoConfirm.checked) confirmResult();
                }, reduceMotion ? 0 : 700));
            }
        }, stages.length * interval));
    };

    const submitPrompt = (prompt) => {
        const cleanPrompt = prompt.trim().slice(0, maxPromptLength);
        if (!cleanPrompt) {
            input?.focus();
            return;
        }
        userMessage.textContent = cleanPrompt;
        start.hidden = true;
        conversation.hidden = false;
        runStages();
        scrollToTop();
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitPrompt(input.value);
    });

    workspace.querySelectorAll('[data-ai-suggestion]').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.aiSuggestion;
            input.focus();
        });
    });

    workspace.querySelector('[data-ai-adjust]')?.addEventListener('click', () => {
        clearTimers();
        conversation.hidden = true;
        start.hidden = false;
        scrollToTop();
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    });

    confirmButton?.addEventListener('click', confirmResult);

    workspace.querySelector('[data-ai-followup-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const followup = event.currentTarget.querySelector('textarea');
        if (!followup.value.trim()) return;
        input.value = followup.value.trim().slice(0, maxPromptLength);
        followup.value = '';
        submitPrompt(input.value);
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeAiWorkspace);
else initializeAiWorkspace();
