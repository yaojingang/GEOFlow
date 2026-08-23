const terminalStates = new Set(['completed', 'partially_completed', 'failed', 'cancelled', 'outcome_unknown', 'rejected']);
const pollingStates = new Set(['received', 'answering', 'planning', 'validating_plan', 'queued', 'running', 'cancel_requested']);

export function shouldAcceptRunSnapshot(current, incoming) {
    if (!incoming?.id) return false;
    if (!current || current.id !== incoming.id) return true;

    const currentSequence = Number(current.sequence ?? 0);
    const incomingSequence = Number(incoming.sequence ?? 0);
    if (incomingSequence !== currentSequence) return incomingSequence > currentSequence;

    return Number(incoming.version ?? 0) > Number(current.version ?? 0);
}

export function shouldApplyRunSnapshot(current, incoming, allowRunSwitch = false) {
    if (current?.id && incoming?.id && current.id !== incoming.id) return allowRunSwitch;

    return shouldAcceptRunSnapshot(current, incoming);
}

export function shouldFetchRunUpdate(current, event, activeConversationId) {
    if (!event?.run_id || event.conversation_id !== activeConversationId) return false;
    if (current?.id && current.id !== event.run_id) return false;
    if (!current) return true;

    return Number(event.sequence ?? 0) > Number(current.sequence ?? 0)
        || Number(event.version ?? 0) > Number(current.version ?? 0);
}

export function isSubmissionCurrent(currentGeneration, expectedGeneration, currentConversationId, expectedConversationId) {
    return currentGeneration === expectedGeneration && currentConversationId === expectedConversationId;
}

export function shouldSubmitPrompt(event) {
    return event.key === 'Enter' && !event.shiftKey && !event.isComposing;
}

export function composerControlsState(_runtimeEnabled, submissionInFlight, hasPrompt) {
    return {
        inputDisabled: submissionInFlight,
        submitDisabled: submissionInFlight || !hasPrompt,
    };
}

export function errorDialogContent(kind, message, labels) {
    if (kind === 'runtime') {
        return {
            kind,
            title: labels.errorRuntimeTitle,
            description: labels.errorRuntimeDescription,
            detail: message,
            hint: labels.errorRuntimeHint,
            closeLabel: labels.continueEditing,
            primaryAction: 'configurator',
            primaryLabel: labels.openConfigurator,
        };
    }
    if (kind === 'session') {
        return {
            kind,
            title: labels.errorSessionTitle,
            description: labels.errorSessionDescription,
            detail: message,
            hint: labels.errorSessionHint,
            closeLabel: labels.returnToPage,
            primaryAction: 'reload',
            primaryLabel: labels.refreshPage,
        };
    }
    if (kind === 'network') {
        return {
            kind,
            title: labels.errorNetworkTitle,
            description: labels.errorNetworkDescription,
            detail: message,
            hint: labels.errorNetworkHint,
            closeLabel: labels.returnToPage,
            primaryAction: null,
            primaryLabel: null,
        };
    }

    return {
        kind: 'generic',
        title: labels.errorTitle,
        description: message,
        detail: '',
        hint: labels.errorHint,
        closeLabel: labels.returnToPage,
        primaryAction: null,
        primaryLabel: null,
    };
}

export function runProgressStage(state) {
    if (state === 'received' || state === 'answering') return 'intake';
    if (state === 'planning' || state === 'validating_plan') return 'planning';
    if (state === 'queued' || state === 'running' || state === 'cancel_requested') return 'execution';

    return null;
}

export function shouldAcceptAnswerDelta(currentRun, stream, event) {
    if (!event?.run_id || !event?.delta) return false;
    if (currentRun && currentRun.id !== event.run_id) return false;
    if (currentRun?.id === event.run_id && terminalStates.has(currentRun.state)) return false;
    const runSequence = Number(event.run_sequence ?? 0);
    if (currentRun?.id === event.run_id && runSequence < Number(currentRun.sequence ?? 0)) return false;
    if (!stream || stream.runId !== event.run_id) return true;
    if (runSequence !== Number(stream.runSequence ?? 0)) return runSequence > Number(stream.runSequence ?? 0);

    return Number(event.chunk_sequence ?? 0) > Number(stream.sequence ?? 0);
}

export function runStateGroup(state) {
    if (state === 'completed') return 'success';
    if (state === 'partially_completed' || state === 'outcome_unknown' || state === 'skipped') return 'warning';
    if (state === 'failed' || state === 'rejected') return 'danger';
    if (state === 'cancelled') return 'neutral';
    if (state === 'awaiting_approval' || state === 'awaiting_step_approval' || state === 'clarifying') return 'attention';

    return 'active';
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);

    return node;
}

function requestKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();

    return `aiw-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function safeResultUrl(value) {
    try {
        const url = new URL(String(value), window.location.origin);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}

function initializeAiWorkspace() {
    const root = document.querySelector('[data-ai-workspace]');
    if (!root) return;

    const labels = JSON.parse(root.querySelector('[data-ai-labels]')?.textContent ?? '{}');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const runtimeEnabled = root.dataset.runtimeEnabled === 'true';
    const runtimeUnavailableMessage = root.dataset.runtimeUnavailableMessage || labels.runtimeUnavailable;
    const start = root.querySelector('[data-ai-start]');
    const thread = root.querySelector('[data-ai-thread]');
    const messages = root.querySelector('[data-ai-messages]');
    const runs = root.querySelector('[data-ai-runs]');
    const historyList = document.querySelector('[data-ai-history-list]');
    const form = root.querySelector('[data-ai-form]');
    const input = root.querySelector('[data-ai-input]');
    const alert = root.querySelector('[data-ai-alert]');
    const errorDialog = root.querySelector('[data-ai-error-dialog]');
    const errorTitle = errorDialog?.querySelector('[data-ai-error-title]');
    const errorDescription = errorDialog?.querySelector('[data-ai-error-description]');
    const errorDetail = errorDialog?.querySelector('[data-ai-error-detail]');
    const errorHint = errorDialog?.querySelector('[data-ai-error-hint]');
    const errorSecondary = errorDialog?.querySelector('[data-ai-error-secondary]');
    const errorConfigurator = errorDialog?.querySelector('[data-ai-error-configurator]');
    const errorConfiguratorLabel = errorDialog?.querySelector('[data-ai-error-configurator-label]');
    const errorReload = errorDialog?.querySelector('[data-ai-error-reload]');
    const errorReloadLabel = errorDialog?.querySelector('[data-ai-error-reload-label]');
    let activeConversationId = null;
    let activeRun = null;
    let activeAnswerStream = null;
    let pollTimer = null;
    let viewGeneration = 0;
    let submissionGeneration = 0;
    let submissionInFlight = false;
    const submitButton = form?.querySelector('button[type="submit"]');

    const endpoint = (template, id) => String(template ?? '').replace('__ID__', encodeURIComponent(id));
    const formatDate = (value) => value ? new Date(value).toLocaleDateString(document.documentElement.lang || undefined, {
        month: 'numeric',
        day: 'numeric',
    }) : '';

    const resizeInput = () => {
        if (!input) return;
        input.style.height = 'auto';
        const nextHeight = Math.min(Math.max(input.scrollHeight, 88), 144);
        input.style.height = `${nextHeight}px`;
        input.style.overflowY = input.scrollHeight > 144 ? 'auto' : 'hidden';
    };

    const syncComposer = () => {
        const hasPrompt = Boolean(input?.value.trim());
        const controls = composerControlsState(runtimeEnabled, submissionInFlight, hasPrompt);
        if (input) input.disabled = controls.inputDisabled;
        if (submitButton) submitButton.disabled = controls.submitDisabled;
        resizeInput();
    };

    const syncConversationUrl = (conversationId = null) => {
        const url = new URL(window.location.href);
        if (conversationId) url.searchParams.set('conversation', conversationId);
        else url.searchParams.delete('conversation');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    };

    const showAlert = (message, requestedKind = 'auto') => {
        const kind = requestedKind !== 'auto'
            ? requestedKind
            : message === labels.sessionExpired
                ? 'session'
                : message === labels.networkError ? 'network' : 'generic';
        const content = errorDialogContent(kind, message, labels);
        alert.textContent = message;
        alert.hidden = false;
        if (!errorDialog) return;
        errorDialog.dataset.kind = content.kind;
        errorTitle.textContent = content.title;
        errorDescription.textContent = content.description;
        errorDetail.textContent = content.detail;
        errorDetail.closest('.gf-ai-error-dialog__detail').hidden = content.detail === '';
        errorHint.textContent = content.hint;
        errorSecondary.textContent = content.closeLabel;
        errorConfigurator.hidden = content.primaryAction !== 'configurator';
        errorConfigurator.href = root.dataset.aiConfiguratorUrl;
        errorConfiguratorLabel.textContent = content.primaryLabel ?? labels.openConfigurator;
        errorReload.hidden = content.primaryAction !== 'reload';
        errorReloadLabel.textContent = content.primaryLabel ?? labels.refreshPage;
        document.dispatchEvent(new CustomEvent('geoflow:modal:open', {
            detail: { name: 'ai-workspace-error', opener: input },
        }));
    };

    const clearAlert = () => {
        alert.hidden = true;
        alert.textContent = '';
        if (errorDialog?.classList.contains('is-open')) {
            document.dispatchEvent(new CustomEvent('geoflow:modal:close', {
                detail: { name: 'ai-workspace-error' },
            }));
        }
    };

    const setSubmitting = (submitting) => {
        submissionInFlight = submitting;
        syncComposer();
    };

    const invalidateSubmission = () => {
        submissionGeneration += 1;
        setSubmitting(false);
    };

    const request = async (url, options = {}) => {
        let response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    ...(options.headers ?? {}),
                },
            });
        } catch {
            throw new Error(labels.networkError);
        }
        const contentType = response.headers.get('content-type') ?? '';
        if (response.status === 401 || !contentType.includes('application/json')) {
            throw new Error(labels.sessionExpired);
        }
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message ?? labels.networkError);

        return payload.data;
    };

    const setThreadVisible = (visible) => {
        start.hidden = visible;
        thread.hidden = !visible;
        root.classList.toggle('is-conversation-active', visible);
    };

    const appendMessage = (role, content, meta = {}) => {
        const item = element('article', `gf-ai-message gf-ai-message--${role}`);
        if (meta.pending) {
            item.classList.add('is-pending');
            item.setAttribute('aria-label', labels.messageSending);
        }
        const bubble = element('div', 'gf-ai-message__bubble', content);
        item.append(bubble);
        if (meta.system_operations_executed === false) {
            item.append(element('small', 'gf-ai-no-operation', labels.systemNotExecuted));
        }
        messages.append(item);

        return item;
    };

    const renderRunActivity = (state, statusMessage = '') => {
        const stage = runProgressStage(state);
        if (!stage) return null;
        const activity = element('div', `gf-ai-run-activity is-${stage}`);
        activity.setAttribute('role', 'status');
        activity.setAttribute('aria-live', 'polite');
        const signal = element('span', 'gf-ai-run-activity__signal');
        signal.setAttribute('aria-hidden', 'true');
        const copy = element('div', 'gf-ai-run-activity__copy');
        copy.append(element('strong', null, labels.activityStages?.[stage] ?? labels.activityTitle));
        copy.append(element('span', null, statusMessage || labels.submissionPending));
        const dots = element('span', 'gf-ai-run-activity__dots');
        dots.setAttribute('aria-hidden', 'true');
        dots.append(element('i'), element('i'), element('i'));
        activity.append(signal, copy, dots);

        return activity;
    };

    const renderSubmissionPending = () => {
        runs.querySelector('[data-ai-submission-pending]')?.remove();
        const card = element('article', 'gf-ai-run-card is-active gf-ai-submission-pending');
        card.dataset.aiSubmissionPending = 'true';
        card.setAttribute('aria-busy', 'true');
        card.append(renderRunActivity('received', labels.submissionPending));
        runs.prepend(card);
    };

    const renderHistory = (items) => {
        if (!historyList) return;
        historyList.replaceChildren();
        if (!items.length) {
            historyList.append(element('p', 'gf-sidebar__empty', labels.historyEmpty));
            return;
        }
        items.slice(0, 3).forEach((conversation) => {
            const row = element('div', 'gf-ai-history__item');
            if (conversation.id === activeConversationId) row.classList.add('is-active');
            const open = element('a', 'gf-ai-history__open');
            const conversationUrl = new URL(window.location.href);
            conversationUrl.searchParams.set('conversation', conversation.id);
            open.href = `${conversationUrl.pathname}${conversationUrl.search}${conversationUrl.hash}`;
            open.dataset.conversationId = conversation.id;
            if (conversation.id === activeConversationId) open.setAttribute('aria-current', 'page');
            open.append(element('span', null, conversation.title));
            open.append(element('small', null, formatDate(conversation.updated_at)));
            const archive = element('button', 'gf-ai-history__archive', labels.archive);
            archive.type = 'button';
            archive.dataset.archiveConversation = conversation.id;
            row.append(open, archive);
            historyList.append(row);
        });
    };

    const loadHistory = async () => {
        try {
            const conversations = await request(root.dataset.conversationsUrl);
            renderHistory(conversations);
            return conversations;
        } catch (error) {
            showAlert(error.message);
            return [];
        }
    };

    const renderStep = (step, runState, payloadPruned = false) => {
        const item = element('li', 'gf-ai-plan-step');
        item.classList.add(`is-${step.state}`);
        item.dataset.stepId = step.id;
        const head = element('div', 'gf-ai-plan-step__head');
        head.append(element('strong', null, `${step.position}. ${step.capability_name ?? step.capability}`));
        head.append(element('span', `gf-ai-step-state is-${runStateGroup(step.state)}`, labels.statuses?.[step.state] ?? step.state));
        item.append(head);
        const facts = element('div', 'gf-ai-plan-step__facts');
        facts.append(element('span', null, labels.risks?.[step.risk_level] ?? step.risk_level));
        facts.append(element('span', null, labels.scopes?.[step.execution_scope] ?? step.execution_scope));
        facts.append(element('span', null, `v${step.capability_version}`));
        item.append(facts);
        if (step.state === 'running') {
            const activity = element('span', 'gf-ai-plan-step__activity', labels.stepRunning);
            activity.prepend(element('i'));
            item.append(activity);
        }
        const parameters = element('pre', 'gf-ai-plan-step__parameters', JSON.stringify(step.parameters ?? {}, null, 2));
        item.append(parameters);
        if (step.result_summary?.summary) item.append(element('p', 'gf-ai-plan-step__result', step.result_summary.summary));
        if (step.error_message) item.append(element('p', 'gf-ai-plan-step__error', step.error_message));
        if (!payloadPruned && step.state === 'failed' && ['failed', 'partially_completed'].includes(runState)
            && String(step.execution_scope) !== 'external_write') {
            const retry = element('button', 'gf-button gf-button--quiet', labels.retry);
            retry.type = 'button';
            retry.dataset.retryStep = step.id;
            item.append(retry);
        }

        return item;
    };

    const renderPlanEditor = (snapshot) => {
        const editor = element('form', 'gf-ai-plan-editor');
        editor.dataset.planEditor = snapshot.id;
        editor.dataset.planVersion = snapshot.plan_version;
        snapshot.steps.forEach((step) => {
            const label = element('label', null, step.capability);
            const textarea = element('textarea', 'gf-field');
            textarea.rows = 6;
            textarea.dataset.stepParameters = step.id;
            textarea.value = JSON.stringify(step.parameters ?? {}, null, 2);
            label.append(textarea);
            editor.append(label);
        });
        const actions = element('div', 'gf-ai-card__actions');
        const save = element('button', 'gf-button gf-button--primary', labels.savePlan);
        save.type = 'submit';
        actions.append(save);
        editor.append(actions);

        return editor;
    };

    const renderRun = (snapshot, { allowRunSwitch = false } = {}) => {
        if (!shouldApplyRunSnapshot(activeRun, snapshot, allowRunSwitch)) {
            if (activeRun?.id === snapshot.id) schedulePolling(activeRun);
            return;
        }
        activeRun = snapshot;
        activeAnswerStream = null;
        runs.replaceChildren();
        const card = element('article', `gf-ai-run-card is-${runStateGroup(snapshot.state)}`);
        card.dataset.runId = snapshot.id;
        const header = element('header', 'gf-ai-run-card__head');
        const title = element('div');
        title.append(element('strong', null, labels.statuses?.[snapshot.state] ?? snapshot.state));
        title.append(element('small', null, snapshot.status_message ?? ''));
        header.append(title);
        header.append(element('span', 'gf-ai-run-card__version', `#${String(snapshot.id).slice(0, 8)} · v${snapshot.version}`));
        card.append(header);
        const activity = renderRunActivity(snapshot.state, snapshot.status_message);
        if (activity) card.append(activity);

        if (snapshot.resolution_score !== null && snapshot.resolution_score !== undefined) {
            card.append(element('div', 'gf-ai-resolution-score', `${labels.intentConfidence} ${(Number(snapshot.resolution_score) * 100).toFixed(0)}%`));
        }
        if (snapshot.answer) {
            const answer = element('div', 'gf-ai-run-answer');
            String(snapshot.answer).split('\n').forEach((line) => answer.append(element('p', null, line || ' ')));
            card.append(answer);
        }
        if (snapshot.system_operations_executed === false && terminalStates.has(snapshot.state)) {
            card.append(element('div', 'gf-ai-no-operation', labels.systemNotExecuted));
        }

        if (snapshot.steps?.length) {
            const plan = element('section', 'gf-ai-plan');
            const planHead = element('div', 'gf-ai-plan__head');
            planHead.append(element('h2', null, labels.planTitle));
            planHead.append(element('span', null, String(labels.planVersion).replace(':version', snapshot.plan_version)));
            plan.append(planHead);
            const list = element('ol', 'gf-ai-plan__steps');
            snapshot.steps.forEach((step) => list.append(renderStep(step, snapshot.state, snapshot.payload_pruned)));
            plan.append(list);
            if (!snapshot.payload_pruned
                && ['awaiting_approval', 'awaiting_step_approval', 'failed'].includes(snapshot.state)
                && !snapshot.steps.some((step) => step.state === 'completed')) {
                const edit = element('button', 'gf-button gf-button--quiet', labels.editPlan);
                edit.type = 'button';
                edit.dataset.editPlan = snapshot.id;
                plan.append(edit, renderPlanEditor(snapshot));
                plan.querySelector('[data-plan-editor]').hidden = true;
            }
            card.append(plan);
        }

        const pendingApprovals = (snapshot.approvals ?? []).filter((approval) => approval.status === 'pending');
        pendingApprovals.forEach((approval) => {
            const approvalCard = element('section', 'gf-ai-approval');
            approvalCard.append(element('strong', null, labels.statuses?.awaiting_approval ?? 'Awaiting approval'));
            approvalCard.append(element('small', null, formatDate(approval.expires_at)));
            const actions = element('div', 'gf-ai-card__actions');
            const reject = element('button', 'gf-button gf-button--quiet', labels.reject);
            reject.type = 'button';
            reject.dataset.rejectApproval = approval.id;
            const approve = element('button', 'gf-button gf-button--primary', labels.approve);
            approve.type = 'button';
            approve.dataset.approveApproval = approval.id;
            actions.append(reject, approve);
            approvalCard.append(actions);
            card.append(approvalCard);
        });

        if (snapshot.artifacts?.length) {
            const artifacts = element('section', 'gf-ai-artifacts');
            artifacts.append(element('h2', null, labels.sources));
            snapshot.artifacts.forEach((artifact) => {
                const item = element('div', 'gf-ai-artifact');
                const copy = element('div');
                copy.append(element('strong', null, artifact.name));
                copy.append(element('p', null, artifact.content ?? ''));
                item.append(copy);
                const resultUrl = artifact.source_url ? safeResultUrl(artifact.source_url) : null;
                if (resultUrl) {
                    const link = element('a', 'gf-button gf-button--quiet', labels.openResult);
                    link.href = resultUrl;
                    if (new URL(resultUrl).origin !== window.location.origin) {
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                    }
                    item.append(link);
                }
                artifacts.append(item);
            });
            card.append(artifacts);
        }

        if (snapshot.failure?.message) card.append(element('div', 'gf-ai-run-error', snapshot.failure.message));
        if (!terminalStates.has(snapshot.state)) {
            const actions = element('div', 'gf-ai-card__actions');
            const cancel = element('button', 'gf-button gf-button--quiet', labels.cancel);
            cancel.type = 'button';
            cancel.dataset.cancelRun = snapshot.id;
            actions.append(cancel);
            card.append(actions);
        }
        runs.append(card);
        window.lucide?.createIcons?.();
        schedulePolling(snapshot);
    };

    const renderAnswerDelta = (event) => {
        if (event.conversation_id !== activeConversationId || !event.run_id || !event.delta) return;
        if (!shouldAcceptAnswerDelta(activeRun, activeAnswerStream, event)) return;
        const incomingRunSequence = Number(event.run_sequence ?? 0);
        if (!activeAnswerStream || activeAnswerStream.runId !== event.run_id
            || activeAnswerStream.runSequence !== incomingRunSequence) {
            activeAnswerStream = { runId: event.run_id, runSequence: incomingRunSequence, sequence: 0, text: '' };
        }
        const incomingSequence = Number(event.chunk_sequence ?? 0);
        if (incomingSequence <= activeAnswerStream.sequence) return;
        activeAnswerStream.sequence = incomingSequence;
        activeAnswerStream.text += String(event.delta);

        let card = runs.querySelector('[data-ai-stream-answer]');
        if (!card) {
            card = element('article', 'gf-ai-run-card is-active gf-ai-stream-answer');
            card.dataset.aiStreamAnswer = event.run_id;
            card.append(element('strong', null, labels.statuses?.answering ?? 'Answering'));
            card.append(element('p', 'gf-ai-stream-answer__text'));
            runs.replaceChildren(card);
        }
        card.querySelector('.gf-ai-stream-answer__text').textContent = activeAnswerStream.text;
    };

    const schedulePolling = (snapshot) => {
        window.clearTimeout(pollTimer);
        if (!pollingStates.has(snapshot.state)) return;
        const expectedConversationId = activeConversationId;
        const expectedGeneration = viewGeneration;
        pollTimer = window.setTimeout(async () => {
            try {
                const fresh = await request(endpoint(root.dataset.runUrlTemplate, snapshot.id));
                if (viewGeneration !== expectedGeneration
                    || activeConversationId !== expectedConversationId
                    || activeRun?.id !== snapshot.id
                    || fresh.id !== snapshot.id) return;
                clearAlert();
                renderRun(fresh);
            } catch (error) {
                if (viewGeneration !== expectedGeneration || activeConversationId !== expectedConversationId) return;
                showAlert(error.message || labels.networkError);
                schedulePolling(snapshot);
            }
        }, 1500);
    };

    const openConversation = async (id) => {
        invalidateSubmission();
        const expectedGeneration = ++viewGeneration;
        clearAlert();
        const conversation = await request(endpoint(root.dataset.conversationUrlTemplate, id));
        if (viewGeneration !== expectedGeneration) return;
        activeConversationId = conversation.id;
        activeRun = null;
        messages.replaceChildren();
        runs.replaceChildren();
        conversation.messages.forEach((message) => appendMessage(message.role, message.content, message.meta));
        setThreadVisible(conversation.messages.length > 0 || conversation.runs.length > 0);
        if (conversation.runs.length) renderRun(conversation.runs[0]);
        syncConversationUrl(conversation.id);
        document.body.classList.remove('gf-sidebar-open');
        await loadHistory();
    };

    const createConversation = async (expectedGeneration) => {
        const conversation = await request(root.dataset.conversationsUrl, { method: 'POST', body: JSON.stringify({}) });
        if (viewGeneration !== expectedGeneration) return null;
        activeConversationId = conversation.id;
        syncConversationUrl(conversation.id);
        await loadHistory();
        return conversation;
    };

    const resetWorkspace = () => {
        invalidateSubmission();
        viewGeneration += 1;
        window.clearTimeout(pollTimer);
        activeConversationId = null;
        activeRun = null;
        messages.replaceChildren();
        runs.replaceChildren();
        setThreadVisible(false);
        clearAlert();
        input.value = '';
        syncConversationUrl();
        syncComposer();
        input.focus();
        loadHistory();
    };

    const submitPrompt = async () => {
        const prompt = input.value.trim();
        if (!prompt || submissionInFlight) return;
        if (!runtimeEnabled) {
            showAlert(runtimeUnavailableMessage, 'runtime');
            return;
        }
        const expectedGeneration = viewGeneration;
        const expectedSubmissionGeneration = ++submissionGeneration;
        let optimisticMessage = null;
        clearAlert();
        setSubmitting(true);
        try {
            if (!activeConversationId && !await createConversation(expectedGeneration)) return;
            const expectedConversationId = activeConversationId;
            if (submissionGeneration !== expectedSubmissionGeneration) return;
            setThreadVisible(true);
            optimisticMessage = appendMessage('user', prompt, { pending: true });
            renderSubmissionPending();
            input.value = '';
            syncComposer();
            const snapshot = await request(endpoint(root.dataset.messageUrlTemplate, activeConversationId), {
                method: 'POST',
                body: JSON.stringify({ prompt, request_key: requestKey() }),
            });
            if (submissionGeneration === expectedSubmissionGeneration
                && isSubmissionCurrent(viewGeneration, expectedGeneration, activeConversationId, expectedConversationId)) {
                optimisticMessage.classList.remove('is-pending');
                optimisticMessage.removeAttribute('aria-label');
                renderRun(snapshot, { allowRunSwitch: true });
            }
            await loadHistory();
        } catch (error) {
            if (submissionGeneration === expectedSubmissionGeneration && viewGeneration === expectedGeneration) {
                optimisticMessage?.remove();
                runs.querySelector('[data-ai-submission-pending]')?.remove();
                if (!input.value.trim()) input.value = prompt;
                syncComposer();
                showAlert(error.message);
            }
        } finally {
            if (submissionGeneration === expectedSubmissionGeneration) {
                setSubmitting(false);
                input.focus();
            }
        }
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitPrompt();
    });
    errorReload?.addEventListener('click', () => window.location.reload());
    input?.addEventListener('keydown', (event) => {
        if (shouldSubmitPrompt(event)) {
            event.preventDefault();
            if (!submissionInFlight) form.requestSubmit();
        }
    });
    input?.addEventListener('input', syncComposer);
    root.querySelectorAll('[data-ai-suggestion]').forEach((button) => button.addEventListener('click', () => {
        input.value = button.dataset.aiSuggestion;
        root.querySelectorAll('[data-ai-suggestion]').forEach((item) => item.classList.toggle('is-active', item === button));
        syncComposer();
        input.focus();
    }));
    document.querySelectorAll('[data-ai-new]').forEach((button) => button.addEventListener('click', resetWorkspace));

    historyList?.addEventListener('click', async (event) => {
        const open = event.target.closest('[data-conversation-id]');
        const archive = event.target.closest('[data-archive-conversation]');
        if (open || archive) event.preventDefault();
        try {
            if (open) await openConversation(open.dataset.conversationId);
            if (archive) {
                await request(`${endpoint(root.dataset.conversationUrlTemplate, archive.dataset.archiveConversation)}/archive`, { method: 'POST', body: '{}' });
                if (activeConversationId === archive.dataset.archiveConversation) resetWorkspace();
                else await loadHistory();
            }
        } catch (error) {
            showAlert(error.message);
        }
    });

    runs?.addEventListener('click', async (event) => {
        const approve = event.target.closest('[data-approve-approval]');
        const reject = event.target.closest('[data-reject-approval]');
        const cancel = event.target.closest('[data-cancel-run]');
        const retry = event.target.closest('[data-retry-step]');
        const edit = event.target.closest('[data-edit-plan]');
        if (edit) {
            const editor = runs.querySelector('[data-plan-editor]');
            editor.hidden = !editor.hidden;
            return;
        }
        const expectedGeneration = viewGeneration;
        const expectedConversationId = activeConversationId;
        const expectedRunId = activeRun?.id;
        try {
            let snapshot = null;
            if (approve) snapshot = await request(endpoint(root.dataset.approvalUrlTemplate, approve.dataset.approveApproval), { method: 'POST', body: '{}' });
            if (reject) snapshot = await request(endpoint(root.dataset.rejectUrlTemplate, reject.dataset.rejectApproval), { method: 'POST', body: '{}' });
            if (cancel) snapshot = await request(endpoint(root.dataset.cancelUrlTemplate, cancel.dataset.cancelRun), { method: 'POST', body: '{}' });
            if (retry) snapshot = await request(endpoint(root.dataset.retryUrlTemplate, retry.dataset.retryStep), { method: 'POST', body: '{}' });
            if (snapshot
                && viewGeneration === expectedGeneration
                && activeConversationId === expectedConversationId
                && snapshot.id === expectedRunId) renderRun(snapshot);
        } catch (error) {
            if (viewGeneration === expectedGeneration && activeConversationId === expectedConversationId) showAlert(error.message);
        }
    });

    runs?.addEventListener('submit', async (event) => {
        const editor = event.target.closest('[data-plan-editor]');
        if (!editor) return;
        event.preventDefault();
        const expectedGeneration = viewGeneration;
        const expectedConversationId = activeConversationId;
        const expectedRunId = activeRun?.id;
        const stepParameters = {};
        try {
            editor.querySelectorAll('[data-step-parameters]').forEach((textarea) => {
                stepParameters[textarea.dataset.stepParameters] = JSON.parse(textarea.value);
            });
            const snapshot = await request(endpoint(root.dataset.planUrlTemplate, editor.dataset.planEditor), {
                method: 'PUT',
                body: JSON.stringify({ plan_version: Number(editor.dataset.planVersion), step_parameters: stepParameters }),
            });
            if (viewGeneration === expectedGeneration
                && activeConversationId === expectedConversationId
                && snapshot.id === expectedRunId) renderRun(snapshot);
        } catch (error) {
            if (viewGeneration === expectedGeneration && activeConversationId === expectedConversationId) {
                showAlert(error instanceof SyntaxError ? labels.invalidJson : error.message);
            }
        }
    });

    syncComposer();
    const requestedConversationId = new URL(window.location.href).searchParams.get('conversation');
    loadHistory();
    if (requestedConversationId) {
        openConversation(requestedConversationId).catch((error) => {
            syncConversationUrl(null);
            showAlert(error.message);
        });
    }
    if (runtimeEnabled) {
        const adminId = Number(root.dataset.adminId);
        window.Echo?.private(`admin.ai-workspace.${adminId}`)
            .listen('.ai-workspace.run.updated', async (event) => {
                if (!shouldFetchRunUpdate(activeRun, event, activeConversationId)) return;
                const expectedConversationId = activeConversationId;
                const expectedGeneration = viewGeneration;
                try {
                    const fresh = await request(endpoint(root.dataset.runUrlTemplate, event.run_id));
                    if (viewGeneration !== expectedGeneration || activeConversationId !== expectedConversationId) return;
                    renderRun(fresh, { allowRunSwitch: !activeRun });
                } catch (error) {
                    if (viewGeneration === expectedGeneration && activeConversationId === expectedConversationId) {
                        showAlert(error.message || labels.networkError);
                    }
                }
            })
            .listen('.ai-workspace.answer.delta', renderAnswerDelta);
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeAiWorkspace);
    else initializeAiWorkspace();
}
