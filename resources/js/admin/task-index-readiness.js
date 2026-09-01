const toCount = (value) => Math.max(0, Number.parseInt(String(value ?? 0), 10) || 0);

function readJson(root, selector) {
    const source = root.querySelector(selector);
    if (!source) return null;

    try {
        return JSON.parse(source.textContent || 'null');
    } catch {
        return null;
    }
}

export function normalizeTaskIndexReadiness(report) {
    return {
        status: report?.status === 'warning' ? 'warning' : 'blocked',
        summary: String(report?.summary || ''),
        recommendation: String(report?.recommendation || ''),
        stats: {
            remaining: toCount(report?.task?.remaining),
            total: toCount(report?.library?.total),
            used: toCount(report?.library?.used),
            available: toCount(report?.library?.available),
        },
        issues: Array.isArray(report?.issues) ? report.issues : [],
        editUrl: typeof report?.edit_url === 'string' ? report.edit_url : '',
        manageUrl: typeof report?.manage_url === 'string' ? report.manage_url : '',
    };
}

export function initializeTaskIndexReadiness(root = document, windowRef = window) {
    const dialog = root.querySelector('[data-task-index-readiness-dialog]');
    if (!dialog) return null;

    const title = dialog.querySelector('[data-task-index-readiness-title]');
    const summary = dialog.querySelector('[data-task-index-readiness-summary]');
    const recommendation = dialog.querySelector('[data-task-index-readiness-recommendation]');
    const issues = dialog.querySelector('[data-task-index-readiness-issues]');
    const iconWrap = dialog.querySelector('[data-task-index-readiness-icon-wrap]');
    const editLink = dialog.querySelector('[data-task-index-readiness-edit]');
    const manageLink = dialog.querySelector('[data-task-index-readiness-manage]');
    const closeButtons = dialog.querySelectorAll('[data-task-index-readiness-close]');
    let lastFocus = null;

    const setHidden = (element, hidden) => {
        if (!element) return;
        element.hidden = hidden;
        element.classList.toggle('hidden', hidden);
    };

    const createText = (tag, text, className) => {
        const element = root.createElement(tag);
        element.textContent = String(text || '');
        element.className = className;
        return element;
    };

    const renderIssues = (items) => {
        issues?.replaceChildren();
        items.forEach((issue) => {
            const blocking = issue?.severity === 'blocking';
            const section = root.createElement('section');
            section.className = blocking
                ? 'rounded-xl bg-red-50 px-4 py-3.5 text-red-950'
                : 'rounded-xl bg-amber-50 px-4 py-3.5 text-amber-950';
            section.append(createText('h3', issue?.title, 'text-sm font-semibold leading-6'));
            section.append(createText('p', issue?.message, 'mt-1 text-sm leading-6 text-pretty'));
            if (issue?.impact) section.append(createText('p', issue.impact, 'mt-2 text-xs leading-5 opacity-80 text-pretty'));
            if (Array.isArray(issue?.suggestions) && issue.suggestions.length > 0) {
                const list = root.createElement('ol');
                list.className = 'mt-2 list-decimal space-y-1 pl-5 text-xs leading-5';
                issue.suggestions.forEach((suggestion) => list.append(createText('li', suggestion, 'pl-0.5')));
                section.append(list);
            }
            issues?.append(section);
        });
    };

    const close = () => {
        if (dialog.open) dialog.close();
    };

    const open = (rawReport, trigger = null) => {
        const report = normalizeTaskIndexReadiness(rawReport);
        const warning = report.status === 'warning';
        lastFocus = trigger || root.activeElement || null;
        title.textContent = warning ? dialog.dataset.warningTitle : dialog.dataset.blockedTitle;
        summary.textContent = report.summary;
        recommendation.textContent = report.recommendation;
        iconWrap.className = warning
            ? 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700'
            : 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600';
        Object.entries(report.stats).forEach(([key, value]) => {
            const target = dialog.querySelector(`[data-task-index-readiness-stat="${key}"]`);
            if (target) target.textContent = String(value);
        });
        renderIssues(report.issues);

        if (editLink) editLink.href = report.editUrl || '#';
        if (manageLink) manageLink.href = report.manageUrl || '#';
        setHidden(editLink, report.editUrl === '');
        setHidden(manageLink, report.manageUrl === '');

        if (!dialog.open) dialog.showModal();
        if (windowRef.matchMedia?.('(prefers-reduced-motion: reduce)').matches !== true && typeof dialog.animate === 'function') {
            dialog.animate(
                [{ opacity: 0, transform: 'scale(.98)' }, { opacity: 1, transform: 'scale(1)' }],
                { duration: 180, easing: 'cubic-bezier(.16,1,.3,1)' },
            );
        }
        closeButtons[0]?.focus({ preventScroll: true });
    };

    closeButtons.forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('close', () => lastFocus?.focus?.({ preventScroll: true }));
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) close();
    });
    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
            .filter((element) => !element.hidden && !element.classList.contains('hidden'));
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && root.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && root.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    windowRef.addEventListener('geoflow:task-title-readiness', (event) => {
        if (event.detail?.report) open(event.detail.report, event.detail.trigger);
    });

    const initialReport = readJson(root, '[data-task-index-readiness-initial]');
    if (initialReport) open(initialReport);

    return { open, close };
}

if (typeof document !== 'undefined') initializeTaskIndexReadiness(document, window);
