(function () {
    'use strict';

    const root = document.getElementById('pilot-root');
    const pageId = document.body.dataset.pilotPage;
    if (!root || !window.GeoFlowPilotPages?.[pageId]) return;

    root.innerHTML = window.GeoFlowPilotShell.render(pageId, window.GeoFlowPilotPages[pageId]());
    window.lucide?.createIcons();

    const body = document.body;
    const toast = document.querySelector('[data-toast]');
    let toastTimer;

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 1800);
    }

    document.addEventListener('click', (event) => {
        const collapse = event.target.closest('[data-sidebar-collapse]');
        if (collapse) {
            const collapsed = body.classList.toggle('gf-sidebar-collapsed');
            collapse.setAttribute('aria-expanded', String(!collapsed));
        }

        if (event.target.closest('[data-sidebar-open]')) body.classList.add('gf-sidebar-open');
        if (event.target.closest('[data-sidebar-close]')) body.classList.remove('gf-sidebar-open');

        const demo = event.target.closest('[data-demo]');
        if (demo) {
            if (demo.getAttribute('href') === '#') event.preventDefault();
            showToast(demo.dataset.demo || '试点操作已触发');
        }

        const popoverButton = event.target.closest('[data-popover-button]');
        if (popoverButton) {
            const name = popoverButton.dataset.popoverButton;
            document.querySelectorAll('[data-popover]').forEach((popover) => {
                popover.hidden = popover.dataset.popover !== name || !popover.hidden;
            });
        } else if (!event.target.closest('[data-popover]')) {
            document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = true; });
        }

        const dialogTrigger = event.target.closest('[data-dialog]');
        if (dialogTrigger) openDialog(dialogTrigger.dataset.dialog);
        if (event.target.closest('[data-modal-close]')) closeDialog();

        const settingsTab = event.target.closest('[data-settings-tab]');
        if (settingsTab) activateSettingsTab(settingsTab.dataset.settingsTab);

        const taskRowAction = event.target.closest('[data-task-action]');
        if (taskRowAction) showToast(`${taskRowAction.dataset.taskAction}演示已触发`);
    });

    function openDialog(type) {
        const modal = document.querySelector('[data-modal]');
        const title = modal?.querySelector('[data-modal-title]');
        const subtitle = modal?.querySelector('[data-modal-subtitle]');
        const content = modal?.querySelector('[data-modal-body]');
        if (!modal || !title || !subtitle || !content) return;

        const templates = {
            account: {
                title: 'Admin', subtitle: '当前账户、角色和登录安全',
                body: `<div class="gf-account-summary"><span class="gf-account-avatar gf-account-avatar--large">A</span><div><strong>Admin</strong><span>admin@geoflow.local</span></div><span class="gf-badge gf-badge--success">正常</span></div><dl class="gf-facts"><div><dt>角色</dt><dd>超级管理员</dd></div><div><dt>最近登录</dt><dd>今天 13:42</dd></div><div><dt>权限范围</dt><dd>全部后台功能</dd></div></dl><button class="gf-button gf-button--secondary" type="button" data-demo="修改密码演示">修改密码</button>`,
            },
            qr: {
                title: 'GEOFlow 移动访问', subtitle: '扫码打开当前试点页面',
                body: `<div class="gf-qr-placeholder"><span>${window.GeoFlowPilotShell.icon('qr-code')}</span><div><strong>试点访问二维码</strong><p>正式实施时根据当前域名动态生成。</p></div></div>`,
            },
            'quick-settings': {
                title: '快捷设置', subtitle: '集中进入常用配置',
                body: `<div class="gf-shortcut-list"><a href="site-settings.html">${window.GeoFlowPilotShell.icon('globe-2')}<span><strong>网站设置</strong><small>品牌、SEO、首页与用户</small></span>${window.GeoFlowPilotShell.icon('chevron-right')}</a><button type="button" data-demo="AI配置器演示">${window.GeoFlowPilotShell.icon('bot')}<span><strong>AI配置器</strong><small>模型、服务商与提示词</small></span>${window.GeoFlowPilotShell.icon('chevron-right')}</button><button type="button" data-demo="安全审计演示">${window.GeoFlowPilotShell.icon('shield-check')}<span><strong>安全与审计</strong><small>权限、敏感词与操作日志</small></span>${window.GeoFlowPilotShell.icon('chevron-right')}</button></div>`,
            },
        };
        const template = templates[type] || templates.account;
        title.textContent = template.title;
        subtitle.textContent = template.subtitle;
        content.innerHTML = template.body;
        modal.hidden = false;
        requestAnimationFrame(() => modal.classList.add('is-open'));
        window.lucide?.createIcons();
        modal.querySelector('[data-modal-close]')?.focus();
    }

    function closeDialog() {
        const modal = document.querySelector('[data-modal]');
        if (!modal) return;
        modal.classList.remove('is-open');
        setTimeout(() => { modal.hidden = true; }, 160);
    }

    function activateSettingsTab(tabId) {
        document.querySelectorAll('[data-settings-tab]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.settingsTab === tabId);
            button.setAttribute('aria-selected', String(button.dataset.settingsTab === tabId));
        });
        document.querySelectorAll('[data-settings-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.settingsPanel !== tabId;
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDialog();
            body.classList.remove('gf-sidebar-open');
        }
        const target = event.target;
        if (target.matches('input, textarea, select, [contenteditable]')) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            const links = [...document.querySelectorAll('.gf-pilot-switcher a')];
            const targetLink = event.key === 'ArrowLeft' ? links[0] : links[1];
            if (targetLink) window.location.href = targetLink.href;
        }
    });
}());
