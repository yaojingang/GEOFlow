(function () {
    'use strict';

    const C = () => window.GeoFlowComponents;

    const navigation = [
        {
            id: 'ai',
            items: [
                { key: 'ai-workspace', label: 'AI 工作台', icon: 'sparkles', pageId: 'ai-workspace' },
            ],
        },
        {
            id: 'operations', label: '运营分析',
            items: [
                { key: 'dashboard', label: '首页', icon: 'home', pageId: 'dashboard' },
                { key: 'analytics', label: '增长中心', icon: 'chart-no-axes-combined', pageId: 'analytics-overview' },
            ],
        },
        {
            id: 'content', label: '内容生产',
            items: [
                { key: 'tasks', label: '任务管理', icon: 'workflow', pageId: 'tasks-index' },
                { key: 'articles', label: '内容管理', icon: 'file-text', pageId: 'articles-index' },
                { key: 'manual-publications', label: '人工发布', icon: 'send', pageId: 'manual-publications-index' },
                { key: 'distribution', label: '分发管理', icon: 'radio-tower', pageId: 'distribution-index' },
            ],
        },
        {
            id: 'capability', label: '资产与能力',
            items: [
                { key: 'materials', label: '内容资产', icon: 'database', pageId: 'materials-index' },
                { key: 'ai-config', label: 'AI配置器', icon: 'bot', pageId: 'ai-configurator' },
            ],
        },
        {
            id: 'system', label: '系统管理',
            items: [
                { key: 'site-settings', label: '网站设置', icon: 'settings', pageId: 'site-settings-index' },
                { key: 'admin-users', label: '用户管理', icon: 'users', pageId: 'admin-users-index' },
            ],
        },
    ];

    const recentItems = [
        { label: 'AI 可见性周报复盘', pageId: 'analytics-ai-visibility', tone: 'blue' },
        { label: 'GEOFlow 2.1 发布说明', pageId: 'articles-edit', tone: 'green' },
        { label: '企业知识库批量更新', pageId: 'knowledge-bases-detail', tone: 'violet' },
    ];

    function pageById(id) {
        return window.GEOFLOW_UI_V2.pages.find((page) => page.id === id);
    }

    function pageHref(id) {
        const page = pageById(id);
        return page ? `../../pages/${page.path}` : '../../index.html';
    }

    function sidebarLink(item, currentPage) {
        const active = currentPage.nav === item.key;
        return `<a class="gf-sidebar__link" href="${pageHref(item.pageId)}" title="${C().escapeHtml(item.label)}"${active ? ' aria-current="page"' : ''}>
            ${C().icon(item.icon)}<span class="gf-sidebar__label">${C().escapeHtml(item.label)}</span>
        </a>`;
    }

    function sidebar(currentPage) {
        return `<aside class="gf-sidebar" aria-label="主菜单">
            <div class="gf-sidebar__brand">
                <a class="gf-wordmark" href="${pageHref('ai-workspace')}">GEOFlow</a>
                <button class="gf-icon-button" type="button" aria-label="收起主菜单" aria-expanded="true" data-sidebar-collapse>${C().icon('panel-left-close')}</button>
                <button class="gf-icon-button gf-mobile-menu-button" type="button" aria-label="关闭主菜单" data-sidebar-close>${C().icon('x')}</button>
            </div>
            <nav class="gf-sidebar__nav" aria-label="后台功能导航">
                ${navigation.map((group) => `<section class="gf-sidebar__group" aria-label="${C().escapeHtml(group.label || '入口')}">
                    ${group.label ? `<h2 class="gf-sidebar__heading">${C().escapeHtml(group.label)}</h2>` : ''}
                    <div class="gf-sidebar__items">${group.items.map((item) => sidebarLink(item, currentPage)).join('')}</div>
                </section>`).join('')}
                <section class="gf-sidebar__recent" aria-label="最近处理">
                    <div class="gf-sidebar__recent-head"><h2 class="gf-sidebar__heading gf-p-0">最近处理</h2><button class="gf-icon-button" type="button" aria-label="管理最近处理记录" data-demo-action="管理最近处理记录演示">${C().icon('sliders-horizontal')}</button></div>
                    <div class="gf-sidebar__recent-list">${recentItems.map((item) => `<a class="gf-sidebar__link" href="${pageHref(item.pageId)}" title="${C().escapeHtml(item.label)}"><span class="gf-recent-dot gf-recent-dot--${item.tone}"></span><span class="gf-sidebar__label">${C().escapeHtml(item.label)}</span></a>`).join('')}</div>
                </section>
            </nav>
            <div class="gf-sidebar__version">GEOFlow v2.0 · UI V2 原型</div>
        </aside>`;
    }

    function notificationPopover() {
        return `<div class="gf-popover-wrap">
            <button class="gf-icon-button gf-icon-button--round gf-notification-button" type="button" aria-label="通知消息" aria-controls="gf-popover-notifications" aria-expanded="false" data-popover-button="notifications">${C().icon('bell')}<span class="gf-notification-dot"></span></button>
            <div class="gf-popover" id="gf-popover-notifications" aria-hidden="true" data-popover="notifications">
                <div class="gf-popover__header"><span class="gf-popover__title">通知消息</span>${C().badge('有更新', 'red')}</div>
                <div class="gf-popover__body"><div class="gf-popover__title">发现新版本 v2.1</div><p class="gf-popover__copy">GitHub 上已经有新的 GEOFlow 版本，建议查看更新说明后再升级。</p><div class="gf-mt-16">${C().button('查看更新日志', 'arrow-right', 'primary', 'data-demo-action="打开更新日志演示"')}</div></div>
            </div>
        </div>`;
    }

    function userPopover() {
        return `<div class="gf-popover-wrap">
            <button class="gf-user-button" type="button" aria-label="打开用户菜单" aria-controls="gf-popover-user" aria-expanded="false" data-popover-button="user"><span class="gf-avatar">${C().icon('user')}</span>${C().icon('chevron-down')}</button>
            <div class="gf-popover gf-popover--user" id="gf-popover-user" aria-hidden="true" data-popover="user">
                <div class="gf-menu-meta"><div class="gf-popover__title">欢迎，admin</div><div class="gf-table__secondary">超级管理员</div></div>
                <a class="gf-menu-link" href="${pageHref('dashboard')}">${C().icon('home')}返回首页</a>
                <a class="gf-menu-link" href="${pageHref('site-settings-index')}">${C().icon('settings')}网站设置</a>
                <a class="gf-menu-link" href="${pageHref('admin-users-index')}">${C().icon('users')}用户管理</a>
                <a class="gf-menu-link gf-menu-link--danger" href="${pageHref('login')}">${C().icon('log-out')}退出登录</a>
            </div>
        </div>`;
    }

    function topbar(page) {
        return `<header class="gf-topbar">
            <button class="gf-icon-button gf-mobile-menu-button" type="button" aria-label="打开主菜单" aria-expanded="false" data-sidebar-open>${C().icon('menu')}</button>
            <div class="gf-topbar__context">${C().icon(page.icon || 'circle')}<span class="gf-topbar__title">${C().escapeHtml(page.shortTitle || page.title)}</span></div>
            <div class="gf-topbar__actions">
                ${notificationPopover()}
                <button class="gf-language" type="button" data-demo-action="语言切换演示">${C().icon('languages')}<span>简体中文</span></button>
                ${userPopover()}
            </div>
        </header>`;
    }

    function modal() {
        return `<div class="gf-modal-backdrop" aria-hidden="true" data-modal>
            <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="gf-modal-title">
                <header class="gf-modal__header"><div><h2 class="gf-card__title" id="gf-modal-title">原型操作说明</h2><p class="gf-card__subtitle" data-modal-message>当前操作只展示交互反馈。</p></div><button type="button" class="gf-icon-button" aria-label="关闭弹窗" data-modal-close>${C().icon('x')}</button></header>
                <div class="gf-modal__body"><div class="gf-callout">${C().icon('info')}<div>所有页面均使用演示数据，不会调用接口、写入数据库或改变现有 GEOFlow 状态。</div></div></div>
                <footer class="gf-modal__footer">${C().button('知道了', 'check', 'primary', 'data-modal-close')}</footer>
            </section>
        </div>`;
    }

    function render(page, content) {
        if (page.type === 'login') return content;
        const focus = page.type === 'ai-workspace';
        return `<a class="gf-skip-link gf-sr-only" href="#main-content">跳到主要内容</a>
        <div class="gf-shell">
            <button class="gf-sidebar-overlay" type="button" aria-label="关闭主菜单" aria-hidden="true" tabindex="-1" data-sidebar-overlay></button>
            ${sidebar(page)}
            <div class="gf-shell__body">
                ${topbar(page)}
                <main id="main-content" class="gf-main${focus ? ' gf-main--focus' : ''}"><div class="gf-content${focus ? ' gf-content--focus' : ''}">${content}</div></main>
                <footer class="gf-footer">© 2026 GEOFlow · Admin UI V2 静态原型 · 演示数据</footer>
            </div>
        </div>
        ${modal()}
        <div class="gf-toast" role="status" data-toast>原型操作已完成</div>`;
    }

    window.GeoFlowShell = { navigation, pageById, pageHref, render };
}());
