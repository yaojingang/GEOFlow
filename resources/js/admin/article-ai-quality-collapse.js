export const ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY = 'geoflow.admin.article-ai-quality.collapsed';

function readCollapsedPreference(windowRef) {
    try {
        return windowRef?.localStorage?.getItem(ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function persistCollapsedPreference(windowRef, collapsed) {
    try {
        windowRef?.localStorage?.setItem(ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY, collapsed ? '1' : '0');
    } catch {
        // The panel remains collapsible when browser storage is unavailable.
    }
}

export function setupArticleAiQualityCollapse({
    documentRef = document,
    windowRef = window,
} = {}) {
    const root = documentRef.querySelector('[data-ai-quality-collapsible]');
    if (!root) return null;

    const header = root.querySelector('[data-ai-quality-collapse-header]');
    const body = root.querySelector('[data-ai-quality-collapse-body]');
    const expandedCopy = root.querySelector('[data-ai-quality-expanded-copy]');
    const compactSummary = root.querySelector('[data-ai-quality-compact-summary]');
    const toggle = root.querySelector('[data-ai-quality-collapse-toggle]');
    const label = root.querySelector('[data-ai-quality-collapse-label]');
    const icon = root.querySelector('[data-ai-quality-collapse-icon]');
    const optimizationOpen = root.querySelector('[data-ai-optimization-open]');
    if (!header || !body || !expandedCopy || !compactSummary || !toggle || !label || !icon) return null;

    const state = {
        collapsed: false,
    };

    const applyCollapsedState = (collapsed, persist = true) => {
        state.collapsed = collapsed;
        const actionLabel = collapsed ? toggle.dataset.expandLabel : toggle.dataset.collapseLabel;

        root.dataset.collapsed = String(collapsed);
        body.hidden = collapsed;
        expandedCopy.hidden = collapsed;
        compactSummary.hidden = !collapsed;
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', actionLabel);
        toggle.title = actionLabel;
        label.textContent = actionLabel;
        icon.classList.toggle('rotate-180', collapsed);

        header.classList.toggle('border-b', !collapsed);
        header.classList.toggle('px-6', !collapsed);
        header.classList.toggle('py-5', !collapsed);
        header.classList.toggle('px-4', collapsed);
        header.classList.toggle('py-3', collapsed);

        if (persist) persistCollapsedPreference(windowRef, collapsed);
    };

    applyCollapsedState(readCollapsedPreference(windowRef), false);
    toggle.addEventListener('click', () => applyCollapsedState(!state.collapsed));
    optimizationOpen?.addEventListener('click', () => {
        if (state.collapsed) applyCollapsedState(false);
    });

    return {
        get collapsed() {
            return state.collapsed;
        },
        setCollapsed: applyCollapsedState,
    };
}

if (typeof document !== 'undefined') setupArticleAiQualityCollapse();
