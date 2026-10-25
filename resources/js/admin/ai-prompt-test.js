import DOMPurify from 'dompurify';

export const normalizePrompt = (value) => value.replace(/\r\n?/g, '\n');

// Offsets sent by the server use UTF-16, matching textarea selection ranges.
export function resolvePromptAnchor(current, snapshot, anchor) {
    current = normalizePrompt(current);
    snapshot = normalizePrompt(snapshot);
    if (!anchor?.quote || snapshot.slice(anchor.start, anchor.end) !== anchor.quote) return null;
    if (current === snapshot) return { start: anchor.start, end: anchor.end };
    const matches = [];
    let offset = current.indexOf(anchor.quote);
    while (offset !== -1) {
        const end = offset + anchor.quote.length;
        const before = current.slice(0, offset);
        const after = current.slice(end);
        if ((anchor.before ? before.endsWith(anchor.before) : offset === 0)
            && (anchor.after ? after.startsWith(anchor.after) : end === current.length)) {
            matches.push({ start: offset, end });
        }
        offset = current.indexOf(anchor.quote, offset + 1);
    }
    return matches.length === 1 ? matches[0] : null;
}

// Keep incomplete lines between network chunks, including a split CRLF delimiter.
export function createEventDecoder(onEvent) {
    let buffer = '';
    let data = [];
    function line(value) {
        if (!value) {
            if (data.length) onEvent(JSON.parse(data.join('\n')));
            data = [];
        } else if (value.startsWith('data:')) {
            data.push(value.slice(5).replace(/^ /, ''));
        }
    }
    return {
        push(chunk) {
            buffer += chunk;
            let end;
            while ((end = buffer.indexOf('\n')) !== -1) {
                line(buffer.slice(0, end).replace(/\r$/, ''));
                buffer = buffer.slice(end + 1);
            }
        },
        finish() {
            if (buffer.trim() || data.length) throw new Error('incomplete_event');
        },
    };
}

// Bind each deadline to its own request, even after a newer run starts.
export function abortAfter(controller, timeoutMs) {
    return setTimeout(() => controller.abort(new DOMException('Request timed out', 'TimeoutError')), timeoutMs);
}

// Bound the whole response, including a stalled body after headers arrive.
export async function requestJson(url, options = {}, timeoutMs = 15000) {
    const controller = new AbortController();
    const abort = () => controller.abort(options.signal?.reason);
    if (options.signal?.aborted) abort();
    else options.signal?.addEventListener('abort', abort, { once: true });
    const timer = abortAfter(controller, timeoutMs);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        let data;
        try { data = await response.json(); }
        catch (error) {
            if (controller.signal.aborted || response.ok) throw error;
            data = {};
        }
        if (!response.ok) {
            const error = new Error(data.message || 'request_failed');
            error.status = response.status;
            error.fields = data.errors;
            throw error;
        }
        return data;
    } finally {
        clearTimeout(timer);
        options.signal?.removeEventListener('abort', abort);
    }
}

export const isRunProcessing = (run) => run?.processing ?? ['retrieving', 'generating', 'diagnosing'].includes(run?.status);
export const isTerminalStatusError = (status) => [401, 403, 404, 410, 419, 422].includes(status);
export const canStartDiagnosis = (run, { busy = false, stopped = false, automatic = false } = {}) => Boolean(run)
    && !busy && !run.cancel_requested && !(automatic && stopped);

export async function pollRunStatus({ read, isCurrent, onValue, onError, retry }) {
    try {
        const value = await read();
        if (isCurrent()) onValue(value);
    } catch (error) {
        if (!isCurrent()) return;
        onError(error);
        if (!isTerminalStatusError(error.status)) retry();
    }
}

function init(root) {
    const labels = JSON.parse(root.querySelector('[data-prompt-test-labels]').textContent);
    const $ = (key) => root.querySelector(`[data-${key}]`);
    const editor = document.getElementById('prompt_content');
    const openButton = document.querySelector('[data-prompt-test-open]');
    if (!editor || !openButton) return;
    const config = $('test-config');
    const pickers = [$('knowledge-picker'), $('title-picker')];
    const selectedKnowledge = new Map();
    let selectedTitle = null;
    let knowledgeItems = [];
    let titleItems = [];
    let knowledgePage = 1;
    let titlePage = 1;
    let knowledgeSequence = 0;
    let titleSequence = 0;
    let models = [];
    let run = null;
    let busy = false;
    let uncertain = false;
    let stopped = false;
    let cancelInFlight = false;
    let configured = false;
    let configLoading = false;
    let controller = null;
    let pollTimer = null;
    let pollCount = 0;
    let epoch = 0;
    let recoveringId = null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const element = (tag, text = '', className = '') => {
        const node = document.createElement(tag);
        node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const setText = (key, text) => { $(key).textContent = text ?? ''; };
    const showError = (key, text) => { setText(key, text); $(key).hidden = !text; };
    const runUrl = (id = run?.id) => root.dataset.runUrl.replace('__RUN__', encodeURIComponent(id));
    const configError = (error) => showError('config-error', error.status ? error.message : labels.network);
    const debounce = (callback) => {
        let timer;
        return () => { clearTimeout(timer); timer = setTimeout(callback, 250); };
    };
    function request(url, body, signal, timeoutMs = 15000) {
        return requestJson(url, {
            method: body ? 'POST' : 'GET', credentials: 'same-origin', signal,
            headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}) },
            ...(body ? { body: JSON.stringify(body) } : {}),
        }, timeoutMs);
    }
    function renderStatus() {
        if (!run) return;
        let text = stopped && !run.cancel_requested && isRunProcessing(run) ? labels.stopping
            : run.cancel_requested && isRunProcessing(run) ? labels.cleanup
            : run.status === 'generating' ? (labels.phases[run.phase] || labels.states.generating)
            : labels.states[run.status];
        if (isRunProcessing(run)) {
            const seconds = Math.max(0, Math.floor((Date.now() - new Date(run.created_at).getTime()) / 1000));
            text += ` · ${labels.elapsed.replace(':seconds', String(seconds))}`;
        }
        setText('status', text);
    }
    const elapsedTimer = setInterval(renderStatus, 1000);
    window.addEventListener('pagehide', () => { clearInterval(elapsedTimer); clearTimeout(pollTimer); controller?.abort(); }, { once: true });
    function setBusy(value) {
        busy = value;
        for (const button of [openButton, $('adjust'), $('again'), $('start'), $('retry')]) button.disabled = value;
        $('config-fields').disabled = value || configLoading || !configured;
        $('start').disabled = value || configLoading || !configured;
        $('reload-config').disabled = value || configLoading;
        for (const picker of pickers) picker.querySelector('summary').setAttribute('aria-disabled', String(value || configLoading || !configured));
        $('stop').hidden = !value || !run || (recoveringId !== null && recoveringId !== run.id);
        $('stop').disabled = cancelInFlight || Boolean(run?.cancel_requested);
    }
    function sourceChanged() {
        $('run-changed').hidden = !run || normalizePrompt(editor.value) === run.prompt_content;
        lineLabel.textContent = `${labels.line} ${normalizePrompt(editor.value.slice(0, editor.selectionStart)).split('\n').length}`;
    }
    const lineLabel = element('p', '', 'prompt-test-line');
    editor.after(lineLabel);
    editor.addEventListener('input', sourceChanged);
    editor.addEventListener('select', sourceChanged);
    editor.addEventListener('keyup', sourceChanged);
    editor.addEventListener('click', sourceChanged);
    sourceChanged();

    function renderKnowledge() {
        const list = $('knowledge-list');
        list.replaceChildren();
        if (!knowledgeItems.length) list.append(element('p', labels.empty, 'prompt-test-muted'));
        for (const item of knowledgeItems) {
            const label = element('label');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = selectedKnowledge.has(item.id);
            input.disabled = !input.checked && selectedKnowledge.size >= 5;
            input.addEventListener('change', () => {
                if (input.checked) selectedKnowledge.set(item.id, item);
                else selectedKnowledge.delete(item.id);
                renderKnowledge();
            });
            label.append(input, element('span', item.name));
            list.append(label);
        }
        $('selected-knowledge').replaceChildren();
        setText('knowledge-summary', selectedKnowledge.size ? labels.knowledge_count.replace(':count', String(selectedKnowledge.size)) : labels.select_knowledge_short);
        for (const item of selectedKnowledge.values()) {
            const chip = element('button', `${item.name} ×`);
            chip.type = 'button';
            chip.setAttribute('aria-label', `${labels.cancel} ${item.name}`);
            chip.addEventListener('click', () => { selectedKnowledge.delete(item.id); renderKnowledge(); });
            $('selected-knowledge').append(chip);
        }
    }
    async function loadKnowledge(page = 1) {
        const sequence = ++knowledgeSequence;
        const url = new URL(root.dataset.optionsUrl, location.href);
        url.search = new URLSearchParams({ kind: 'knowledge', q: $('knowledge-search').value, page });
        try {
            const data = await request(url);
            if (sequence !== knowledgeSequence) return;
            knowledgeItems = page === 1 ? data.knowledge_bases : [...knowledgeItems, ...data.knowledge_bases];
            knowledgePage = data.page;
            $('more-knowledge').hidden = data.page >= data.last_page;
            renderKnowledge();
        } catch (error) { if (sequence === knowledgeSequence) configError(error); }
    }
    function renderTitles() {
        const list = $('title-list');
        list.replaceChildren();
        if (!titleItems.length) list.append(element('p', labels.empty, 'prompt-test-muted'));
        for (const item of titleItems) {
            const label = element('label');
            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'test_title';
            input.checked = selectedTitle?.id === item.id;
            input.addEventListener('change', () => {
                selectedTitle = item;
                $('keyword').value = item.keyword || '';
                setText('selected-title', `${labels.selected}：${item.title}`);
                setText('title-summary', labels.title_selected);
                $('title-picker').open = false;
                $('title-picker').querySelector('summary').focus();
            });
            label.append(input, element('span', item.title));
            list.append(label);
        }
    }
    async function loadTitles(page = 1) {
        const sequence = ++titleSequence;
        if (!$('title-library').value) {
            titleItems = [];
            renderTitles();
            $('more-titles').hidden = true;
            return;
        }
        const url = new URL(root.dataset.optionsUrl, location.href);
        url.search = new URLSearchParams({ kind: 'titles', library_id: $('title-library').value, q: $('title-search').value, page });
        try {
            const data = await request(url);
            if (sequence !== titleSequence) return;
            titleItems = page === 1 ? data.items : [...titleItems, ...data.items];
            titlePage = data.page;
            $('more-titles').hidden = data.page >= data.last_page;
            renderTitles();
        } catch (error) { if (sequence === titleSequence) configError(error); }
    }
    function fillModels(select, placeholder) {
        const value = select.value;
        select.replaceChildren();
        if (placeholder) select.append(new Option(placeholder, ''));
        for (const model of models) select.append(new Option(model.name, model.id));
        if (models.some((item) => String(item.id) === value)) select.value = value;
    }
    async function loadConfig() {
        if (busy || configLoading) return;
        configLoading = true;
        $('config-loading').hidden = false;
        $('reload-config').hidden = true;
        setBusy(busy);
        showError('config-error', '');
        try {
            const data = await request(root.dataset.optionsUrl);
            models = data.models;
            fillModels($('generation-model'), labels.choose_model);
            fillModels($('diagnostic-model'), labels.same_model);
            fillModels($('retry-model'));
            if (!$('generation-model').value) $('generation-model').value = data.default_model_id || '';
            const library = $('title-library').value;
            $('title-library').replaceChildren(new Option(labels.choose_library, ''));
            for (const item of data.title_libraries) $('title-library').append(new Option(item.name, item.id));
            $('title-library').value = library;
            $('title-search').disabled = !$('title-library').value;
            if (!configured) {
                knowledgeItems = data.knowledge_bases;
                knowledgePage = data.page;
                $('more-knowledge').hidden = data.page >= data.last_page;
                renderKnowledge();
            } else await loadKnowledge();
            configured = true;
        } catch (error) {
            showError('config-error', error.status ? error.message : labels.options_failed);
            $('reload-config').hidden = false;
        } finally {
            configLoading = false;
            $('config-loading').hidden = true;
            setBusy(busy);
        }
    }
    function openConfig() {
        if (busy) return;
        config.scrollIntoView({ block: 'center', behavior: 'instant' });
        $('knowledge-picker').querySelector('summary').focus({ preventScroll: true });
        if (!configured) loadConfig();
    }
    openButton.addEventListener('click', openConfig);
    $('adjust').addEventListener('click', openConfig);
    $('reload-config').addEventListener('click', loadConfig);
    for (const picker of pickers) {
        picker.querySelector('summary').addEventListener('click', (event) => {
            if (busy || configLoading || !configured) event.preventDefault();
        });
        picker.addEventListener('toggle', () => {
            if (picker.open) {
                for (const other of pickers) if (other !== picker) other.open = false;
                const bounds = picker.querySelector('summary').getBoundingClientRect();
                const panelHeight = picker.querySelector('.prompt-test-picker-panel').offsetHeight;
                picker.classList.toggle('opens-up', window.innerHeight - bounds.bottom < panelHeight + 8 && bounds.top > panelHeight + 8);
            }
        });
        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && picker.open) {
                event.preventDefault();
                picker.open = false;
                picker.querySelector('summary').focus();
            }
        });
    }
    document.addEventListener('click', (event) => {
        for (const picker of pickers) if (!picker.contains(event.target)) picker.open = false;
    });
    $('close-snapshot').addEventListener('click', () => $('snapshot-dialog').close());
    $('knowledge-search').addEventListener('input', debounce(() => loadKnowledge()));
    $('title-search').addEventListener('input', debounce(() => loadTitles()));
    $('more-knowledge').addEventListener('click', () => loadKnowledge(knowledgePage + 1));
    $('more-titles').addEventListener('click', () => loadTitles(titlePage + 1));
    $('title-library').addEventListener('change', () => {
        selectedTitle = null;
        setText('selected-title', '');
        setText('title-summary', labels.choose_title);
        $('title-search').value = '';
        $('title-search').disabled = !$('title-library').value;
        loadTitles();
    });
    root.querySelectorAll('[name=title_source]').forEach((input) => input.addEventListener('change', () => {
        const manual = input.value === 'manual';
        $('manual-fields').hidden = !manual;
        $('library-fields').hidden = manual;
        $('selected-title').hidden = manual;
        $('title-picker').open = false;
    }));

    function applyRun(value) {
        run = value;
        $('test-result').hidden = false;
        setText('article-title', run.title);
        setText('article-source', run.content);
        $('article-preview').innerHTML = DOMPurify.sanitize(run.html || '');
        setText('run-meta', [run.generation_model?.name, run.diagnostic_model?.name ? `${labels.diagnostic_model}：${run.diagnostic_model.name}` : '', ...(run.knowledge_bases || []).map((item) => item.name), new Date(run.created_at).toLocaleString()].filter(Boolean).join(' · '));
        renderStatus();
        showError('run-error', run.message);
        sourceChanged();
        renderDiagnosis();
    }
    async function copy(value) {
        try { await navigator.clipboard.writeText(value); setText('action-notice', labels.copied); }
        catch { setText('action-notice', labels.copy_failed); }
    }
    $('copy-article').addEventListener('click', () => copy(run?.content || ''));
    function switchView(source) {
        $('article-preview').hidden = source;
        $('article-source').hidden = !source;
        $('show-source').setAttribute('aria-pressed', String(source));
        $('show-preview').setAttribute('aria-pressed', String(!source));
    }
    $('show-source').addEventListener('click', () => switchView(true));
    $('show-preview').addEventListener('click', () => switchView(false));

    function selectRange(textarea, range) {
        textarea.focus({ preventScroll: true });
        textarea.setSelectionRange(range.start, range.end);
        // Measure wrapped text with the textarea's actual typography and width.
        const mirror = element('div');
        const style = getComputedStyle(textarea);
        for (const property of ['font', 'letterSpacing', 'lineHeight', 'padding', 'boxSizing', 'wordBreak', 'tabSize']) mirror.style[property] = style[property];
        Object.assign(mirror.style, { position: 'absolute', visibility: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', width: `${textarea.clientWidth}px` });
        mirror.textContent = textarea.value.slice(0, range.start);
        const marker = element('span', '|');
        mirror.append(marker);
        document.body.append(mirror);
        textarea.scrollTop = Math.max(0, marker.offsetTop - textarea.clientHeight / 3);
        mirror.remove();
        textarea.scrollIntoView({ block: 'center', behavior: 'instant' });
    }
    function locate(anchor) {
        const range = resolvePromptAnchor(editor.value, run.prompt_content, anchor);
        if (range) {
            selectRange(editor, range);
            setText('action-notice', labels.located);
            sourceChanged();
        } else {
            $('snapshot').value = run.prompt_content;
            $('snapshot-dialog').showModal();
            selectRange($('snapshot'), { start: anchor.start, end: anchor.end });
        }
    }
    function renderDiagnosis() {
        const result = run?.diagnosis;
        $('diagnosis').hidden = !result && !['diagnosing', 'diagnosis_failed', 'article_ready'].includes(run?.status);
        $('diagnosis-results').replaceChildren();
        setText('diagnosis-version', result?.version || '');
        setText('diagnosis-status', run.status === 'diagnosing' ? labels.states.diagnosing : run.status === 'diagnosis_failed' ? run.message : '');
        const retryable = !run.cancel_requested && ['article_ready', 'diagnosis_failed'].includes(run.status);
        $('diagnosis-retry').hidden = run.cancel_requested || (!retryable && !result?.unresolved_suggestions);
        if (run.diagnostic_model?.id) $('retry-model').value = run.diagnostic_model.id;
        if (!result) return;
        const container = $('diagnosis-results');
        const scores = element('div', '', 'prompt-test-scores');
        const entries = [['overall', result.overall, null], ...Object.entries(result.dimensions).map(([key, value]) => [key, value.score, value])];
        for (const [key, score, dimension] of entries) {
            const block = element('div', '', 'prompt-test-score');
            block.append(element('span', key === 'overall' ? labels.overall : labels.dimensions[key], 'prompt-test-muted'));
            block.append(element('strong', score === null ? labels.pending_evidence : `${score} / 100`, score === null ? 'is-unknown' : ''));
            block.append(element('span', dimension ? `${labels.coverage} ${dimension.assessed} / ${dimension.total}` : '30% · 30% · 40%', 'prompt-test-muted'));
            scores.append(block);
        }
        container.append(scores);
        for (const [key, dimension] of Object.entries(result.dimensions)) {
            const details = element('details', '', 'prompt-test-criteria');
            details.append(element('summary', labels.dimensions[key]));
            for (const criterion of dimension.criteria) {
                const item = element('div', '', 'prompt-test-criterion');
                item.append(element('strong', `${labels.criteria[criterion.id]} · ${criterion.level === null ? labels.pending_evidence : `${criterion.level} / 4`}`));
                item.append(element('p', criterion.reason));
                item.append(element('p', criterion.quote ? `${labels.article_evidence}：${criterion.quote}` : `${labels.scope}：${criterion.scope}`, 'prompt-test-quote'));
                details.append(item);
            }
            container.append(details);
        }
        container.append(element('h3', labels.suggestions, 'prompt-test-suggestions-title'));
        if (result.unresolved_suggestions) container.append(element('p', labels.unresolved, 'prompt-test-error'));
        if (!result.suggestions.length && !result.unresolved_suggestions) container.append(element('p', labels.no_suggestions, 'prompt-test-muted'));
        for (const suggestion of result.suggestions) {
            const item = element('section', '', 'prompt-test-suggestion');
            const priority = element('span', `${labels.priorities[suggestion.priority]} · ${labels.dimensions[suggestion.dimension]}`, 'prompt-test-priority');
            priority.dataset.priority = suggestion.priority;
            item.append(priority, element('h4', suggestion.problem));
            const fields = element('dl');
            const field = (label, value, quote = false) => {
                if (!value) return;
                fields.append(element('dt', label), element('dd', value, quote ? 'prompt-test-quote' : ''));
            };
            field(suggestion.article_quote ? labels.article_evidence : labels.scope, suggestion.article_quote || suggestion.article_scope, true);
            field(`${labels.prompt_location} · ${labels.line} ${suggestion.anchor.line}${suggestion.anchor.section ? ` · ${suggestion.anchor.section}` : ''}`, suggestion.anchor.quote, true);
            field(suggestion.anchor.action === 'insert_after' ? labels.insert_after : labels.replacement, suggestion.replacement, true);
            field(labels.reason, suggestion.reason);
            field(labels.expected, suggestion.expected_improvement);
            field(labels.needed, suggestion.needed_evidence);
            const actions = element('div', '', 'prompt-test-actions');
            const locateButton = element('button', labels.locate, 'prompt-test-button');
            locateButton.type = 'button';
            locateButton.addEventListener('click', () => locate(suggestion.anchor));
            const copyButton = element('button', labels.copy_suggestion, 'prompt-test-text-button');
            copyButton.type = 'button';
            copyButton.addEventListener('click', () => copy(suggestion.replacement));
            actions.append(locateButton, copyButton);
            item.append(fields, actions);
            container.append(item);
        }
    }

    async function diagnose() {
        if (!canStartDiagnosis(run, { busy })) return;
        const operationEpoch = epoch;
        const diagnosticModelId = Number($('retry-model').value || run.diagnostic_model.id);
        setBusy(true);
        run.status = 'diagnosing';
        run.processing = true;
        run.message = null;
        run.error_code = null;
        applyRun(run);
        try {
            const response = await request(`${runUrl()}/diagnose`, {
                request_id: crypto.randomUUID(),
                diagnostic_model_id: diagnosticModelId,
            }, undefined, 190000);
            if (epoch !== operationEpoch) return;
            applyRun(response);
            uncertain = false;
        } catch (error) {
            if (epoch !== operationEpoch) return;
            uncertain = true;
            showError('run-error', error.status ? error.message : labels.network);
            $('refresh').hidden = false;
            scheduleRefresh();
        } finally { if (epoch === operationEpoch) setBusy(uncertain); }
    }
    function scheduleRefresh() {
        clearTimeout(pollTimer);
        if (pollCount++ < 90) pollTimer = setTimeout(() => refreshStatus(), 5000);
    }
    async function refreshStatus() {
        const requestedId = recoveringId || run?.id;
        if (!requestedId) return;
        const operationEpoch = epoch;
        await pollRunStatus({
            read: () => request(runUrl(requestedId)),
            isCurrent: () => epoch === operationEpoch && (recoveringId || run?.id) === requestedId,
            onValue: (response) => {
                recoveringId = null;
                applyRun(response);
                uncertain = isRunProcessing(run);
                setBusy(uncertain);
                $('refresh').hidden = !uncertain;
                if (uncertain) scheduleRefresh();
                else clearTimeout(pollTimer);
            },
            onError: (error) => {
                showError('run-error', error.status ? error.message : labels.network);
                if (isTerminalStatusError(error.status)) {
                    uncertain = false;
                    recoveringId = null;
                    setBusy(false);
                    clearTimeout(pollTimer);
                }
                $('refresh').hidden = false;
            },
            retry: scheduleRefresh,
        });
    }
    $('refresh').addEventListener('click', refreshStatus);
    $('retry').addEventListener('click', diagnose);
    $('stop').addEventListener('click', async () => {
        if (!run || cancelInFlight || run.cancel_requested) return;
        const cancellingId = run.id;
        const operationEpoch = epoch;
        stopped = true;
        cancelInFlight = true;
        $('stop').disabled = true;
        renderStatus();
        try {
            const current = await request(`${runUrl(cancellingId)}/cancel`, {}, undefined, 10000);
            if (epoch !== operationEpoch || run?.id !== cancellingId) return;
            controller?.abort();
            applyRun(current);
            uncertain = isRunProcessing(current);
            setBusy(uncertain);
            $('refresh').hidden = !uncertain;
            if (uncertain) scheduleRefresh();
        } catch (error) {
            if (epoch !== operationEpoch || run?.id !== cancellingId) return;
            showError('run-error', error.status ? error.message : labels.network);
            $('refresh').hidden = false;
            scheduleRefresh();
        } finally {
            if (epoch === operationEpoch) {
                cancelInFlight = false;
                $('stop').disabled = Boolean(run?.cancel_requested);
            }
        }
    });
    async function generate() {
        if (busy) return;
        showError('config-error', '');
        root.querySelectorAll('[data-field-error]').forEach((node) => { node.textContent = ''; });
        const source = root.querySelector('[name=title_source]:checked').value;
        const payload = {
            request_id: crypto.randomUUID(), prompt_content: normalizePrompt(editor.value),
            knowledge_base_ids: [...selectedKnowledge.keys()], title_source: source,
            title: source === 'manual' ? $('manual-title').value.trim() : null,
            title_id: source === 'library' ? selectedTitle?.id : null,
            keyword: $('keyword').value,
            ai_model_id: Number($('generation-model').value),
            diagnostic_model_id: $('diagnostic-model').value ? Number($('diagnostic-model').value) : null,
        };
        let validation = '';
        if (!payload.prompt_content.trim()) validation = labels.enter_prompt;
        else if (!payload.knowledge_base_ids.length || payload.knowledge_base_ids.length > 5) validation = labels.select_knowledge;
        else if (source === 'library' ? !payload.title_id : !payload.title) validation = labels.choose_title;
        else if (!payload.ai_model_id) validation = labels.choose_model;
        if (validation) {
            openConfig();
            showError('config-error', validation);
            return;
        }
        const operationEpoch = ++epoch;
        recoveringId = payload.request_id;
        stopped = false;
        cancelInFlight = false;
        for (const picker of pickers) picker.open = false;
        setBusy(true);
        // The previous result stays visible until the new run is acknowledged.
        $('stop').hidden = true;
        uncertain = false;
        pollCount = 0;
        clearTimeout(pollTimer);
        const streamController = new AbortController();
        controller = streamController;
        const streamTimeout = abortAfter(streamController, 390000);
        let accepted = false;
        let terminal = false;
        let ready = false;
        try {
            const response = await fetch(root.dataset.generateUrl, {
                method: 'POST', credentials: 'same-origin', signal: streamController.signal,
                headers: { Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const error = new Error(data.message || labels.network);
                error.status = response.status;
                error.fields = data.errors;
                throw error;
            }
            accepted = true;
            const parser = createEventDecoder((event) => {
                if (epoch !== operationEpoch) return;
                if (event.type === 'started') {
                    recoveringId = null;
                    applyRun(event.run);
                    $('refresh').hidden = true;
                    setText('action-notice', '');
                    setBusy(true);
                    $('test-result').scrollIntoView({ block: 'start', behavior: 'instant' });
                } else if (event.type === 'stage' && run) {
                    run.status = event.status;
                    run.phase = event.phase;
                    run.processing = true;
                    renderStatus();
                } else if (event.type === 'delta' && run) {
                    run.content += event.text;
                    setText('article-source', run.content);
                } else if (event.type === 'preview' && run) {
                    run.html = event.html;
                    $('article-preview').innerHTML = DOMPurify.sanitize(event.html);
                } else if (event.type === 'article_complete' || event.type === 'error') {
                    terminal = true;
                    ready = event.type === 'article_complete';
                    applyRun(event.run);
                }
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                parser.push(decoder.decode(value, { stream: true }));
            }
            parser.push(decoder.decode());
            parser.finish();
            if (!terminal) throw new Error(labels.network);
        } catch (error) {
            if (epoch !== operationEpoch) return;
            if (error.status && error.status < 500 && !accepted) {
                recoveringId = null;
                config.scrollIntoView({ block: 'center', behavior: 'instant' });
                configError(error);
                for (const [key, messages] of Object.entries(error.fields || {})) {
                    const target = root.querySelector(`[data-field-error="${key === 'title_id' ? 'title' : key}"]`);
                    if (target) target.textContent = messages[0];
                }
            } else if (stopped && run?.cancel_requested) {
                uncertain = isRunProcessing(run);
                $('refresh').hidden = !uncertain;
                if (uncertain) scheduleRefresh();
            } else {
                uncertain = true;
                if (!run) {
                    applyRun({ id: payload.request_id, prompt_content: payload.prompt_content, title: payload.title || selectedTitle?.title,
                        status: 'retrieving', content: '', html: '', created_at: new Date().toISOString() });
                }
                if (!stopped) showError('run-error', labels.network);
                $('refresh').hidden = false;
                scheduleRefresh();
            }
        } finally {
            clearTimeout(streamTimeout);
            if (epoch === operationEpoch) {
                controller = null;
                setBusy(uncertain);
            }
        }
        // Wait for the stream to close so its server-side operation lease is released.
        if (epoch === operationEpoch && ready && !uncertain && canStartDiagnosis(run, { busy, stopped, automatic: true })) await diagnose();
    }
    $('test-config').addEventListener('submit', (event) => { event.preventDefault(); generate(); });
    $('again').addEventListener('click', generate);
    loadConfig();
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-prompt-test]').forEach(init);
