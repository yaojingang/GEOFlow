const assistantRoot = document.getElementById('article-create-assistant');

if (assistantRoot) {
    const messageNode = document.getElementById('article-assistant-messages');
    const messages = (() => {
        try {
            return JSON.parse(messageNode?.textContent || '{}');
        } catch {
            return {};
        }
    })();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const titleInput = document.getElementById('title');
    const keywordInput = document.getElementById('keywords');
    const contentTextarea = document.getElementById('content-textarea');
    const sourceTitleInput = document.getElementById('article-source-title-id');
    const aiGeneratedInput = document.getElementById('article-is-ai-generated');
    const titlePickerOpenButton = document.getElementById('article-title-picker-open');
    const titlePickerModal = document.getElementById('article-title-picker-modal');
    const libraryFilter = document.getElementById('article-title-library-filter');
    const usageFilter = document.getElementById('article-title-usage-filter');
    const searchInput = document.getElementById('article-title-search');
    const resultsNode = document.getElementById('article-title-picker-results');
    const loadingNode = document.getElementById('article-title-picker-loading');
    const emptyNode = document.getElementById('article-title-picker-empty');
    const errorNode = document.getElementById('article-title-picker-error');
    const summaryNode = document.getElementById('article-title-picker-summary');
    const selectionNode = document.getElementById('article-title-picker-selection');
    const applyTitleButton = document.getElementById('article-title-picker-apply');
    const previousButton = document.getElementById('article-title-picker-prev');
    const nextButton = document.getElementById('article-title-picker-next');
    const selectedTitlePanel = document.getElementById('article-selected-title');
    const selectedTitleLibrary = document.getElementById('article-selected-title-library');
    const selectedTitleMeta = document.getElementById('article-selected-title-meta');
    const selectedTitleClear = document.getElementById('article-selected-title-clear');
    const knowledgeBaseSelect = document.getElementById('article-ai-knowledge-base');
    const promptSelect = document.getElementById('article-ai-prompt');
    const modelSelect = document.getElementById('article-ai-model');
    const generateButton = document.getElementById('article-ai-generate');
    const statusRow = document.getElementById('article-ai-status-row');
    const statusIcon = document.getElementById('article-ai-status-icon');
    const statusText = document.getElementById('article-ai-status');
    const characterCount = document.getElementById('article-ai-character-count');
    const progressBar = document.getElementById('article-ai-progress');

    let titlePage = 1;
    let titleLastPage = 1;
    let selectedTitle = null;
    let appliedTitle = null;
    let titleRequestController = null;
    let searchTimer = null;
    let lastFocusedElement = null;
    let generationController = null;
    let generationContent = '';
    let editorFrame = null;

    const refreshIcons = () => {
        if (window.lucide) {
            window.lucide.createIcons();
        }
    };

    const interpolate = (template, values = {}) => Object.entries(values).reduce(
        (text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
        template || '',
    );

    const createIcon = (name, className = 'h-4 w-4') => {
        const icon = document.createElement('i');
        icon.dataset.lucide = name;
        icon.className = className;

        return icon;
    };

    const editorBridge = () => window.geoArticleEditorAssistantBridge || null;

    const getEditorValue = () => {
        const bridge = editorBridge();
        if (bridge && typeof bridge.getValue === 'function') {
            return bridge.getValue() || '';
        }

        return contentTextarea?.value || '';
    };

    const setEditorValue = (value) => {
        const markdown = String(value || '');
        const bridge = editorBridge();
        if (bridge && typeof bridge.setValue === 'function') {
            bridge.setValue(markdown);
        }
        if (contentTextarea) {
            contentTextarea.value = markdown;
        }
    };

    const scheduleEditorValue = (value) => {
        generationContent = value;
        if (editorFrame !== null) {
            return;
        }

        editorFrame = window.requestAnimationFrame(() => {
            setEditorValue(generationContent);
            editorFrame = null;
        });
    };

    const setTitlePickerState = (state, errorMessage = '') => {
        loadingNode?.classList.toggle('hidden', state !== 'loading');
        loadingNode?.classList.toggle('flex', state === 'loading');
        emptyNode?.classList.toggle('hidden', state !== 'empty');
        errorNode?.classList.toggle('hidden', state !== 'error');
        resultsNode?.classList.toggle('hidden', state !== 'ready');
        if (errorNode) {
            errorNode.textContent = errorMessage;
        }
    };

    const clearAppliedTitle = () => {
        appliedTitle = null;
        if (sourceTitleInput) {
            sourceTitleInput.value = '';
        }
        selectedTitlePanel?.classList.add('hidden');
        selectedTitlePanel?.classList.remove('flex');
    };

    const renderAppliedTitle = () => {
        if (!appliedTitle) {
            clearAppliedTitle();
            return;
        }

        sourceTitleInput.value = String(appliedTitle.id);
        selectedTitleLibrary.textContent = appliedTitle.library_name || '';
        selectedTitleMeta.textContent = appliedTitle.keyword
            ? `${messages.titleKeyword || ''}: ${appliedTitle.keyword}`
            : (messages.titleNoKeyword || '');
        selectedTitlePanel?.classList.remove('hidden');
        selectedTitlePanel?.classList.add('flex');
        refreshIcons();
    };

    const selectTitle = (item) => {
        selectedTitle = item;
        selectionNode.textContent = interpolate(messages.titleSelected, { title: item.title });
        applyTitleButton.disabled = false;
        resultsNode?.querySelectorAll('[data-title-id]').forEach((row) => {
            const active = Number(row.dataset.titleId) === Number(item.id);
            row.classList.toggle('border-blue-400', active);
            row.classList.toggle('bg-blue-50', active);
            row.classList.toggle('border-gray-200', !active);
            row.querySelector('[data-title-check]')?.classList.toggle('opacity-0', !active);
        });
    };

    const renderTitleResults = (items) => {
        resultsNode.replaceChildren();

        items.forEach((item) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.dataset.titleId = String(item.id);
            row.className = 'group flex w-full items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 text-left transition hover:border-blue-300 hover:bg-blue-50/50 focus:outline-none focus:ring-2 focus:ring-blue-500';

            const check = document.createElement('span');
            check.dataset.titleCheck = '';
            check.className = 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white opacity-0';
            check.append(createIcon('check', 'h-3.5 w-3.5'));

            const body = document.createElement('span');
            body.className = 'min-w-0 flex-1';

            const heading = document.createElement('span');
            heading.className = 'block text-sm font-semibold leading-6 text-gray-900';
            heading.textContent = item.title;

            const badges = document.createElement('span');
            badges.className = 'mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500';

            const libraryBadge = document.createElement('span');
            libraryBadge.className = 'rounded-full bg-gray-100 px-2 py-0.5 text-gray-600';
            libraryBadge.textContent = item.library_name || '';
            badges.append(libraryBadge);

            const keywordBadge = document.createElement('span');
            keywordBadge.textContent = item.keyword
                ? `${messages.titleKeyword || ''}: ${item.keyword}`
                : (messages.titleNoKeyword || '');
            badges.append(keywordBadge);

            if (item.is_ai_generated) {
                const aiBadge = document.createElement('span');
                aiBadge.className = 'rounded-full bg-violet-50 px-2 py-0.5 font-medium text-violet-700';
                aiBadge.textContent = messages.titleAi || 'AI';
                badges.append(aiBadge);
            }

            if (item.used_count > 0) {
                const usedBadge = document.createElement('span');
                usedBadge.className = 'rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700';
                usedBadge.textContent = interpolate(messages.titleUsed, { count: item.used_count });
                badges.append(usedBadge);
            }

            body.append(heading, badges);
            row.append(check, body);
            row.addEventListener('click', () => selectTitle(item));
            resultsNode.append(row);
        });

        refreshIcons();
    };

    const loadTitles = async (page = 1) => {
        titleRequestController?.abort();
        titleRequestController = new AbortController();
        selectedTitle = null;
        applyTitleButton.disabled = true;
        selectionNode.textContent = messages.titleNoSelection || '';
        titlePage = page;
        setTitlePickerState('loading');

        const url = new URL(assistantRoot.dataset.titlesUrl, window.location.origin);
        url.searchParams.set('page', String(page));
        url.searchParams.set('usage', usageFilter?.value || 'unused');
        if (libraryFilter?.value) {
            url.searchParams.set('library_id', libraryFilter.value);
        }
        if (searchInput?.value.trim()) {
            url.searchParams.set('search', searchInput.value.trim());
        }

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: titleRequestController.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload.message || messages.titleLoadFailed);
            }

            const items = Array.isArray(payload.items) ? payload.items : [];
            titlePage = Number(payload.pagination?.page || 1);
            titleLastPage = Number(payload.pagination?.last_page || 1);
            previousButton.disabled = titlePage <= 1;
            nextButton.disabled = titlePage >= titleLastPage;
            summaryNode.textContent = interpolate(messages.titleSummary, {
                total: Number(payload.pagination?.total || 0),
                page: titlePage,
                last: titleLastPage,
            });

            if (items.length === 0) {
                setTitlePickerState('empty');
                return;
            }

            renderTitleResults(items);
            setTitlePickerState('ready');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            setTitlePickerState('error', error.message || messages.titleLoadFailed);
        }
    };

    const openTitlePicker = () => {
        lastFocusedElement = document.activeElement;
        selectedTitle = null;
        applyTitleButton.disabled = true;
        selectionNode.textContent = messages.titleNoSelection || '';
        titlePickerModal?.classList.remove('hidden');
        titlePickerModal?.classList.add('flex');
        titlePickerModal?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
        searchInput?.focus();
        loadTitles(1);
    };

    const closeTitlePicker = () => {
        window.clearTimeout(searchTimer);
        searchTimer = null;
        titleRequestController?.abort();
        titlePickerModal?.classList.add('hidden');
        titlePickerModal?.classList.remove('flex');
        titlePickerModal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        lastFocusedElement?.focus?.();
    };

    titlePickerOpenButton?.addEventListener('click', openTitlePicker);
    titlePickerModal?.querySelectorAll('[data-title-picker-close]').forEach((node) => {
        node.addEventListener('click', closeTitlePicker);
    });
    libraryFilter?.addEventListener('change', () => loadTitles(1));
    usageFilter?.addEventListener('change', () => loadTitles(1));
    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => loadTitles(1), 280);
    });
    previousButton?.addEventListener('click', () => {
        if (titlePage > 1) {
            loadTitles(titlePage - 1);
        }
    });
    nextButton?.addEventListener('click', () => {
        if (titlePage < titleLastPage) {
            loadTitles(titlePage + 1);
        }
    });
    applyTitleButton?.addEventListener('click', () => {
        if (!selectedTitle) {
            return;
        }

        appliedTitle = selectedTitle;
        titleInput.value = selectedTitle.title;
        if (keywordInput && keywordInput.value.trim() === '' && selectedTitle.keyword) {
            keywordInput.value = selectedTitle.keyword;
        }
        renderAppliedTitle();
        closeTitlePicker();
        titleInput.focus();
    });
    selectedTitleClear?.addEventListener('click', clearAppliedTitle);
    titleInput?.addEventListener('input', () => {
        if (appliedTitle && titleInput.value.trim() !== appliedTitle.title.trim()) {
            clearAppliedTitle();
        }
    });

    titlePickerModal?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTitlePicker();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...titlePickerModal.querySelectorAll(
            'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        )].filter((node) => !node.closest('.hidden'));
        if (focusable.length === 0) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const saveAssistantPreference = () => {
        try {
            window.sessionStorage.setItem('geoflow.articleAssistant.knowledgeBaseId', knowledgeBaseSelect?.value || '');
            window.sessionStorage.setItem('geoflow.articleAssistant.promptId', promptSelect?.value || '');
            window.sessionStorage.setItem('geoflow.articleAssistant.modelId', modelSelect?.value || '');
        } catch {
            // 会话存储不可用时继续使用当前页面选择。
        }
    };

    const restoreAssistantPreference = () => {
        try {
            const knowledgeBaseId = window.sessionStorage.getItem('geoflow.articleAssistant.knowledgeBaseId') || '';
            const promptId = window.sessionStorage.getItem('geoflow.articleAssistant.promptId') || '';
            const modelId = window.sessionStorage.getItem('geoflow.articleAssistant.modelId') || '';
            if (knowledgeBaseSelect?.querySelector(`option[value="${CSS.escape(knowledgeBaseId)}"]`)) {
                knowledgeBaseSelect.value = knowledgeBaseId;
            }
            if (promptSelect?.querySelector(`option[value="${CSS.escape(promptId)}"]`)) {
                promptSelect.value = promptId;
            }
            if (modelSelect?.querySelector(`option[value="${CSS.escape(modelId)}"]`)) {
                modelSelect.value = modelId;
            }
        } catch {
            // 会话存储不可用时保留默认选项。
        }
    };

    const setGenerationStatus = (state, text, length = 0) => {
        statusRow?.classList.remove('hidden');
        statusText.textContent = text || '';
        characterCount.textContent = length > 0
            ? interpolate(messages.characters, { count: length })
            : '';

        statusIcon.className = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full';
        progressBar.className = 'h-full rounded-full';
        statusIcon.replaceChildren();

        if (state === 'success') {
            statusIcon.classList.add('bg-emerald-100', 'text-emerald-700');
            statusIcon.append(createIcon('check', 'h-4 w-4'));
            progressBar.classList.add('w-full', 'bg-emerald-500');
        } else if (state === 'error') {
            statusIcon.classList.add('bg-red-100', 'text-red-700');
            statusIcon.append(createIcon('circle-alert', 'h-4 w-4'));
            progressBar.classList.add('w-full', 'bg-red-400');
        } else if (state === 'stopped') {
            statusIcon.classList.add('bg-amber-100', 'text-amber-700');
            statusIcon.append(createIcon('square', 'h-3.5 w-3.5'));
            progressBar.classList.add('w-full', 'bg-amber-400');
        } else {
            statusIcon.classList.add('bg-blue-100', 'text-blue-700');
            statusIcon.append(createIcon('loader-2', 'h-4 w-4 animate-spin'));
            progressBar.classList.add('w-1/3', 'animate-pulse', 'bg-blue-500');
        }

        refreshIcons();
    };

    const setGenerating = (active) => {
        knowledgeBaseSelect.disabled = active;
        promptSelect.disabled = active;
        modelSelect.disabled = active;
        generateButton.classList.toggle('bg-blue-600', !active);
        generateButton.classList.toggle('hover:bg-blue-700', !active);
        generateButton.classList.toggle('bg-amber-500', active);
        generateButton.classList.toggle('hover:bg-amber-600', active);
        generateButton.replaceChildren(
            createIcon(active ? 'square' : 'wand-sparkles', 'mr-2 h-4 w-4'),
            document.createTextNode(active ? (messages.stopButton || '') : (messages.generateButton || '')),
        );
        refreshIcons();
    };

    const parseSseBlock = (block) => {
        const payload = block
            .split(/\r?\n/)
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).trimStart())
            .join('\n');
        if (!payload || payload === '[DONE]') {
            return payload;
        }

        try {
            return JSON.parse(payload);
        } catch {
            return null;
        }
    };

    const readGenerationStream = async (response) => {
        if (!response.body) {
            throw new Error(messages.networkFailed);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let doneReceived = false;

        while (true) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), { stream: !done });

            let separator = buffer.match(/\r?\n\r?\n/);
            while (separator && separator.index !== undefined) {
                const block = buffer.slice(0, separator.index);
                buffer = buffer.slice(separator.index + separator[0].length);
                const event = parseSseBlock(block);
                if (event === '[DONE]') {
                    doneReceived = true;
                } else if (event?.type === 'text_delta' && typeof event.delta === 'string') {
                    generationContent += event.delta;
                    aiGeneratedInput.value = '1';
                    scheduleEditorValue(generationContent);
                    setGenerationStatus('streaming', messages.streaming, generationContent.length);
                }
                separator = buffer.match(/\r?\n\r?\n/);
            }

            if (done) {
                break;
            }
        }

        if (!doneReceived) {
            throw new Error(messages.networkFailed);
        }
    };

    const startGeneration = async () => {
        if (generationController) {
            generationController.abort();
            return;
        }

        const title = titleInput?.value.trim() || '';
        if (!title) {
            titleInput?.focus();
            editorBridge()?.tip?.(messages.titleRequired);
            return;
        }
        if (!knowledgeBaseSelect?.value) {
            knowledgeBaseSelect?.focus();
            editorBridge()?.tip?.(messages.knowledgeRequired);
            return;
        }
        if (!promptSelect?.value) {
            promptSelect?.focus();
            editorBridge()?.tip?.(messages.promptRequired);
            return;
        }
        if (!modelSelect?.value) {
            modelSelect?.focus();
            editorBridge()?.tip?.(messages.modelRequired);
            return;
        }

        const currentContent = getEditorValue();
        if (currentContent.trim() !== '' && !window.confirm(messages.replaceConfirm || '')) {
            return;
        }

        saveAssistantPreference();
        generationController = new AbortController();
        generationContent = '';
        setGenerating(true);
        setGenerationStatus('loading', messages.preparing, 0);

        try {
            const response = await fetch(assistantRoot.dataset.generateUrl, {
                method: 'POST',
                headers: {
                    Accept: 'text/event-stream, application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    title,
                    keyword: keywordInput?.value.trim() || '',
                    knowledge_base_id: Number(knowledgeBaseSelect.value),
                    prompt_id: Number(promptSelect.value),
                    ai_model_id: Number(modelSelect.value),
                }),
                signal: generationController.signal,
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload.message || messages.failed);
            }

            await readGenerationStream(response);
            if (generationContent.trim() === '') {
                throw new Error(messages.emptyContent || messages.failed);
            }
            if (editorFrame !== null) {
                window.cancelAnimationFrame(editorFrame);
                editorFrame = null;
            }
            setEditorValue(generationContent);
            setGenerationStatus('success', messages.completed, generationContent.length);
        } catch (error) {
            if (error.name === 'AbortError') {
                if (editorFrame !== null) {
                    window.cancelAnimationFrame(editorFrame);
                    editorFrame = null;
                }
                setEditorValue(generationContent || currentContent);
                setGenerationStatus('stopped', messages.stopped, generationContent.length);
            } else {
                if (editorFrame !== null) {
                    window.cancelAnimationFrame(editorFrame);
                    editorFrame = null;
                }
                setEditorValue(generationContent || currentContent);
                setGenerationStatus('error', error.message || messages.failed, generationContent.length);
            }
        } finally {
            generationController = null;
            setGenerating(false);
        }
    };

    knowledgeBaseSelect?.addEventListener('change', saveAssistantPreference);
    promptSelect?.addEventListener('change', saveAssistantPreference);
    modelSelect?.addEventListener('change', saveAssistantPreference);
    generateButton?.addEventListener('click', startGeneration);
    window.addEventListener('beforeunload', (event) => {
        if (!generationController) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    restoreAssistantPreference();
    refreshIcons();
}
