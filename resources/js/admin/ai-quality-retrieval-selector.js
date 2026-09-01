const retrievalModes = ['atomic_first', 'chunk', 'knowledge_broad'];

export const combineRetrievalReadiness = (selectedIds, readinessByKnowledgeBase, labels = {}) => {
    const ids = [...new Set(selectedIds.map(String).filter(Boolean))];
    const emptySelectionLabel = labels.emptySelection || 'Select at least one knowledge base';
    const unavailableLabel = labels.unavailable || 'This method is unavailable';
    const result = Object.fromEntries(retrievalModes.map((mode) => [mode, {
        available: ids.length > 0,
        blockers: ids.length > 0 ? [] : [emptySelectionLabel],
    }]));

    ids.forEach((id) => {
        const knowledgeBase = readinessByKnowledgeBase?.[id];
        retrievalModes.forEach((mode) => {
            const state = knowledgeBase?.modes?.[mode];
            if (state?.available) return;

            result[mode].available = false;
            const messages = Array.isArray(state?.blockers) && state.blockers.length > 0
                ? state.blockers.map((blocker) => blocker?.message).filter(Boolean)
                : [unavailableLabel];
            messages.forEach((message) => {
                const name = String(knowledgeBase?.name || '').trim();
                result[mode].blockers.push(name ? name + '：' + message : String(message));
            });
        });
    });

    retrievalModes.forEach((mode) => {
        result[mode].blockers = [...new Set(result[mode].blockers)];
    });

    return result;
};

export const chooseRetrievalMode = (current, readiness, touched) => {
    if (current && readiness?.[current]?.available) return current;
    if (touched) return '';

    return retrievalModes.find((mode) => readiness?.[mode]?.available) || '';
};

const parseMap = (root) => {
    try {
        return JSON.parse(root.querySelector('[data-retrieval-readiness-map]')?.textContent || '{}');
    } catch {
        return {};
    }
};

const selectedKnowledgeBaseIds = (root) => {
    const selector = root.dataset.knowledgeInputSelector;
    if (selector) {
        return [...document.querySelectorAll(selector)]
            .filter((input) => input.checked)
            .map((input) => input.value);
    }

    return String(root.dataset.selectedKnowledgeBaseIds || '').split(',').filter(Boolean);
};

const helpPanelFor = (root, trigger) => {
    const panelId = trigger.getAttribute('aria-controls');
    if (!panelId) return null;

    const panel = document.getElementById(panelId);

    return panel && root.contains(panel) ? panel : null;
};

const closeHelpPopovers = (root, except = null, restoreFocus = false) => {
    root.querySelectorAll('[data-retrieval-mode-help-trigger][aria-expanded="true"]').forEach((trigger) => {
        if (trigger === except) return;

        const panel = helpPanelFor(root, trigger);
        trigger.setAttribute('aria-expanded', 'false');
        if (panel) panel.hidden = true;
        if (restoreFocus) trigger.focus();
    });
};

const initializeHelpPopovers = (root) => {
    root.querySelectorAll('[data-retrieval-mode-help-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            const panel = helpPanelFor(root, trigger);
            if (!panel) return;

            const willOpen = trigger.getAttribute('aria-expanded') !== 'true';
            closeHelpPopovers(root, trigger);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            panel.hidden = !willOpen;
        });
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        closeHelpPopovers(root, null, true);
    });
    document.addEventListener('click', (event) => {
        if (event.target instanceof Node && root.contains(event.target) && event.target.closest?.('[data-retrieval-mode-help]')) {
            return;
        }

        closeHelpPopovers(root);
    });
};

const renderSelector = (root, readiness, selectedValue) => {
    const readonly = root.dataset.readonly === 'true';
    root.querySelectorAll('[data-retrieval-mode-card]').forEach((card) => {
        const mode = card.dataset.mode;
        const input = card.querySelector('[data-retrieval-mode-input]');
        if (!input) return;
        if (!mode) {
            input.disabled = readonly;
            input.checked = selectedValue === '';
            return;
        }

        const available = Boolean(readiness?.[mode]?.available);
        input.disabled = readonly || !available;
        input.checked = selectedValue === mode;
        card.classList.toggle('bg-white', available);
        card.classList.toggle('bg-gray-50', !available);
        card.classList.toggle('hover:border-blue-300', available);
        const choice = card.querySelector('[data-retrieval-mode-choice]');
        if (choice) {
            choice.classList.toggle('cursor-pointer', !input.disabled);
            choice.classList.toggle('cursor-default', readonly && available);
            choice.classList.toggle('cursor-not-allowed', !available);
            choice.classList.toggle('active:scale-[.99]', !input.disabled);
        }
        const title = card.querySelector('[data-retrieval-mode-title]');
        if (title) {
            title.classList.toggle('text-gray-900', available);
            title.classList.toggle('text-gray-600', !available);
        }
        const status = card.querySelector('[data-retrieval-mode-status]');
        if (status) {
            status.textContent = available
                ? status.dataset.availableLabel
                : status.dataset.unavailableLabel;
            status.classList.toggle('text-emerald-700', available);
            status.classList.toggle('text-gray-500', !available);
        }
        const blockerWrapper = card.querySelector('[data-retrieval-mode-blockers-wrapper]');
        const blockerText = card.querySelector('[data-retrieval-mode-blockers]');
        const blockers = readiness?.[mode]?.blockers || [];
        if (blockerWrapper) blockerWrapper.hidden = available;
        if (blockerText) blockerText.textContent = blockers.join('；') || root.dataset.unavailableLabel;
    });
};

export const initializeAiQualityRetrievalSelector = (root) => {
    if (!root || root.dataset.retrievalSelectorReady === 'true') return;
    root.dataset.retrievalSelectorReady = 'true';
    const readinessMap = parseMap(root);
    const allowInherit = root.dataset.allowInherit === 'true';
    let touched = root.dataset.persisted === 'true';

    initializeHelpPopovers(root);

    const refresh = () => {
        const readiness = combineRetrievalReadiness(selectedKnowledgeBaseIds(root), readinessMap, {
            emptySelection: root.dataset.emptySelectionLabel,
            unavailable: root.dataset.unavailableLabel,
        });
        const current = root.querySelector('[data-retrieval-mode-input]:checked')?.value || '';
        const selectedValue = allowInherit && current === ''
            ? ''
            : chooseRetrievalMode(current, readiness, touched);
        const selectionInvalid = !allowInherit && touched && selectedValue === '';
        root.dataset.selectionInvalid = selectionInvalid ? 'true' : 'false';
        renderSelector(root, readiness, selectedValue);
        const live = root.querySelector('[data-retrieval-mode-live]');
        if (live) {
            const status = selectedValue
                ? root.querySelector('[data-mode="' + selectedValue + '"] [data-retrieval-mode-status]')
                : null;
            live.textContent = selectionInvalid
                ? root.dataset.selectionUnavailableLabel || root.dataset.unavailableLabel
                : selectedValue && readiness[selectedValue]?.available
                    ? status?.dataset.availableLabel || ''
                    : '';
            live.classList.toggle('text-red-600', selectionInvalid);
        }
    };

    root.querySelectorAll('[data-retrieval-mode-input]').forEach((input) => {
        input.addEventListener('change', () => {
            touched = true;
            const touchedInput = root.querySelector('[data-retrieval-mode-touched]');
            if (touchedInput) touchedInput.value = '1';
            refresh();
        });
    });
    const selector = root.dataset.knowledgeInputSelector;
    if (selector) {
        document.querySelectorAll(selector).forEach((input) => input.addEventListener('change', () => {
            if (touched) {
                const touchedInput = root.querySelector('[data-retrieval-mode-touched]');
                if (touchedInput) touchedInput.value = '1';
            }
            refresh();
        }));
    }
    const form = root.closest('form');
    if (form && root.dataset.submitGuardReady !== 'true') {
        root.dataset.submitGuardReady = 'true';
        form.addEventListener('submit', (event) => {
            refresh();
            if (root.dataset.selectionInvalid !== 'true') return;

            event.preventDefault();
            root.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        });
    }
    refresh();
};

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-ai-quality-retrieval-selector]')
        .forEach(initializeAiQualityRetrievalSelector);
}
