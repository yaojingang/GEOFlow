const toCount = (value) => Math.max(0, Number.parseInt(String(value ?? 0), 10) || 0);

export function deriveInlineTitleStats(library, articleLimit, createdCount) {
    return {
        total: toCount(library?.total),
        used: toCount(library?.used),
        available: toCount(library?.available),
        remaining: Math.max(0, toCount(articleLimit) - toCount(createdCount)),
    };
}

export function shouldSubmitImmediately(report) {
    return report?.status === 'ready' && report?.requires_acknowledgement !== true;
}

export function buildReadinessActions(report) {
    const task = report?.task ?? {};
    const suggestedLimit = toCount(report?.suggested_article_limit);
    const createdCount = toCount(task.created_count);
    const requestFailed = report?.request_failed === true;

    return {
        adjust: !requestFailed && report?.can_activate === false && suggestedLimit >= Math.max(1, createdCount),
        enableLoop: !requestFailed && report?.can_activate === false && task.is_loop !== true && toCount(report?.library?.total) > 0,
        savePaused: !requestFailed && report?.can_activate === false,
        acknowledge: !requestFailed && report?.can_save === true && report?.can_activate === true && report?.requires_acknowledgement === true,
        retry: requestFailed,
        serverCheck: requestFailed,
    };
}

export function applySuggestedLimit(articleLimitInput, draftLimitInput, suggestedLimit) {
    const value = String(Math.max(1, toCount(suggestedLimit)));
    articleLimitInput.value = value;
    draftLimitInput.value = value;
    draftLimitInput.max = value;
}

export function syncSelectableCardState(card, disabled) {
    if (!card) return;

    card.classList.toggle('cursor-pointer', !disabled);
    card.classList.toggle('hover:border-blue-300', !disabled);
    card.classList.toggle('hover:bg-blue-50', !disabled);
    card.classList.toggle('cursor-not-allowed', disabled);
    card.classList.toggle('bg-gray-50', disabled);
    card.classList.toggle('opacity-50', disabled);
}

function readJson(root, selector, fallback = {}) {
    const source = root.querySelector(selector);
    if (!source) return fallback;

    try {
        return JSON.parse(source.textContent || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function initializeLinkedFields(form, i18n) {
    const imageLibrarySelect = form.querySelector('#image_library_id');
    const imageCountSelect = form.querySelector('#image_count');
    const needReviewCheckbox = form.querySelector('#need_review');
    const publishIntervalInput = form.querySelector('#publish_interval');
    const articleLimitInput = form.querySelector('#article_limit');
    const draftLimitInput = form.querySelector('#draft_limit');
    const fixedCategorySection = form.querySelector('#fixed-category-section');
    const fixedCategorySelect = form.querySelector('#fixed_category_id');
    const categoryModeRadios = form.querySelectorAll('input[name="category_mode"]');
    const publishScopeRadios = form.querySelectorAll('[data-publish-scope-option]');
    const distributionChannelInputs = form.querySelectorAll('[data-distribution-channel-input]');
    const distributionStrategyInputs = form.querySelectorAll('[data-distribution-strategy-input]');
    const distributionStrategyCards = form.querySelectorAll('[data-distribution-strategy-card]');
    const distributionChannelCount = form.querySelector('[data-distribution-channel-count]');
    const distributionChannelToggle = form.querySelector('[data-distribution-channel-toggle]');
    const collapsedDistributionChannelCards = form.querySelectorAll('[data-distribution-channel-collapsed="true"]');
    const distributionSelectAllButton = form.querySelector('[data-distribution-channel-select-all]');
    const distributionClearButton = form.querySelector('[data-distribution-channel-clear]');
    const knowledgeBaseInputs = form.querySelectorAll('[data-knowledge-base-input]');
    const knowledgeBaseCount = form.querySelector('[data-knowledge-base-count]');
    const knowledgeBaseToggle = form.querySelector('[data-knowledge-base-toggle]');
    const collapsedKnowledgeBaseCards = form.querySelectorAll('[data-knowledge-base-collapsed="true"]');
    let distributionChannelsExpanded = false;
    let knowledgeBaseExpanded = false;
    const aiQualityToggle = form.querySelector('[data-ai-quality-toggle]');
    const aiQualitySettings = form.querySelector('[data-ai-quality-settings]');
    const aiQualityState = form.querySelector('[data-ai-quality-state]');
    const aiQualityRequiredFields = form.querySelectorAll('[data-ai-quality-required]');
    const aiQualityWorkflow = form.querySelector('[data-ai-quality-workflow]');
    const aiQualityWorkflowTail = form.querySelector('[data-ai-quality-workflow-tail]');
    const aiQualityTimeoutSampling = form.querySelector('[data-ai-quality-timeout-sampling]');
    const aiQualityPassScore = form.querySelector('#ai_quality_pass_score');
    const aiQualityOptimization = form.querySelector('[data-ai-quality-optimization]');
    const aiQualityOptimizationToggle = form.querySelector('[data-ai-quality-optimization-toggle]');
    const aiQualityOptimizationLevels = form.querySelectorAll('[data-ai-quality-optimization-level]');
    const aiQualityWorkflowOptimization = form.querySelectorAll('[data-ai-quality-workflow-optimization]');

    const syncOptimizationTargets = () => {
        const passScore = Math.max(1, Math.min(100, toCount(aiQualityPassScore?.value) || 85));
        aiQualityOptimizationLevels.forEach((input) => {
            const target = Math.max(passScore, toCount(input.dataset.minimumTarget));
            const label = input.closest('label')?.querySelector('[data-ai-quality-optimization-target]');
            if (label) label.textContent = String(label.dataset.targetTemplate || '').replace('__SCORE__', String(target));
        });
    };

    const toggleImageCountByLibrary = () => {
        if (!imageLibrarySelect || !imageCountSelect) return;

        if (!imageLibrarySelect.value) {
            imageCountSelect.value = '0';
            imageCountSelect.disabled = true;
        } else {
            imageCountSelect.disabled = false;
            if (imageCountSelect.value === '0') imageCountSelect.value = '1';
        }
    };

    const togglePublishInterval = () => {
        if (!needReviewCheckbox || !publishIntervalInput) return;

        publishIntervalInput.disabled = needReviewCheckbox.checked;
        publishIntervalInput.parentElement?.classList.toggle('opacity-50', needReviewCheckbox.checked);
    };

    const handleCategoryModeChange = () => {
        if (!fixedCategorySection || !fixedCategorySelect) return;
        const selected = form.querySelector('input[name="category_mode"]:checked');
        if (!selected) return;

        const isFixed = selected.value === 'fixed';
        fixedCategorySection.classList.toggle('hidden', !isFixed);
        fixedCategorySelect.required = isFixed;
        if (!isFixed) fixedCategorySelect.value = '';
    };

    const syncDraftLimitMax = () => {
        if (!articleLimitInput || !draftLimitInput) return;
        const articleLimit = Math.max(1, toCount(articleLimitInput.value));
        draftLimitInput.max = String(articleLimit);
        if (toCount(draftLimitInput.value) > articleLimit) draftLimitInput.value = String(articleLimit);
    };

    const syncDistributionChannelCount = () => {
        if (!distributionChannelCount) return;
        const count = Array.from(distributionChannelInputs).filter((input) => input.checked).length;
        distributionChannelCount.textContent = String(i18n.distributionCount || '').replace('__COUNT__', String(count));
    };

    const syncDistributionChannelVisibility = () => {
        if (!distributionChannelToggle || collapsedDistributionChannelCards.length === 0) return;
        let hiddenCount = 0;
        collapsedDistributionChannelCards.forEach((card) => {
            const input = card.querySelector('[data-distribution-channel-input]');
            const hidden = !distributionChannelsExpanded && input?.checked !== true;
            card.classList.toggle('hidden', hidden);
            if (hidden) hiddenCount += 1;
        });
        distributionChannelToggle.textContent = distributionChannelsExpanded
            ? distributionChannelToggle.dataset.collapseLabel || ''
            : String(distributionChannelToggle.dataset.expandLabel || '').replace('__COUNT__', String(hiddenCount));
        distributionChannelToggle.setAttribute('aria-expanded', String(distributionChannelsExpanded));
        distributionChannelToggle.classList.toggle('hidden', !distributionChannelsExpanded && hiddenCount === 0);
    };

    const syncDistributionChannelsByScope = () => {
        const localOnly = form.querySelector('input[name="publish_scope"]:checked')?.value === 'local_only';
        distributionStrategyInputs.forEach((input) => { input.disabled = localOnly; });
        distributionStrategyCards.forEach((card) => syncSelectableCardState(card, localOnly));
        distributionChannelInputs.forEach((input) => {
            input.disabled = localOnly;
            if (localOnly) input.checked = false;
            syncSelectableCardState(input.closest('[data-distribution-channel-card]'), localOnly);
        });
        [distributionSelectAllButton, distributionClearButton].forEach((button) => {
            if (button) button.disabled = localOnly;
        });
        syncDistributionChannelCount();
        syncDistributionChannelVisibility();
    };

    const syncKnowledgeBaseCount = () => {
        if (!knowledgeBaseCount) return;
        const count = Array.from(knowledgeBaseInputs).filter((input) => input.checked).length;
        knowledgeBaseCount.textContent = String(i18n.knowledgeBaseCount || '').replace('__COUNT__', String(count));
    };

    const syncKnowledgeBaseVisibility = () => {
        if (!knowledgeBaseToggle || collapsedKnowledgeBaseCards.length === 0) return;
        let hiddenCount = 0;
        collapsedKnowledgeBaseCards.forEach((card) => {
            const input = card.querySelector('[data-knowledge-base-input]');
            const hidden = !knowledgeBaseExpanded && input?.checked !== true;
            card.classList.toggle('hidden', hidden);
            if (hidden) hiddenCount += 1;
        });
        knowledgeBaseToggle.textContent = knowledgeBaseExpanded
            ? knowledgeBaseToggle.dataset.collapseLabel || ''
            : String(knowledgeBaseToggle.dataset.expandLabel || '').replace('__COUNT__', String(hiddenCount));
        knowledgeBaseToggle.setAttribute('aria-expanded', String(knowledgeBaseExpanded));
        knowledgeBaseToggle.classList.toggle('hidden', !knowledgeBaseExpanded && hiddenCount === 0);
    };

    const syncAiQualitySettings = () => {
        if (!aiQualityToggle || !aiQualitySettings) return;
        const enabled = aiQualityToggle.checked;
        aiQualitySettings.classList.toggle('hidden', !enabled);
        aiQualityRequiredFields.forEach((field) => { field.required = enabled; });
        if (aiQualityTimeoutSampling) {
            aiQualityTimeoutSampling.disabled = !enabled;
            if (!enabled) aiQualityTimeoutSampling.checked = false;
        }
        if (aiQualityOptimizationToggle) {
            aiQualityOptimizationToggle.disabled = !enabled;
            if (!enabled) aiQualityOptimizationToggle.checked = false;
        }
        const optimizationEnabled = enabled && aiQualityOptimizationToggle?.checked === true;
        aiQualityOptimizationLevels.forEach((field) => { field.disabled = !optimizationEnabled; });
        aiQualityOptimization?.classList.toggle('opacity-60', !enabled);
        aiQualityWorkflowOptimization.forEach((element) => element.classList.toggle('hidden', !optimizationEnabled));
        if (aiQualityState) {
            aiQualityState.textContent = enabled
                ? aiQualityState.dataset.enabledLabel || ''
                : aiQualityState.dataset.disabledLabel || '';
            aiQualityState.classList.toggle('bg-green-50', enabled);
            aiQualityState.classList.toggle('text-green-700', enabled);
            aiQualityState.classList.toggle('bg-gray-100', !enabled);
            aiQualityState.classList.toggle('text-gray-600', !enabled);
        }
        if (aiQualityWorkflow && aiQualityWorkflowTail) {
            aiQualityWorkflowTail.textContent = needReviewCheckbox?.checked
                ? aiQualityWorkflow.dataset.manualLabel || ''
                : aiQualityWorkflow.dataset.autoLabel || '';
        }
    };

    imageLibrarySelect?.addEventListener('change', toggleImageCountByLibrary);
    needReviewCheckbox?.addEventListener('change', togglePublishInterval);
    needReviewCheckbox?.addEventListener('change', syncAiQualitySettings);
    aiQualityToggle?.addEventListener('change', syncAiQualitySettings);
    aiQualityOptimizationToggle?.addEventListener('change', syncAiQualitySettings);
    aiQualityPassScore?.addEventListener('input', syncOptimizationTargets);
    articleLimitInput?.addEventListener('input', syncDraftLimitMax);
    categoryModeRadios.forEach((radio) => radio.addEventListener('change', handleCategoryModeChange));
    publishScopeRadios.forEach((radio) => radio.addEventListener('change', syncDistributionChannelsByScope));
    distributionChannelInputs.forEach((input) => input.addEventListener('change', () => {
        syncDistributionChannelCount();
        syncDistributionChannelVisibility();
    }));
    distributionSelectAllButton?.addEventListener('click', () => {
        distributionChannelInputs.forEach((input) => { if (!input.disabled) input.checked = true; });
        syncDistributionChannelCount();
        syncDistributionChannelVisibility();
    });
    distributionClearButton?.addEventListener('click', () => {
        distributionChannelInputs.forEach((input) => { if (!input.disabled) input.checked = false; });
        syncDistributionChannelCount();
        syncDistributionChannelVisibility();
    });
    distributionChannelToggle?.addEventListener('click', () => {
        distributionChannelsExpanded = !distributionChannelsExpanded;
        syncDistributionChannelVisibility();
    });
    knowledgeBaseToggle?.addEventListener('click', () => {
        knowledgeBaseExpanded = !knowledgeBaseExpanded;
        syncKnowledgeBaseVisibility();
    });
    knowledgeBaseInputs.forEach((input) => input.addEventListener('change', () => {
        const selectedCount = Array.from(knowledgeBaseInputs).filter((item) => item.checked).length;
        if (selectedCount > 5) {
            input.checked = false;
            input.setCustomValidity(i18n.knowledgeBaseLimit || '');
            input.reportValidity();
            input.setCustomValidity('');
        }
        syncKnowledgeBaseCount();
        syncKnowledgeBaseVisibility();
    }));

    toggleImageCountByLibrary();
    togglePublishInterval();
    handleCategoryModeChange();
    syncDraftLimitMax();
    syncDistributionChannelsByScope();
    syncDistributionChannelCount();
    syncDistributionChannelVisibility();
    syncKnowledgeBaseCount();
    syncKnowledgeBaseVisibility();
    syncAiQualitySettings();
    syncOptimizationTargets();
}

function initializeReadinessCheck(root, form, i18n, fetchImpl, requestTimeoutMs) {
    const dialog = root.querySelector('[data-task-title-readiness-dialog]');
    const submitButton = form.querySelector('[data-task-form-submit]');
    const submitLabel = form.querySelector('[data-task-form-submit-label]');
    const titleLibrary = form.querySelector('#title_library_id');
    const articleLimit = form.querySelector('#article_limit');
    const draftLimit = form.querySelector('#draft_limit');
    const loopMode = form.querySelector('#is_loop');
    const status = form.querySelector('#status');
    const statsRegion = form.querySelector('[data-task-title-stats]');
    if (!dialog || !submitButton || !submitLabel || !titleLibrary || !articleLimit || !draftLimit || !loopMode || !status) return;
    submitButton.disabled = false;
    submitButton.removeAttribute?.('aria-disabled');

    const defaultSubmitLabel = submitLabel.textContent;
    const createdCount = toCount(form.dataset.createdCount);
    const titleElement = dialog.querySelector('[data-task-readiness-title]');
    const summaryElement = dialog.querySelector('[data-task-readiness-summary]');
    const recommendationElement = dialog.querySelector('[data-task-readiness-recommendation]');
    const pausedHint = dialog.querySelector('[data-task-readiness-paused-hint]');
    const issuesElement = dialog.querySelector('[data-task-readiness-issues]');
    const iconWrap = dialog.querySelector('[data-task-readiness-icon-wrap]');
    const adjustButton = dialog.querySelector('[data-task-readiness-adjust]');
    const loopButton = dialog.querySelector('[data-task-readiness-loop]');
    const manageLink = dialog.querySelector('[data-task-readiness-manage]');
    const pauseButton = dialog.querySelector('[data-task-readiness-pause]');
    const acknowledgeButton = dialog.querySelector('[data-task-readiness-acknowledge]');
    const retryButton = dialog.querySelector('[data-task-readiness-retry]');
    const serverButton = dialog.querySelector('[data-task-readiness-server]');
    const closeButtons = dialog.querySelectorAll('[data-task-readiness-close]');
    let lastReport = null;
    let lastSubmitter = submitButton;
    let confirmedFingerprint = null;
    let checking = false;

    const selectedLibrary = () => {
        const option = titleLibrary.selectedOptions?.[0];
        return {
            name: option?.dataset.titleName || '',
            total: option?.dataset.titleTotal || 0,
            used: option?.dataset.titleUsed || 0,
            available: option?.dataset.titleAvailable || 0,
            manageUrl: option?.dataset.titleManageUrl || '#',
        };
    };

    const updateInlineStats = () => {
        if (!statsRegion) return;
        const values = deriveInlineTitleStats(selectedLibrary(), articleLimit.value, createdCount);
        Object.entries(values).forEach(([key, value]) => {
            const element = statsRegion.querySelector(`[data-task-title-stat="${key}"]`);
            if (element) element.textContent = String(value);
        });
    };

    const setChecking = (value, lockFields = value) => {
        checking = value;
        submitButton.disabled = value;
        [titleLibrary, articleLimit, loopMode, status].forEach((field) => { field.disabled = lockFields; });
        submitButton.toggleAttribute('aria-busy', value);
        submitLabel.textContent = value ? i18n.checking : defaultSubmitLabel;
    };

    const readinessPayload = () => ({
        title_library_id: toCount(titleLibrary.value),
        article_limit: Math.max(1, toCount(articleLimit.value)),
        is_loop: loopMode.checked,
        status: status.value,
        ...(toCount(form.dataset.taskId) > 0 ? { task_id: toCount(form.dataset.taskId) } : {}),
    });

    const readinessFingerprint = () => JSON.stringify(readinessPayload());

    const setHidden = (element, value) => {
        element.hidden = value;
        element.classList.toggle('hidden', value);
    };

    const closeDialog = () => {
        if (dialog.open) dialog.close();
        setChecking(false);
    };

    const createText = (tag, text, className) => {
        const element = root.createElement(tag);
        element.textContent = text || '';
        element.className = className;
        return element;
    };

    const renderIssues = (issues) => {
        issuesElement.replaceChildren();
        (issues || []).forEach((issue) => {
            const blocking = issue.severity === 'blocking';
            const section = root.createElement('section');
            section.className = blocking
                ? 'rounded-xl bg-red-50 px-4 py-3.5 text-red-950'
                : 'rounded-xl bg-amber-50 px-4 py-3.5 text-amber-950';
            section.append(createText('h3', issue.title, 'text-sm font-semibold leading-6'));
            section.append(createText('p', issue.message, 'mt-1 text-sm leading-6 text-pretty'));
            if (issue.impact) section.append(createText('p', issue.impact, 'mt-2 text-xs leading-5 opacity-80 text-pretty'));
            if (Array.isArray(issue.suggestions) && issue.suggestions.length > 0) {
                const list = root.createElement('ol');
                list.className = 'mt-2 list-decimal space-y-1 pl-5 text-xs leading-5';
                issue.suggestions.forEach((suggestion) => list.append(createText('li', suggestion, 'pl-0.5')));
                section.append(list);
            }
            issuesElement.append(section);
        });
    };

    const openDialog = (report) => {
        lastReport = report;
        const warning = report.status !== 'blocked';
        titleElement.textContent = warning ? i18n.warningTitle : i18n.blockedTitle;
        summaryElement.textContent = report.summary || i18n.requestFailed;
        recommendationElement.textContent = report.recommendation || i18n.requestFailed;
        pausedHint.textContent = report.paused_hint || '';
        setHidden(pausedHint, !report.paused_hint);
        iconWrap.className = warning
            ? 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700'
            : 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600';
        const reportStats = {
            remaining: toCount(report.task?.remaining),
            total: toCount(report.library?.total),
            used: toCount(report.library?.used),
            available: toCount(report.library?.available),
        };
        Object.entries(reportStats).forEach(([key, value]) => {
            const element = dialog.querySelector(`[data-task-readiness-stat="${key}"]`);
            if (element) element.textContent = String(value);
        });
        renderIssues(report.issues);

        const actions = buildReadinessActions(report);
        setHidden(adjustButton, !actions.adjust);
        adjustButton.textContent = String(i18n.adjustLimit || '').replace('__COUNT__', String(toCount(report.suggested_article_limit)));
        setHidden(loopButton, !actions.enableLoop);
        setHidden(pauseButton, !actions.savePaused);
        pauseButton.textContent = report.task?.status === 'paused' ? i18n.saveExistingPaused : i18n.savePaused;
        setHidden(acknowledgeButton, !actions.acknowledge);
        setHidden(retryButton, !actions.retry);
        setHidden(serverButton, !actions.serverCheck);
        manageLink.href = report.manage_url || selectedLibrary().manageUrl;
        setHidden(manageLink, report.request_failed === true);

        if (!dialog.open) dialog.showModal();
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && typeof dialog.animate === 'function') {
            dialog.animate(
                [{ opacity: 0, transform: 'scale(.98)' }, { opacity: 1, transform: 'scale(1)' }],
                { duration: 180, easing: 'cubic-bezier(.16,1,.3,1)' },
            );
        }
        setChecking(false);
        closeButtons[0]?.focus({ preventScroll: true });
    };

    const submitNative = () => {
        if (dialog.open) dialog.close();
        confirmedFingerprint = readinessFingerprint();
        const fingerprint = confirmedFingerprint;
        form.requestSubmit(lastSubmitter || submitButton);
        queueMicrotask(() => {
            if (confirmedFingerprint === fingerprint) confirmedFingerprint = null;
        });
    };

    const requestReport = async (payload) => {
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        let timeoutId;
        const timeout = new Promise((_, reject) => {
            timeoutId = setTimeout(() => {
                controller?.abort();
                reject(new Error('readiness:timeout'));
            }, requestTimeoutMs);
        });
        const request = Promise.resolve(fetchImpl(form.dataset.titleReadinessUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value || '',
            },
            body: JSON.stringify(payload),
            ...(controller ? { signal: controller.signal } : {}),
        }));

        try {
            const response = await Promise.race([request, timeout]);
            if (!response.ok) throw new Error(`readiness:${response.status}`);

            return response.json();
        } finally {
            clearTimeout(timeoutId);
        }
    };

    const runCheck = async () => {
        if (checking) return;
        const payload = readinessPayload();
        const fingerprint = JSON.stringify(payload);
        setChecking(true);
        try {
            const report = await requestReport(payload);
            if (fingerprint !== readinessFingerprint()) {
                setChecking(false);
                void runCheck();
                return;
            }
            if (shouldSubmitImmediately(report)) {
                setChecking(false);
                submitNative();
                return;
            }
            openDialog(report);
        } catch {
            const inline = deriveInlineTitleStats(selectedLibrary(), articleLimit.value, createdCount);
            openDialog({
                status: 'warning',
                can_save: true,
                can_activate: false,
                requires_acknowledgement: true,
                request_failed: true,
                library: inline,
                task: { status: status.value, is_loop: loopMode.checked, created_count: createdCount, remaining: inline.remaining },
                issues: [i18n.requestFailedIssue],
                summary: i18n.requestFailed,
                recommendation: i18n.requestFailed,
            });
        }
    };

    form.addEventListener('submit', (event) => {
        const fingerprint = readinessFingerprint();
        if (confirmedFingerprint === fingerprint) {
            confirmedFingerprint = null;
            setChecking(true, false);
            return;
        }
        confirmedFingerprint = null;
        event.preventDefault();
        lastSubmitter = event.submitter || submitButton;
        void runCheck();
    });
    titleLibrary.addEventListener('change', updateInlineStats);
    articleLimit.addEventListener('input', updateInlineStats);
    closeButtons.forEach((button) => button.addEventListener('click', closeDialog));
    dialog.addEventListener('cancel', () => setChecking(false));
    dialog.addEventListener('close', () => lastSubmitter?.focus({ preventScroll: true }));
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) closeDialog();
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
    adjustButton.addEventListener('click', () => {
        applySuggestedLimit(articleLimit, draftLimit, lastReport?.suggested_article_limit);
        updateInlineStats();
        closeDialog();
        void runCheck();
    });
    loopButton.addEventListener('click', () => {
        loopMode.checked = true;
        closeDialog();
        void runCheck();
    });
    pauseButton.addEventListener('click', () => {
        status.value = 'paused';
        submitNative();
    });
    acknowledgeButton.addEventListener('click', submitNative);
    retryButton.addEventListener('click', () => {
        closeDialog();
        void runCheck();
    });
    serverButton.addEventListener('click', submitNative);

    updateInlineStats();
    const initialReport = readJson(root, '[data-task-title-readiness-initial]', null);
    if (initialReport) openDialog(initialReport);
}

export function initializeTaskForm(root = document, options = {}) {
    const form = root.querySelector('[data-task-form]');
    if (!form) return;
    const i18n = readJson(root, '[data-task-form-i18n]');
    initializeLinkedFields(form, i18n);
    initializeReadinessCheck(
        root,
        form,
        i18n,
        options.fetchImpl || window.fetch.bind(window),
        Math.max(1, Number(options.readinessTimeoutMs) || 12000),
    );

    if (!window.GeoFlowAdminUi?.refreshIcons && typeof window.lucide !== 'undefined') {
        window.lucide.createIcons();
    }
}

if (typeof document !== 'undefined') initializeTaskForm(document);
