(() => {
    const root = document.querySelector('[data-theme-adaptation]');
    if (!root) return;
    const settingsForm = document.querySelector('[data-recommendation-settings]');
    const start = root.querySelector('[data-adaptation-start]');
    const status = root.querySelector('[data-adaptation-status]');
    const actions = root.querySelector('[data-adaptation-actions]');
    const rollback = root.querySelector('[data-adaptation-rollback]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let taskUrl = status?.dataset.taskUrl || '';
    let taskId = status?.dataset.taskId || '';
    let timer;
    let pollFailures = 0;
    const canStart = Boolean(start && !start.disabled);
    const settingsChanged = values => {
        const saved = JSON.parse(settingsForm?.dataset.savedValues || '{}');
        return ['heading', 'default_limit', 'rotation_hours', 'enabled'].some(key => String(values[key]) !== String(saved[key]));
    };
    const labels = { queued: '正在等待处理', checking: '正在检查站点和主题', snapshot: '正在保存当前主题', locating: '正在定位推荐区', validating: '正在检查文章页', publishing: '正在开启推荐', completed: '推荐已开启', rolled_back: '本次适配已撤回' };
    const render = task => {
        if (!status) return;
        status.hidden = false;
        status.textContent = task.status === 'failed' ? (task.error_message || '主题适配未完成，请重试') : (labels[task.phase] || labels[task.status] || '正在处理');
        if (task.status === 'queued' && task.created_at && Date.now() - Date.parse(task.created_at) > 120000) {
            status.textContent = '任务仍在等待处理，请检查后台队列是否正常运行。';
        }
        taskUrl = task.status_url || taskUrl;
        taskId = task.id || taskId;
        if (settingsForm) settingsForm.inert = task.status === 'queued' || task.status === 'running';
        if (actions) actions.hidden = task.status !== 'completed';
        if (start) {
            start.disabled = !canStart || task.status === 'queued' || task.status === 'running';
            start.textContent = task.status === 'failed' ? '重试适配并开启推荐' : '一键适配并开启推荐';
        }
        if (task.article_url) {
            let link = root.querySelector('[data-adaptation-article]');
            if (!link && actions) {
                link = document.createElement('a');
                link.dataset.adaptationArticle = '';
                link.className = 'min-h-10 rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-800';
                link.textContent = '查看文章页'; link.target = '_blank'; link.rel = 'noopener'; actions.prepend(link);
            }
            if (link) link.href = task.article_url;
        }
        if (task.status === 'completed' || task.status === 'rolled_back') { window.location.reload(); return; }
        if (task.status === 'queued' || task.status === 'running') poll();
    };
    const request = async (url, payload) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(url, { method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, ...(payload === undefined ? {} : {body: JSON.stringify(payload)}) });
            if (response.status === 401 || response.status === 419 || response.redirected) throw new Error('登录状态已过期，请刷新页面后重试。');
            const isJson = (response.headers.get('Content-Type') || '').includes('application/json');
            const data = isJson ? await response.json() : {};
            if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || '请求未完成，请稍后重试。');
            if (!isJson) throw new Error('服务器未返回任务状态，请刷新页面后重试。');
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('等待响应超时，任务可能仍在后台处理。请刷新页面查看状态。');
            if (error.name === 'TypeError' || error.message === 'Failed to fetch') {
                const networkError = new Error('暂时无法连接服务器。任务可能仍在后台处理，请再次点击或刷新查看状态。');
                networkError.networkFailure = true;
                throw networkError;
            }
            throw error;
        } finally { clearTimeout(timeout); }
    };
    const poll = () => {
        clearTimeout(timer);
        if (!taskUrl) return;
        timer = setTimeout(async () => {
            try {
                const task = await request(taskUrl);
                pollFailures = 0;
                render(task);
            } catch (error) {
                status.textContent = error.networkFailure ? '暂时无法连接服务器，任务仍可在后台继续。正在重新查询状态。' : error.message;
                pollFailures += 1;
                if (!error.message.includes('登录状态')) poll();
            }
        }, pollFailures ? Math.min(30000, 10000 * Math.min(pollFailures, 3)) : 2500);
    };
    start?.addEventListener('click', async () => {
        start.disabled = true;
        try {
            if (settingsForm) {
                if (!settingsForm.reportValidity()) { start.disabled = !canStart; return; }
                const values = Object.fromEntries(new FormData(settingsForm));
                if (settingsChanged(values)) {
                    status.hidden = false;
                    status.textContent = '正在保存推荐设置';
                    settingsForm.inert = true;
                    const result = await request(settingsForm.action, values);
                    settingsForm.querySelector('[name="revision"]').value = result.revision;
                    settingsForm.dataset.savedValues = JSON.stringify(values);
                }
                settingsForm.dispatchEvent(new CustomEvent('gf:saved', { bubbles: true }));
                settingsForm.inert = true;
            }
            render(await request(root.dataset.storeUrl, { site_key: root.dataset.siteKey }));
        } catch (error) {
            if (settingsForm) settingsForm.inert = false;
            status.hidden = false; status.textContent = error.message; start.disabled = !canStart;
        }
    });
    rollback?.addEventListener('click', async () => {
        if (!window.AdminActionDialog?.confirm) {
            status.hidden = false; status.textContent = '确认组件尚未加载，请刷新页面后重试。'; return;
        }
        const edited = settingsForm && settingsChanged(Object.fromEntries(new FormData(settingsForm)));
        if (!await window.AdminActionDialog.confirm({title: '撤回此次主题适配', message: edited ? '将恢复之前的文章主题版本与推荐开关，并放弃当前未保存的推荐设置。' : '将恢复之前的文章主题版本与推荐开关。', confirmLabel: '撤回适配', tone: 'danger'})) return;
        rollback.disabled = true;
        if (settingsForm) settingsForm.inert = true;
        try {
            const task = await request(`${root.dataset.rollbackBase}/${taskId}/rollback`, {});
            if (task.status === 'rolled_back' && settingsForm) settingsForm.reset();
            render(task);
        } catch (error) {
            if (settingsForm) settingsForm.inert = false;
            status.hidden = false; status.textContent = error.message; rollback.disabled = false;
        }
    });
    if (taskUrl && /正在等待处理|正在适配主题/.test(status?.textContent || '')) { if (start) start.disabled = true; if (settingsForm) settingsForm.inert = true; poll(); }
    window.addEventListener('pagehide', () => clearTimeout(timer));
})();
