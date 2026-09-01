import { createStreamingMarkdownRenderer, normalizeAnswerMarkdown, renderMarkdownInto } from './ai-workspace/markdown.js';

export function parseSseBuffer(buffer, chunk = '', flush = false) {
    const source = `${buffer ?? ''}${chunk ?? ''}`.replace(/\r\n/gu, '\n');
    const blocks = source.split('\n\n');
    const rest = flush ? '' : blocks.pop() ?? '';
    const events = [];

    blocks.forEach((block) => {
        let event = 'message';
        const data = [];
        block.split('\n').forEach((line) => {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            if (line.startsWith('data:')) data.push(line.slice(5).trimStart());
        });
        if (data.length === 0) return;
        const raw = data.join('\n');
        let payload = raw;
        try {
            payload = JSON.parse(raw);
        } catch {
            payload = raw;
        }
        events.push({ event, data: payload });
    });

    if (flush && source.trim() !== '' && blocks.length === 0) {
        return parseSseBuffer('', `${source}\n\n`, false);
    }

    return { events, rest };
}

export function createSseParser(onEvent) {
    let buffer = '';

    return {
        push(chunk) {
            const parsed = parseSseBuffer(buffer, chunk);
            buffer = parsed.rest;
            parsed.events.forEach(onEvent);
        },
        finish() {
            const parsed = parseSseBuffer(buffer, '', true);
            buffer = '';
            parsed.events.forEach(onEvent);
        },
    };
}

export function trustedFeatureUrl(value, origin, adminBasePath = '/admin') {
    try {
        const url = new URL(String(value ?? ''), origin);
        const normalizedBase = `/${String(adminBasePath ?? '/admin').replace(/^\/+|\/+$/gu, '')}`;
        if (!['http:', 'https:'].includes(url.protocol)) return null;
        if (url.origin !== origin || url.username || url.password) return null;
        if (url.pathname !== normalizedBase && !url.pathname.startsWith(`${normalizedBase}/`)) return null;

        return url.href;
    } catch {
        return null;
    }
}

export function fallbackConversationTitle(value, lowInformationTitle = '日常交流') {
    const title = String(value ?? '').replace(/\s+/gu, ' ').trim();
    const compact = title.toLocaleLowerCase().replace(/[\p{P}\p{S}\s]+/gu, '');
    const lowInformation = /^(?:(?:你+好+)|(?:您+好+)|(?:嗨+)|(?:哈+喽+)|(?:在+吗+)|(?:hello+)|(?:hi+))+$/iu;
    if (compact === '' || lowInformation.test(compact)) return lowInformationTitle;

    return Array.from(title).slice(0, 15).join('');
}

function replaceTemplate(template, id) {
    return String(template ?? '').replace('__ID__', encodeURIComponent(String(id)));
}

function csrfToken(documentRef) {
    return documentRef.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

function setupAiWorkspace(root, { documentRef = document, windowRef = window, fetcher = window.fetch.bind(window) } = {}) {
    const form = root.querySelector('[data-ai-form]');
    const input = root.querySelector('[data-ai-input]');
    const send = root.querySelector('[data-ai-send]');
    const stop = root.querySelector('[data-ai-stop]');
    const start = root.querySelector('[data-ai-start]');
    const thread = root.querySelector('[data-ai-thread]');
    const messages = root.querySelector('[data-ai-messages]');
    const threadTitle = root.querySelector('[data-ai-thread-title]');
    const jumpLatest = root.querySelector('[data-ai-jump-latest]');
    const loadEarlier = root.querySelector('[data-ai-load-earlier]');
    const rename = root.querySelector('[data-ai-rename]');
    const alert = root.querySelector('[data-ai-alert]');
    const composerError = root.querySelector('[data-ai-composer-error]');
    const showcase = root.querySelector('[data-ai-showcase]');
    const showcaseSlides = Array.from(root.querySelectorAll('[data-ai-showcase-slide]'));
    const showcaseDots = Array.from(root.querySelectorAll('[data-ai-showcase-dot]'));
    const showcasePrevious = root.querySelector('[data-ai-showcase-prev]');
    const showcaseNext = root.querySelector('[data-ai-showcase-next]');
    const labelsNode = root.querySelector('[data-ai-labels]');
    if (!form || !input || !send || !stop || !start || !thread || !messages || !threadTitle) return null;

    let labels = {};
    try {
        labels = JSON.parse(labelsNode?.textContent ?? '{}');
    } catch {
        labels = {};
    }

    const state = {
        conversationId: new URL(windowRef.location.href).searchParams.get('conversation'),
        title: '',
        generating: false,
        controller: null,
        nextCursor: null,
        hasMore: false,
        loadingEarlier: false,
        generationId: 0,
        viewId: 0,
    };
    const scrollRoot = root.closest('.gf-main') ?? documentRef.scrollingElement ?? documentRef.documentElement;
    let activeConversationLoad = null;
    let conversationLoadController = null;

    const refreshIcons = (scope = root) => windowRef.lucide?.createIcons?.({ attrs: { 'stroke-width': 1.8 }, nameAttr: 'data-lucide', root: scope });
    const scrollToLatest = (behavior = 'smooth') => scrollRoot.scrollTo({ top: scrollRoot.scrollHeight, behavior });
    const isNearBottom = () => scrollRoot.scrollHeight - scrollRoot.scrollTop - scrollRoot.clientHeight < 180;

    const announce = (message) => {
        if (!alert) return;
        alert.textContent = message;
        alert.hidden = message === '';
    };

    const showComposerError = (message = '') => {
        if (!composerError) return;
        composerError.textContent = message;
        composerError.hidden = message === '';
        input.setAttribute('aria-invalid', String(message !== ''));
    };

    const autoResize = () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(180, Math.max(28, input.scrollHeight))}px`;
    };

    const syncComposer = () => {
        const hasPrompt = input.value.trim() !== '';
        send.disabled = state.generating || !hasPrompt;
        send.hidden = state.generating;
        stop.hidden = !state.generating;
        input.setAttribute('aria-busy', String(state.generating));
    };

    let showcaseIndex = 0;
    let showcaseTimer = null;
    const prefersReducedMotion = windowRef.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
    const stopShowcase = () => {
        if (showcaseTimer === null) return;
        windowRef.clearInterval(showcaseTimer);
        showcaseTimer = null;
    };
    const showShowcaseSlide = (index, { restart = false } = {}) => {
        if (showcaseSlides.length === 0) return;
        showcaseIndex = (Number(index) + showcaseSlides.length) % showcaseSlides.length;
        showcaseSlides.forEach((slide, position) => { slide.hidden = position !== showcaseIndex; });
        showcaseDots.forEach((dot, position) => {
            if (position === showcaseIndex) dot.setAttribute('aria-current', 'true');
            else dot.removeAttribute('aria-current');
        });
        if (restart) {
            stopShowcase();
            startShowcase();
        }
    };
    const startShowcase = () => {
        if (!showcase || showcaseSlides.length < 2 || prefersReducedMotion || documentRef.hidden || start.hidden) return;
        stopShowcase();
        showcaseTimer = windowRef.setInterval(() => showShowcaseSlide(showcaseIndex + 1), 6000);
    };

    const updateLocation = (conversationId) => {
        const url = new URL(windowRef.location.href);
        if (conversationId) url.searchParams.set('conversation', conversationId);
        else url.searchParams.delete('conversation');
        windowRef.history.replaceState({}, '', url);
        windowRef.GeoFlowAdminUi?.setRecentConversationActive?.(conversationId || null);
    };

    const showThread = () => {
        start.hidden = true;
        thread.hidden = false;
        stopShowcase();
    };

    const applyConversationTitle = (value) => {
        const title = String(value ?? '').trim();
        if (title === '') return false;
        state.title = title;
        threadTitle.textContent = title;

        return true;
    };

    const showStart = () => {
        conversationLoadController?.abort();
        conversationLoadController = null;
        activeConversationLoad = null;
        state.viewId += 1;
        state.generationId += 1;
        state.generating = false;
        state.controller = null;
        start.hidden = false;
        thread.hidden = true;
        messages.replaceChildren();
        state.conversationId = null;
        state.title = '';
        state.nextCursor = null;
        state.hasMore = false;
        state.loadingEarlier = false;
        if (loadEarlier) {
            loadEarlier.hidden = true;
            loadEarlier.disabled = false;
            loadEarlier.textContent = labels.loadEarlier ?? '加载更早消息';
        }
        updateLocation(null);
        input.value = '';
        showComposerError('');
        autoResize();
        syncComposer();
        startShowcase();
        input.focus({ preventScroll: true });
    };

    const createIcon = (name) => {
        const icon = documentRef.createElement('i');
        icon.dataset.lucide = name;
        return icon;
    };

    const createAvatar = (role) => {
        const avatar = documentRef.createElement('span');
        avatar.className = `gf-ai-help__avatar gf-ai-help__avatar--${role}`;
        avatar.setAttribute('aria-hidden', 'true');
        if (role === 'assistant') avatar.append(createIcon('sparkles'));
        else avatar.textContent = String(root.dataset.userInitial ?? '').trim().slice(0, 1) || 'U';

        return avatar;
    };

    const renderFeatureLinks = (target, features) => {
        const safeFeatures = (Array.isArray(features) ? features : []).map((feature) => {
            const url = trustedFeatureUrl(feature?.url, windowRef.location.origin, root.dataset.adminBasePath);
            return url ? { ...feature, url } : null;
        }).filter(Boolean).slice(0, 3);
        if (safeFeatures.length === 0) return;

        const section = documentRef.createElement('section');
        section.className = 'gf-ai-help__related';
        const heading = documentRef.createElement('h3');
        heading.textContent = labels.relatedFeatures ?? '相关功能';
        const list = documentRef.createElement('div');
        safeFeatures.forEach((feature) => {
            const link = documentRef.createElement('a');
            link.href = feature.url;
            link.className = 'gf-ai-help__feature';
            const icon = documentRef.createElement('span');
            icon.append(createIcon(String(feature.icon ?? 'arrow-up-right')));
            const copy = documentRef.createElement('span');
            const title = documentRef.createElement('strong');
            title.textContent = String(feature.title ?? '');
            const description = documentRef.createElement('small');
            description.textContent = String(feature.description ?? '');
            copy.append(title, description);
            link.append(icon, copy, createIcon('arrow-right'));
            list.append(link);
        });
        section.append(heading, list);
        target.append(section);
    };

    const renderKnowledgeSources = (target, sources) => {
        const items = (Array.isArray(sources) ? sources : []).map((source) => ({
            section: String(source?.section_path ?? '').trim(),
            version: String(source?.official_version ?? '').trim(),
        })).filter((source) => source.section !== '').filter((source, index, list) => (
            list.findIndex((candidate) => candidate.section === source.section && candidate.version === source.version) === index
        )).slice(0, 4);
        if (items.length === 0) return;

        const section = documentRef.createElement('section');
        section.className = 'gf-ai-help__sources';
        const heading = documentRef.createElement('h3');
        heading.textContent = labels.referenceSections ?? '参考章节';
        const list = documentRef.createElement('div');
        items.forEach((source) => {
            const item = documentRef.createElement('span');
            item.append(createIcon('book-open-text'));
            const text = documentRef.createElement('span');
            text.textContent = source.section;
            item.append(text);
            if (source.version !== '') {
                const version = documentRef.createElement('small');
                version.textContent = `v${source.version}`;
                item.append(version);
            }
            list.append(item);
        });
        section.append(heading, list);
        target.append(section);
    };

    const renderRelatedMedia = (target, mediaItems) => {
        const items = (Array.isArray(mediaItems) ? mediaItems : []).map((item) => {
            const url = trustedFeatureUrl(item?.url, windowRef.location.origin, root.dataset.adminBasePath);
            const thumbnailUrl = trustedFeatureUrl(item?.thumbnail_url ?? item?.url, windowRef.location.origin, root.dataset.adminBasePath);
            return url && thumbnailUrl ? { ...item, url, thumbnailUrl } : null;
        }).filter(Boolean).slice(0, 3);
        if (items.length === 0) return;

        const section = documentRef.createElement('section');
        section.className = 'gf-ai-help__media';
        const heading = documentRef.createElement('h3');
        heading.textContent = labels.knowledgeImages ?? '相关截图';
        const gallery = documentRef.createElement('div');
        items.forEach((item) => {
            const figure = documentRef.createElement('figure');
            const button = documentRef.createElement('button');
            button.type = 'button';
            button.className = 'gf-ai-help__media-open';
            const image = documentRef.createElement('img');
            image.src = item.thumbnailUrl;
            image.alt = String(item.alt ?? item.title ?? '');
            image.loading = 'lazy';
            image.decoding = 'async';
            if (Number(item.width) > 0) image.width = Number(item.width);
            if (Number(item.height) > 0) image.height = Number(item.height);
            image.addEventListener('error', () => {
                figure.remove();
                if (gallery.childElementCount === 0) section.remove();
            });
            button.append(image);
            button.addEventListener('click', () => {
                const dialog = documentRef.createElement('dialog');
                dialog.className = 'gf-ai-help__media-dialog';
                dialog.setAttribute('aria-label', String(item.title ?? item.alt ?? labels.knowledgeImages ?? '图片预览'));

                const toolbar = documentRef.createElement('div');
                toolbar.className = 'gf-ai-help__media-dialog-toolbar';
                const controls = documentRef.createElement('div');
                controls.className = 'gf-ai-help__media-dialog-controls';
                const close = documentRef.createElement('button');
                close.type = 'button';
                close.className = 'gf-ai-help__media-dialog-close';
                close.setAttribute('aria-label', labels.closePreview ?? '关闭预览');
                close.append(createIcon('x'));

                const preview = image.cloneNode();
                preview.src = item.url;
                preview.loading = 'eager';
                preview.draggable = false;
                const viewport = documentRef.createElement('div');
                viewport.className = 'gf-ai-help__media-dialog-viewport';
                viewport.append(preview);

                const zoomMinimum = 0.5;
                const zoomMaximum = 2;
                const zoomStep = 0.25;
                let zoom = 1;
                const zoomOut = documentRef.createElement('button');
                zoomOut.type = 'button';
                zoomOut.className = 'gf-ai-help__media-dialog-zoom-out';
                zoomOut.setAttribute('aria-label', labels.zoomOut ?? '缩小图片');
                zoomOut.append(createIcon('minus'));
                const zoomReset = documentRef.createElement('button');
                zoomReset.type = 'button';
                zoomReset.className = 'gf-ai-help__media-dialog-zoom-reset';
                zoomReset.setAttribute('aria-label', labels.resetZoom ?? '重置缩放');
                const zoomValue = documentRef.createElement('span');
                zoomValue.className = 'gf-ai-help__media-dialog-zoom-value';
                zoomValue.setAttribute('aria-live', 'polite');
                zoomValue.setAttribute('aria-label', labels.zoomLevel ?? '图片缩放比例');
                zoomReset.append(zoomValue);
                const zoomIn = documentRef.createElement('button');
                zoomIn.type = 'button';
                zoomIn.className = 'gf-ai-help__media-dialog-zoom-in';
                zoomIn.setAttribute('aria-label', labels.zoomIn ?? '放大图片');
                zoomIn.append(createIcon('plus'));

                const updateZoom = (nextZoom) => {
                    zoom = Math.min(zoomMaximum, Math.max(zoomMinimum, Math.round(nextZoom / zoomStep) * zoomStep));
                    preview.style.width = `${zoom * 100}%`;
                    zoomValue.textContent = `${Math.round(zoom * 100)}%`;
                    zoomOut.disabled = zoom <= zoomMinimum;
                    zoomIn.disabled = zoom >= zoomMaximum;
                };
                zoomOut.addEventListener('click', () => updateZoom(zoom - zoomStep));
                zoomReset.addEventListener('click', () => updateZoom(1));
                zoomIn.addEventListener('click', () => updateZoom(zoom + zoomStep));
                controls.append(zoomOut, zoomReset, zoomIn);
                toolbar.append(controls, close);

                const caption = documentRef.createElement('p');
                caption.textContent = String(item.caption ?? item.title ?? '');
                close.addEventListener('click', () => dialog.close());
                dialog.addEventListener('keydown', (event) => {
                    const nextZoom = ['+', '='].includes(event.key)
                        ? zoom + zoomStep
                        : event.key === '-'
                            ? zoom - zoomStep
                            : event.key === '0'
                                ? 1
                                : null;
                    if (nextZoom === null) return;
                    event.preventDefault();
                    updateZoom(nextZoom);
                });
                dialog.addEventListener('close', () => {
                    dialog.remove();
                    button.focus({ preventScroll: true });
                });
                dialog.append(toolbar, viewport, caption);
                documentRef.body.append(dialog);
                updateZoom(1);
                refreshIcons(dialog);
                dialog.showModal();
            });
            const caption = documentRef.createElement('figcaption');
            const title = documentRef.createElement('strong');
            title.textContent = String(item.title ?? '');
            const copy = documentRef.createElement('span');
            copy.textContent = String(item.caption ?? '');
            caption.append(title, copy);
            figure.append(button, caption);
            gallery.append(figure);
        });
        section.append(heading, gallery);
        target.append(section);
    };

    const renderSuggestions = (target, suggestions) => {
        const items = [...new Set((Array.isArray(suggestions) ? suggestions : []).map((item) => String(item).trim()).filter(Boolean))].slice(0, 3);
        if (items.length === 0) return;

        const section = documentRef.createElement('section');
        section.className = 'gf-ai-help__followups';
        const heading = documentRef.createElement('h3');
        heading.textContent = labels.suggestedQuestions ?? '你还可以问';
        const list = documentRef.createElement('div');
        items.forEach((question) => {
            const button = documentRef.createElement('button');
            button.type = 'button';
            button.dataset.aiSuggestion = question;
            const text = documentRef.createElement('span');
            text.textContent = question;
            button.append(text, createIcon('arrow-up-right'));
            list.append(button);
        });
        section.append(heading, list);
        target.append(section);
    };

    const addCopyAction = (target, content) => {
        const copyContent = normalizeAnswerMarkdown(content);
        const action = documentRef.createElement('button');
        action.type = 'button';
        action.className = 'gf-ai-help__copy';
        action.append(createIcon('copy'), documentRef.createTextNode(labels.copyAnswer ?? '复制回答'));
        action.addEventListener('click', async () => {
            try {
                await windowRef.navigator.clipboard.writeText(copyContent);
                action.lastChild.textContent = labels.copied ?? '已复制';
            } catch {
                action.lastChild.textContent = labels.copyFailed ?? '复制失败';
            }
            windowRef.setTimeout(() => {
                action.lastChild.textContent = labels.copyAnswer ?? '复制回答';
            }, 1400);
        });
        target.append(action);
    };

    const createMessage = (role, content = '', meta = {}) => {
        const row = documentRef.createElement('article');
        row.className = `gf-ai-help__message is-${role}`;
        row.dataset.role = role;
        row.setAttribute('aria-label', role === 'assistant'
            ? labels.assistantRole ?? 'AI assistant'
            : labels.userRole ?? 'You');
        const body = documentRef.createElement('div');
        body.className = 'gf-ai-help__message-body';

        if (role === 'assistant') {
            const answer = documentRef.createElement('div');
            answer.className = 'gf-ai-help__answer gf-ai-markdown';
            renderMarkdownInto(answer, content, labels);
            body.append(answer);
            if (content.trim() !== '') addCopyAction(body, content);
            renderRelatedMedia(body, meta.related_media);
            renderKnowledgeSources(body, meta.knowledge_sources);
            renderFeatureLinks(body, meta.related_features);
            renderSuggestions(body, meta.suggestions);
            row.append(createAvatar('assistant'), body);
        } else {
            const bubble = documentRef.createElement('div');
            bubble.className = 'gf-ai-help__user-bubble';
            bubble.textContent = content;
            body.append(bubble);
            row.append(body, createAvatar('user'));
        }

        refreshIcons(row);

        return row;
    };

    const createPendingAnswer = () => {
        const row = documentRef.createElement('article');
        row.className = 'gf-ai-help__message is-assistant is-pending';
        row.setAttribute('aria-label', labels.assistantRole ?? 'AI assistant');
        const body = documentRef.createElement('div');
        body.className = 'gf-ai-help__message-body';
        const status = documentRef.createElement('div');
        status.className = 'gf-ai-help__thinking';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        const statusIcon = documentRef.createElement('span');
        statusIcon.append(createIcon('search'));
        const statusText = documentRef.createElement('span');
        statusText.textContent = '';
        const dots = documentRef.createElement('span');
        dots.className = 'gf-ai-help__dots';
        dots.setAttribute('aria-hidden', 'true');
        dots.append(documentRef.createElement('i'), documentRef.createElement('i'), documentRef.createElement('i'));
        status.append(statusIcon, statusText, dots);
        const answer = documentRef.createElement('div');
        answer.className = 'gf-ai-help__answer gf-ai-markdown';
        answer.setAttribute('aria-busy', 'true');
        answer.hidden = true;
        body.append(status, answer);
        row.append(createAvatar('assistant'), body);
        refreshIcons(row);

        return {
            row,
            body,
            status,
            statusIcon,
            statusText,
            answer,
            content: '',
            renderer: createStreamingMarkdownRenderer(answer, labels),
            statusTimers: [],
        };
    };

    const clearStatusTimers = (pending) => {
        pending.statusTimers.forEach((timer) => windowRef.clearTimeout(timer));
        pending.statusTimers = [];
    };

    const startStatusTimers = (pending) => {
        pending.statusTimers = [
            windowRef.setTimeout(() => {
                if (pending.content === '' && pending.row.classList.contains('is-pending')) {
                    pending.statusText.textContent = labels.statusSlow ?? '模型正在生成';
                }
            }, 3_000),
            windowRef.setTimeout(() => {
                if (pending.content === '' && pending.row.classList.contains('is-pending')) {
                    pending.statusText.textContent = labels.statusVerySlow ?? '回答需要一点时间';
                }
            }, 8_000),
        ];
    };

    const renderCompletion = (pending, data) => {
        clearStatusTimers(pending);
        pending.renderer.finish(pending.content);
        pending.row.classList.remove('is-pending');
        pending.answer.classList.remove('is-streaming');
        pending.answer.setAttribute('aria-busy', 'false');
        pending.status.remove();
        addCopyAction(pending.body, pending.content);
        renderRelatedMedia(pending.body, data?.related_media);
        renderKnowledgeSources(pending.body, data?.knowledge_sources);
        renderFeatureLinks(pending.body, data?.related_features);
        renderSuggestions(pending.body, data?.suggestions);
        refreshIcons(pending.row);
    };

    const renderError = (pending, data) => {
        clearStatusTimers(pending);
        if (pending.content.trim() !== '') {
            pending.answer.hidden = false;
            pending.renderer.finish(pending.content);
        }
        pending.row.classList.remove('is-pending');
        pending.answer.classList.remove('is-streaming');
        pending.answer.setAttribute('aria-busy', 'false');
        pending.status.remove();
        if (pending.content.trim() === '') pending.answer.remove();
        const error = documentRef.createElement('div');
        error.className = 'gf-ai-help__error';
        error.append(createIcon('circle-alert'));
        const copy = documentRef.createElement('span');
        copy.textContent = String(data?.message ?? labels.networkError ?? '暂时无法获取回答');
        error.append(copy);
        pending.body.append(error);
        renderFeatureLinks(pending.body, data?.related_features);
        renderSuggestions(pending.body, data?.suggestions);
        refreshIcons(pending.row);
    };

    const renderStopped = (pending) => {
        clearStatusTimers(pending);
        pending.row.classList.remove('is-pending');
        pending.answer.classList.remove('is-streaming');
        pending.answer.setAttribute('aria-busy', 'false');
        pending.status.remove();
        if (pending.content.trim() === '') {
            pending.answer.remove();
            const stopped = documentRef.createElement('div');
            stopped.className = 'gf-ai-help__stopped';
            stopped.append(createIcon('square'), documentRef.createTextNode(labels.answerStopped ?? '已停止生成'));
            pending.body.append(stopped);
            refreshIcons(pending.row);

            return true;
        }

        pending.answer.hidden = false;
        pending.renderer.finish(pending.content);
        const stopped = documentRef.createElement('div');
        stopped.className = 'gf-ai-help__stopped';
        stopped.append(createIcon('square'), documentRef.createTextNode(labels.answerStopped ?? '已停止生成'));
        pending.body.append(stopped);
        addCopyAction(pending.body, pending.content);
        refreshIcons(pending.row);

        return true;
    };

    const fetchJson = async (url, options = {}) => {
        const response = await fetcher(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(documentRef),
                ...(options.headers ?? {}),
            },
        });
        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const error = new Error(payload.message ?? `Request failed with ${response.status}`);
            error.status = response.status;
            throw error;
        }

        return response.json();
    };

    const createConversation = async (signal) => {
        const payload = await fetchJson(root.dataset.conversationsUrl, { method: 'POST', body: '{}', signal });
        state.conversationId = String(payload.data.id);
        state.title = String(payload.data.title ?? '');
        state.loadingEarlier = false;
        if (loadEarlier) {
            loadEarlier.disabled = false;
            loadEarlier.textContent = labels.loadEarlier ?? '加载更早消息';
        }
        threadTitle.textContent = state.title;
        updateLocation(state.conversationId);

        return state.conversationId;
    };

    const applyHistory = (payload, { prepend = false } = {}) => {
        const history = (Array.isArray(payload.messages) ? payload.messages : []).map((message) => createMessage(
            String(message.role ?? 'assistant'),
            String(message.content ?? ''),
            message.meta ?? {},
        ));
        if (prepend) messages.prepend(...history);
        else messages.replaceChildren(...history);
        state.hasMore = Boolean(payload.message_page?.has_more);
        state.nextCursor = payload.message_page?.next_cursor ?? null;
        if (loadEarlier) loadEarlier.hidden = !state.hasMore;
        refreshIcons(messages);
    };

    const loadConversation = async (conversationId, signal) => {
        const viewId = ++state.viewId;
        const payload = await fetchJson(replaceTemplate(root.dataset.conversationUrlTemplate, conversationId), { signal });
        if (viewId !== state.viewId) return false;
        state.conversationId = String(payload.data.id);
        state.title = String(payload.data.title ?? '');
        threadTitle.textContent = state.title;
        applyHistory(payload.data);
        showThread();
        updateLocation(state.conversationId);
        windowRef.requestAnimationFrame(() => scrollToLatest('auto'));

        return true;
    };

    const beginConversationLoad = (conversationId) => {
        conversationLoadController?.abort();
        const controller = new AbortController();
        conversationLoadController = controller;
        const promise = loadConversation(conversationId, controller.signal);
        activeConversationLoad = promise;
        void promise.finally(() => {
            if (activeConversationLoad !== promise) return;
            activeConversationLoad = null;
            if (conversationLoadController === controller) conversationLoadController = null;
        }).catch(() => {});

        return promise;
    };

    const finishGeneration = (generationId) => {
        if (generationId !== state.generationId) return;
        state.generating = false;
        state.controller = null;
        syncComposer();
        autoResize();
    };

    const sendQuestion = async (question) => {
        if (state.generating || question === '') return;
        const generationId = ++state.generationId;
        state.generating = true;
        state.controller = new AbortController();
        const controller = state.controller;
        announce('');
        showComposerError('');
        syncComposer();

        let completed = false;
        let appError = null;
        let renderFrame = null;
        let recentRefreshRequested = false;
        let pending = null;
        let userMessage = null;
        let responseAccepted = false;
        const renderAnswer = () => {
            renderFrame = null;
            if (!pending) return;
            const shouldFollow = isNearBottom();
            pending.renderer.update(pending.content);
            if (shouldFollow) scrollToLatest('auto');
        };
        const scheduleAnswer = () => {
            if (!pending || renderFrame !== null) return;
            renderFrame = windowRef.requestAnimationFrame(renderAnswer);
        };
        const parser = createSseParser(({ event, data }) => {
            if (!pending || generationId !== state.generationId || completed) return;
            if (event === 'status') {
                if (pending.content === '') pending.statusText.textContent = String(data?.label ?? '');
                pending.statusIcon.replaceChildren(createIcon(data?.stage === 'retrieving' ? 'search' : data?.stage === 'composing' ? 'wand-sparkles' : 'message-circle-more'));
                refreshIcons(pending.statusIcon);
            }
            if (event === 'delta') {
                clearStatusTimers(pending);
                pending.status.hidden = true;
                pending.answer.hidden = false;
                pending.answer.classList.add('is-streaming');
                pending.content += String(data?.content ?? '');
                scheduleAnswer();
            }
            if (event === 'title' && applyConversationTitle(data?.title)) {
                recentRefreshRequested = true;
                windowRef.GeoFlowAdminUi?.refreshRecentConversations?.({ force: true });
            }
            if (event === 'done') {
                completed = true;
                applyConversationTitle(data?.conversation_title);
                if (renderFrame !== null) windowRef.cancelAnimationFrame(renderFrame);
                renderAnswer();
                renderCompletion(pending, data);
                announce(labels.answerComplete ?? '回答已生成');
            }
            if (event === 'error') appError = data;
        });

        try {
            if (activeConversationLoad) {
                try {
                    await activeConversationLoad;
                } catch (error) {
                    if (generationId !== state.generationId) return;
                    showStart();
                    input.value = question;
                    autoResize();
                    syncComposer();
                    if (isAbortError(error)) {
                        announce(labels.answerStopped ?? '已停止生成');
                    } else {
                        const message = [401, 419].includes(error.status) ? labels.sessionExpired : error.message;
                        showComposerError(message);
                        announce(message);
                    }

                    return;
                }
            }
            if (generationId !== state.generationId) return;
            state.viewId += 1;
            if (!state.conversationId) await createConversation(controller.signal);
            if (generationId !== state.generationId) return;

            showThread();
            input.value = '';
            autoResize();
            userMessage = createMessage('user', question);
            pending = createPendingAnswer();
            messages.append(userMessage, pending.row);
            refreshIcons(messages);
            scrollToLatest();
            startStatusTimers(pending);

            const defaultTitles = Array.isArray(labels.defaultTitles) ? labels.defaultTitles : [labels.defaultTitle ?? '新对话', '新对话'];
            if (state.title === '' || defaultTitles.includes(state.title)) {
                applyConversationTitle(fallbackConversationTitle(question, labels.casualConversationTitle ?? '日常交流'));
            }

            const response = await fetcher(replaceTemplate(root.dataset.messageUrlTemplate, state.conversationId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(documentRef),
                },
                body: JSON.stringify({ prompt: question }),
                signal: controller.signal,
            });
            if (!response.ok || !response.body) {
                const payload = await response.json().catch(() => ({}));
                const error = new Error(payload.message ?? labels.networkError ?? 'Request failed');
                error.status = response.status;
                throw error;
            }
            responseAccepted = true;

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                parser.push(decoder.decode(value, { stream: true }));
                if (completed) {
                    await reader.cancel();
                    break;
                }
            }
            parser.push(decoder.decode());
            parser.finish();

            if (appError) {
                if (appError.persisted === false) {
                    clearStatusTimers(pending);
                    pending.row.remove();
                    userMessage?.remove();
                    pending = null;
                    input.value = question;
                    autoResize();
                    showComposerError(String(appError.message ?? labels.networkError ?? 'Request failed'));
                    announce(String(appError.message ?? labels.networkError ?? 'Request failed'));
                } else {
                    renderError(pending, appError);
                }
                if (!recentRefreshRequested) windowRef.GeoFlowAdminUi?.refreshRecentConversations?.({ force: true });
            }
            else if (!completed) throw new Error(labels.networkError ?? 'Stream ended before completion');
            else {
                if (!recentRefreshRequested) windowRef.GeoFlowAdminUi?.refreshRecentConversations?.({ force: true });
            }
        } catch (error) {
            if (generationId !== state.generationId) return;
            if (completed) return;
            if (isAbortError(error)) {
                if (!pending) {
                    input.value = question;
                    autoResize();
                } else {
                    renderStopped(pending);
                }
                announce(labels.answerStopped ?? '已停止生成');
            } else {
                const message = [401, 419].includes(error.status) ? labels.sessionExpired : error.message;
                if (pending && responseAccepted) {
                    renderError(pending, { message });
                } else {
                    if (pending) clearStatusTimers(pending);
                    pending?.row.remove();
                    userMessage?.remove();
                    pending = null;
                    input.value = question;
                    autoResize();
                    showComposerError(message);
                }
                announce(message);
            }
        } finally {
            if (pending) clearStatusTimers(pending);
            if (renderFrame !== null) windowRef.cancelAnimationFrame(renderFrame);
            finishGeneration(generationId);
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void sendQuestion(input.value.trim());
    });
    input.addEventListener('input', () => {
        showComposerError('');
        autoResize();
        syncComposer();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        form.requestSubmit();
    });
    stop.addEventListener('click', () => {
        state.controller?.abort();
        conversationLoadController?.abort();
    });
    root.querySelectorAll('[data-ai-fill-prompt]').forEach((button) => button.addEventListener('click', () => {
        if (state.generating) return;
        input.value = button.dataset.aiFillPrompt ?? '';
        showComposerError('');
        autoResize();
        syncComposer();
        input.focus({ preventScroll: true });
    }));
    showcasePrevious?.addEventListener('click', () => showShowcaseSlide(showcaseIndex - 1, { restart: true }));
    showcaseNext?.addEventListener('click', () => showShowcaseSlide(showcaseIndex + 1, { restart: true }));
    showcaseDots.forEach((dot) => dot.addEventListener('click', () => showShowcaseSlide(Number(dot.dataset.aiShowcaseDot), { restart: true })));
    showcase?.addEventListener('mouseenter', stopShowcase);
    showcase?.addEventListener('mouseleave', startShowcase);
    showcase?.addEventListener('focusin', stopShowcase);
    showcase?.addEventListener('focusout', (event) => {
        if (!showcase.contains(event.relatedTarget)) startShowcase();
    });
    documentRef.addEventListener('visibilitychange', () => {
        if (documentRef.hidden) stopShowcase();
        else startShowcase();
    });
    root.addEventListener('click', (event) => {
        const suggestion = event.target.closest('[data-ai-suggestion]');
        if (!suggestion || state.generating) return;
        input.value = suggestion.dataset.aiSuggestion ?? '';
        autoResize();
        syncComposer();
        void sendQuestion(input.value.trim());
    });
    root.querySelectorAll('[data-ai-new]').forEach((button) => button.addEventListener('click', () => {
        state.controller?.abort();
        showStart();
    }));
    rename?.addEventListener('click', async () => {
        if (!state.conversationId || state.generating) return;
        const conversationId = state.conversationId;
        const viewId = state.viewId;
        const title = (await windowRef.AdminActionDialog?.prompt?.({
            title: labels.renamePrompt ?? '输入新的会话名称',
            message: '',
            fieldLabel: labels.renamePrompt ?? '输入新的会话名称',
            value: state.title,
            maxLength: 80,
            required: true,
            requiredMessage: labels.dialogRequired ?? '',
            cancelLabel: labels.dialogCancel ?? '',
            opener: rename,
        }))?.trim();
        if (!title) return;
        try {
            const payload = await fetchJson(replaceTemplate(root.dataset.updateUrlTemplate, conversationId), {
                method: 'PATCH',
                body: JSON.stringify({ title }),
            });
            if (conversationId !== state.conversationId || viewId !== state.viewId) return;
            state.title = String(payload.data.title);
            threadTitle.textContent = state.title;
            windowRef.GeoFlowAdminUi?.refreshRecentConversations?.({ force: true });
        } catch (error) {
            if (conversationId === state.conversationId && viewId === state.viewId) {
                announce([401, 419].includes(error.status) ? labels.sessionExpired : error.message);
            }
        }
    });
    loadEarlier?.addEventListener('click', async () => {
        if (!state.conversationId || !state.nextCursor || state.loadingEarlier) return;
        const conversationId = state.conversationId;
        const viewId = state.viewId;
        state.loadingEarlier = true;
        loadEarlier.disabled = true;
        loadEarlier.textContent = labels.loadingEarlier ?? '正在加载';
        const previousHeight = scrollRoot.scrollHeight;
        try {
            const url = new URL(replaceTemplate(root.dataset.conversationUrlTemplate, conversationId), windowRef.location.origin);
            url.searchParams.set('before', state.nextCursor);
            const payload = await fetchJson(url);
            if (viewId !== state.viewId || conversationId !== state.conversationId) return;
            applyHistory(payload.data, { prepend: true });
            scrollRoot.scrollBy({ top: scrollRoot.scrollHeight - previousHeight, behavior: 'auto' });
        } catch (error) {
            if (viewId === state.viewId && conversationId === state.conversationId) {
                announce([401, 419].includes(error.status) ? labels.sessionExpired : error.message);
            }
        } finally {
            if (viewId === state.viewId && conversationId === state.conversationId) {
                state.loadingEarlier = false;
                loadEarlier.disabled = false;
                loadEarlier.textContent = labels.loadEarlier ?? '加载更早消息';
            }
        }
    });
    jumpLatest?.addEventListener('click', () => scrollToLatest());
    scrollRoot.addEventListener('scroll', () => {
        if (jumpLatest) jumpLatest.hidden = isNearBottom();
    }, { passive: true });
    documentRef.addEventListener('geoflow:conversation-archived', (event) => {
        if (String(event.detail?.conversationId ?? '') === state.conversationId) {
            state.controller?.abort();
            showStart();
        }
    });

    autoResize();
    syncComposer();
    showShowcaseSlide(0);
    startShowcase();
    refreshIcons(root);
    if (state.conversationId) {
        void beginConversationLoad(state.conversationId).catch(() => {
            if (!state.generating) showStart();
        });
    } else {
        input.focus({ preventScroll: true });
    }

    return { sendQuestion, loadConversation: beginConversationLoad, showStart };
}

const workspace = globalThis.document?.querySelector?.('[data-ai-workspace]');
if (workspace) setupAiWorkspace(workspace);

export { createStreamingMarkdownRenderer, setupAiWorkspace };
