(() => {
    const bindSearch = (kind, inputName, label) => {
        const search = document.querySelector(`[data-link-pool-${kind}-search]`);
        const list = document.querySelector(`[data-link-pool-${kind}-list]`);
        const status = document.querySelector(`[data-link-pool-${kind}-search-status]`);
        const identity = `input[name="${inputName}[]"]`;
        if (!search || !list || !status) return;
        let busy = false;
        search.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            busy = true;
            const button = [...search.elements].find(control => control.tagName === 'BUTTON');
            if (button) button.disabled = true;
            list.setAttribute('aria-busy', 'true');
            status.textContent = `正在查找${label}，当前编辑内容会保留。`;
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const url = new URL(search.action, window.location.href);
                url.search = new URLSearchParams(new FormData(search)).toString();
                const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'text/html' } });
                if (response.redirected || response.status === 401 || response.status === 419) throw new Error('登录状态已变化，请重新登录后继续。');
                if (!response.ok) throw new Error(`暂时无法查询${label}，请重试。当前编辑内容已保留。`);
                const documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
                const next = documentResult.querySelector(`[data-link-pool-${kind}-list]`);
                if (!next) throw new Error(`${label}列表暂时无法读取，请重试。当前编辑内容已保留。`);
                const existing = new Map([...list.children].map(row => [row.querySelector(identity)?.value, row]).filter(([id]) => id));
                const retained = [...existing.values()].filter(row => row.querySelector('input:checked')
                    || [...row.querySelectorAll('input[name^="review_reasons"]')].some(input => input.value.trim())
                    || [...row.querySelectorAll('input')].some(input => input.value !== input.defaultValue || input.checked !== input.defaultChecked));
                const retainedIds = new Set(retained.map(row => row.querySelector(identity)?.value));
                const replacements = [...next.children].filter(row => {
                    const id = row.querySelector(identity)?.value;
                    return id ? !retainedIds.has(id) : retained.length === 0;
                }).map(row => existing.get(row.querySelector(identity)?.value) || row);
                list.replaceChildren(...retained, ...replacements);
                status.textContent = `${label}列表已更新，当前选择和活动设置已保留。`;
            } catch (error) {
                status.textContent = error.name === 'AbortError'
                    ? `查找${label}超时，请重试。当前编辑内容已保留。`
                    : error.message.startsWith('登录状态') || error.message.startsWith('暂时无法') || error.message.startsWith(`${label}列表`)
                        ? error.message : '暂时无法连接服务器，请重试。当前编辑内容已保留。';
            } finally {
                clearTimeout(timeout);
                list.removeAttribute('aria-busy');
                if (button) button.disabled = false;
                busy = false;
            }
        });
    };
    bindSearch('target', 'target_ids', '目标');
    bindSearch('source', 'source_article_ids', '来源文章');
    const scope = document.querySelector('select[name="scope"]');
    const chooser = document.querySelector('[data-source-chooser]');
    const updateScope = () => {
        document.querySelectorAll('[data-source-scope]').forEach(field => { field.hidden = field.dataset.sourceScope !== scope?.value; });
        if (scope?.value === 'articles' && chooser) chooser.open = true;
    };
    scope?.addEventListener('change', updateScope);
    document.querySelector('[data-link-pool-source-list]')?.addEventListener('change', event => {
        const input = event.target;
        if (input.type !== 'checkbox' || !input.checked) return;
        const opposite = input.name === 'source_article_ids[]' ? 'exclusion_ids[]' : 'source_article_ids[]';
        const row = input.closest('div').parentElement;
        const other = row.querySelector(`input[name="${opposite}"]`);
        if (other) other.checked = false;
        if (input.name === 'source_article_ids[]' && scope) scope.value = 'articles';
        updateScope();
    });
    updateScope();
})();
