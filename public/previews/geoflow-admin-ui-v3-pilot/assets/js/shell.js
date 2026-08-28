(function () {
    'use strict';

    const pages = {
        dashboard: { label: '数据中心', icon: 'chart-no-axes-combined', href: 'dashboard.html' },
        tasks: { label: '任务管理', icon: 'workflow', href: 'tasks.html' },
        'site-settings': { label: '网站设置', icon: 'settings', href: 'site-settings.html' },
    };

    const groups = [
        {
            id: 'ai',
            items: [
                { key: 'ai-workspace', label: 'AI 工作台', icon: 'sparkles', href: '../../geoflow-admin-ui-v2/pages/workspace/ai-workspace.html?preview=account-footer-3' },
            ],
        },
        { id: 'data', label: '数据', items: [{ key: 'dashboard', label: '数据中心', icon: 'chart-no-axes-combined', href: 'dashboard.html' }] },
        {
            id: 'content', label: '内容', items: [
                { key: 'tasks', label: '任务管理', icon: 'workflow', href: 'tasks.html' },
                { key: 'content', label: '内容管理', icon: 'file-text' },
                { key: 'assets', label: '内容资产', icon: 'database' },
            ],
        },
        { id: 'distribution', label: '分发', items: [{ key: 'distribution', label: '分发管理', icon: 'radio-tower' }] },
        {
            id: 'system', label: '系统', items: [
                { key: 'ai-config', label: 'AI配置器', icon: 'bot' },
                { key: 'site-settings', label: '网站设置', icon: 'settings', href: 'site-settings.html' },
            ],
        },
    ];

    const recentItems = [
        { label: 'AI 可见性周报复盘', tone: 'blue', href: 'dashboard.html' },
        { label: 'GEOFlow 2.1 发布说明', tone: 'green' },
        { label: '企业知识库批量更新', tone: 'violet' },
    ];

    function icon(name, className) {
        return `<i data-lucide="${name}"${className ? ` class="${className}"` : ''}></i>`;
    }

    function navItem(item, current) {
        const active = item.key === current;
        const href = item.href || '#';
        const demo = item.href ? '' : ` data-demo="${item.label}页面将在全量阶段接入"`;
        return `<a class="gf-sidebar__link" href="${href}" title="${item.label}"${active ? ' aria-current="page"' : ''}${demo}>
            ${icon(item.icon)}<span>${item.label}</span>
        </a>`;
    }

    function sidebar(current) {
        return `<aside class="gf-sidebar" aria-label="公共菜单栏">
            <div class="gf-sidebar__brand">
                <a class="gf-wordmark" href="../index.html">GEOFlow</a>
                <button class="gf-icon-button" type="button" aria-label="收起公共菜单栏" aria-expanded="true" data-sidebar-collapse>${icon('panel-left-close')}</button>
                <button class="gf-icon-button gf-mobile-only" type="button" aria-label="关闭公共菜单栏" data-sidebar-close>${icon('x')}</button>
            </div>
            <nav class="gf-sidebar__nav" aria-label="后台功能导航">
                ${groups.map((group) => `<section class="gf-sidebar__group">
                    ${group.label ? `<h2 class="gf-sidebar__heading">${group.label}</h2>` : ''}
                    <div class="gf-sidebar__items">${group.items.map((item) => navItem(item, current)).join('')}</div>
                </section>`).join('')}
                <section class="gf-sidebar__recent">
                    <div class="gf-sidebar__recent-head"><h2 class="gf-sidebar__heading">最近处理</h2><button class="gf-icon-button gf-icon-button--small" type="button" aria-label="管理最近处理" data-demo="最近处理管理演示">${icon('sliders-horizontal')}</button></div>
                    <div class="gf-sidebar__items">${recentItems.map((item) => `<a class="gf-sidebar__link" href="${item.href || '#'}"${item.href ? '' : ` data-demo="打开${item.label}"`}><span class="gf-recent-dot gf-recent-dot--${item.tone}"></span><span>${item.label}</span></a>`).join('')}</div>
                </section>
            </nav>
            <div class="gf-sidebar__account-bar" aria-label="账户与系统快捷入口">
                <button class="gf-sidebar__account" type="button" data-dialog="account" aria-label="打开 Admin 账户信息">
                    <span class="gf-account-avatar">A</span><span class="gf-account-name">Admin</span>${icon('chevron-right')}
                </button>
                <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog="qr" aria-label="打开二维码">${icon('qr-code')}</button>
                <button class="gf-icon-button gf-sidebar__utility" type="button" data-dialog="quick-settings" aria-label="打开快捷设置">${icon('settings')}</button>
            </div>
        </aside>`;
    }

    function topbar(page) {
        return `<header class="gf-topbar">
            <button class="gf-icon-button gf-mobile-only" type="button" aria-label="打开公共菜单栏" data-sidebar-open>${icon('menu')}</button>
            <div class="gf-topbar__context">${icon(page.icon)}<span>${page.label}</span><span class="gf-pilot-tag">试点</span></div>
            <div class="gf-topbar__actions">
                <div class="gf-popover-wrap">
                    <button class="gf-icon-button gf-icon-button--round" type="button" aria-label="通知消息" data-popover-button="notifications">${icon('bell')}<span class="gf-notification-dot"></span></button>
                    <div class="gf-popover" data-popover="notifications" hidden><strong>通知消息</strong><p>发现 GEOFlow 2.1 更新，建议完成当前任务后查看变更说明。</p><button class="gf-button gf-button--primary gf-button--small" type="button" data-demo="更新日志演示">查看更新日志</button></div>
                </div>
                <button class="gf-language" type="button" data-demo="语言切换演示">${icon('languages')}<span>简体中文</span></button>
                <button class="gf-user-button" type="button" aria-label="打开用户菜单" data-popover-button="user"><span class="gf-user-avatar">${icon('user')}</span>${icon('chevron-down')}</button>
                <div class="gf-popover gf-popover--user" data-popover="user" hidden><strong>Admin</strong><span>超级管理员</span><button type="button" data-dialog="account">账户与权限</button><button type="button" data-demo="退出登录演示">退出登录</button></div>
            </div>
        </header>`;
    }

    function dialogs() {
        return `<div class="gf-modal-backdrop" data-modal hidden>
            <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="pilot-modal-title">
                <header class="gf-modal__header"><div><h2 id="pilot-modal-title" data-modal-title>试点操作</h2><p data-modal-subtitle>当前操作只展示界面反馈。</p></div><button class="gf-icon-button" type="button" aria-label="关闭弹窗" data-modal-close>${icon('x')}</button></header>
                <div class="gf-modal__body" data-modal-body></div>
                <footer class="gf-modal__footer"><span>演示数据不会写入系统</span><button class="gf-button gf-button--primary" type="button" data-modal-close>关闭</button></footer>
            </section>
        </div>`;
    }

    function prototypeSwitcher(current) {
        const ids = Object.keys(pages);
        const index = ids.indexOf(current);
        const previous = pages[ids[(index - 1 + ids.length) % ids.length]];
        const next = pages[ids[(index + 1) % ids.length]];
        return `<nav class="gf-pilot-switcher" aria-label="试点页面切换">
            <a href="${previous.href}" aria-label="上一个试点页面">${icon('arrow-left')}</a>
            <span>${index + 1} / ${ids.length} · ${pages[current].label}</span>
            <a href="${next.href}" aria-label="下一个试点页面">${icon('arrow-right')}</a>
        </nav>`;
    }

    function render(pageId, content) {
        const page = pages[pageId];
        return `<a class="gf-skip-link" href="#main-content">跳到主要内容</a>
            <div class="gf-shell">
                <button class="gf-sidebar-overlay" type="button" aria-label="关闭公共菜单栏" data-sidebar-close></button>
                ${sidebar(pageId)}
                <div class="gf-shell__body">
                    ${topbar(page)}
                    <main class="gf-main" id="main-content"><div class="gf-content">${content}</div></main>
                </div>
            </div>
            ${dialogs()}
            ${prototypeSwitcher(pageId)}
            <div class="gf-toast" role="status" aria-live="polite" data-toast>操作已记录</div>`;
    }

    window.GeoFlowPilotShell = { icon, render };
}());
