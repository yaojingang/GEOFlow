export const RECENT_PAGE_SIZE = 10;

const RECENT_COLLAPSED_STORAGE_KEY = 'geoflow.admin.ui-v3.recent-collapsed';

export function mergeRecentPage(existing, incoming, { reset = false, excludedIds = new Set() } = {}) {
    const merged = (reset ? [] : [...existing])
        .filter((item) => item.kind === 'chat' && item.id && !excludedIds.has(String(item.id)));
    const known = new Set(merged.map((item) => `${item.kind}:${item.id}`));

    incoming.forEach((item) => {
        const key = `${item.kind}:${item.id}`;
        if (!item.id || item.kind !== 'chat' || known.has(key) || excludedIds.has(String(item.id))) return;
        known.add(key);
        merged.push(item);
    });

    return merged.filter((item) => !excludedIds.has(String(item.id)));
}

function storedValue(storage, key, fallback = null) {
    try {
        return storage?.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function persistValue(storage, key, value) {
    try {
        storage?.setItem(key, value);
    } catch {
        // The recent list remains usable when browser storage is unavailable.
    }
}

function normalizeItem(item) {
    if (!item?.id || item.kind !== 'chat') return null;

    return {
        id: String(item.id),
        kind: item.kind,
        title: String(item.title ?? ''),
        href: String(item.href ?? '#'),
        archiveUrl: item.archive_url ? String(item.archive_url) : null,
    };
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

export function setupSidebarRecent({
    documentRef = document,
    windowRef = window,
    fetcher = window.fetch.bind(window),
    refreshIcons = () => {},
} = {}) {
    const root = documentRef.querySelector('[data-sidebar-recent]');
    if (!root) return null;

    const body = root.querySelector('[data-sidebar-recent-body]');
    const toggle = root.querySelector('[data-sidebar-recent-toggle]');
    const scroll = root.querySelector('[data-sidebar-recent-scroll]');
    const list = root.querySelector('[data-sidebar-recent-list]');
    const status = root.querySelector('[data-sidebar-recent-status]');
    const retry = root.querySelector('[data-sidebar-recent-retry]');
    if (!body || !toggle || !scroll || !list || !status || !retry) return null;

    const state = {
        collapsed: storedValue(windowRef.localStorage, RECENT_COLLAPSED_STORAGE_KEY, '0') === '1',
        items: [],
        nextCursor: null,
        hasMore: true,
        loading: false,
        loadFailed: false,
        generation: 0,
        activeController: null,
        activePromise: null,
        archivedIds: new Set(),
        activeConversationId: new URL(windowRef.location.href).searchParams.get('conversation'),
    };

    const createChatItem = (item) => {
        const row = documentRef.createElement('div');
        row.className = 'gf-ai-history__item';
        row.dataset.recentKind = 'chat';
        if (item.id === state.activeConversationId) row.classList.add('is-active');

        const link = documentRef.createElement('a');
        link.className = 'gf-ai-history__open gf-sidebar__recent-item';
        link.href = item.href;
        link.dataset.conversationId = item.id;
        if (item.id === state.activeConversationId) link.setAttribute('aria-current', 'page');
        const title = documentRef.createElement('span');
        title.textContent = item.title;
        link.append(title);

        const archive = documentRef.createElement('button');
        archive.className = 'gf-ai-history__archive';
        archive.type = 'button';
        archive.dataset.archiveConversation = item.id;
        archive.dataset.archiveUrl = item.archiveUrl;
        archive.setAttribute('aria-label', root.dataset.archiveLabel);
        archive.title = root.dataset.archiveLabel;
        const archiveIcon = documentRef.createElement('i');
        archiveIcon.dataset.lucide = 'archive';
        archive.append(archiveIcon);
        row.append(link, archive);

        return row;
    };

    const render = () => {
        const visibleItems = state.items.filter((item) => !state.archivedIds.has(item.id));
        list.replaceChildren(...visibleItems.map(createChatItem));

        const statusText = state.loadFailed
            ? root.dataset.recentLoadFailed
            : state.loading
                ? root.dataset.recentLoading
                : visibleItems.length === 0
                    ? root.dataset.recentEmpty
                    : '';
        status.textContent = statusText;
        status.hidden = statusText === '';
        retry.hidden = !state.loadFailed;
        retry.textContent = root.dataset.recentRetry;
        refreshIcons(list);
    };

    const applyCollapsedState = (collapsed, persist = true) => {
        state.collapsed = collapsed;
        root.classList.toggle('is-collapsed', collapsed);
        body.hidden = collapsed;
        toggle.setAttribute('aria-expanded', String(!collapsed));
        if (persist) persistValue(windowRef.localStorage, RECENT_COLLAPSED_STORAGE_KEY, collapsed ? '1' : '0');
    };

    const conversations = () => state.items
        .filter((item) => !state.archivedIds.has(item.id))
        .map((item) => ({ id: item.id, title: item.title }));

    const loadPage = ({ reset = false, generation = state.generation } = {}) => {
        if (!reset && (!state.hasMore || state.activePromise)) return state.activePromise ?? Promise.resolve(conversations());

        const controller = new AbortController();
        state.activeController = controller;
        state.loading = true;
        state.loadFailed = false;
        render();

        const operation = (async () => {
            try {
                const url = new URL(root.dataset.recentUrl, windowRef.location.href);
                url.searchParams.set('limit', String(RECENT_PAGE_SIZE));
                if (!reset && state.nextCursor) url.searchParams.set('cursor', state.nextCursor);
                const response = await fetcher(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(`Recent activity request failed with ${response.status}`);
                const payload = await response.json();
                if (generation !== state.generation) return conversations();

                const incoming = (Array.isArray(payload.data) ? payload.data : [])
                    .map(normalizeItem)
                    .filter(Boolean);
                state.items = mergeRecentPage(state.items, incoming, {
                    reset,
                    excludedIds: state.archivedIds,
                });
                state.nextCursor = payload.next_cursor ?? null;
                state.hasMore = Boolean(payload.has_more);
            } catch (error) {
                if (!isAbortError(error) && generation === state.generation) state.loadFailed = true;
            } finally {
                if (generation === state.generation) {
                    state.loading = false;
                    render();
                }
            }

            return conversations();
        })();
        state.activePromise = operation;
        operation.finally(() => {
            if (state.activePromise === operation) state.activePromise = null;
            if (state.activeController === controller) state.activeController = null;
        });

        return operation;
    };

    const refresh = ({ force = false } = {}) => {
        if (!force && state.activePromise) return state.activePromise;
        state.generation += 1;
        state.activeController?.abort();
        state.activePromise = null;
        state.items = [];
        state.nextCursor = null;
        state.hasMore = true;
        state.loading = false;

        return loadPage({ reset: true, generation: state.generation });
    };

    const loadMore = () => loadPage({ generation: state.generation });

    toggle.addEventListener('click', () => applyCollapsedState(!state.collapsed));
    retry.addEventListener('click', () => void refresh({ force: true }));
    scroll.addEventListener('scroll', () => {
        if (scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight > 32) return;
        void loadMore();
    }, { passive: true });
    list.addEventListener('click', async (event) => {
        const archive = event.target.closest('[data-archive-conversation]');
        if (!archive) {
            if (event.target.closest('a')) documentRef.body.classList.remove('gf-sidebar-open');
            return;
        }
        event.preventDefault();
        const conversationId = archive.dataset.archiveConversation;
        const archiveUrl = archive.dataset.archiveUrl;
        if (!conversationId || !archiveUrl) return;

        archive.disabled = true;
        state.archivedIds.add(conversationId);
        render();
        try {
            const csrfToken = documentRef.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetcher(archiveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: '{}',
            });
            if (!response.ok) throw new Error(`Archive conversation request failed with ${response.status}`);
            if (state.activeConversationId === conversationId) state.activeConversationId = null;
            documentRef.dispatchEvent(new windowRef.CustomEvent('geoflow:conversation-archived', { detail: { conversationId } }));
            void refresh({ force: true });
        } catch {
            state.archivedIds.delete(conversationId);
            state.loadFailed = true;
            render();
        }
    });

    applyCollapsedState(state.collapsed, false);
    render();
    void refresh({ force: true });

    return {
        refresh,
        loadMore,
        chats: conversations,
        setActiveConversation(conversationId) {
            state.activeConversationId = conversationId || null;
            render();
        },
    };
}
