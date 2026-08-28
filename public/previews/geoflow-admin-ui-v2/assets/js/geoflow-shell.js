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
            id: 'data', label: '数据',
            items: [
                { key: 'analytics', label: '数据中心', icon: 'chart-no-axes-combined', pageId: 'analytics-overview' },
            ],
        },
        {
            id: 'content', label: '内容',
            items: [
                { key: 'tasks', label: '任务管理', icon: 'workflow', pageId: 'tasks-index' },
                { key: 'articles', label: '内容管理', icon: 'file-text', pageId: 'articles-index' },
                { key: 'materials', label: '内容资产', icon: 'database', pageId: 'materials-index' },
            ],
        },
        {
            id: 'distribution', label: '分发',
            items: [
                { key: 'distribution', label: '分发管理', icon: 'radio-tower', pageId: 'distribution-index' },
            ],
        },
        {
            id: 'system', label: '系统',
            items: [
                { key: 'ai-config', label: 'AI配置器', icon: 'bot', pageId: 'ai-configurator' },
                { key: 'site-settings', label: '网站设置', icon: 'settings', pageId: 'site-settings-index' },
            ],
        },
    ];

    const recentItems = [
        { key: 'visibility-review', label: 'AI 可见性周报复盘', prompt: '复盘本周 AI 可见性数据，解释品牌提及和引用页面的变化，并生成下一步优化任务', tone: 'blue' },
        { key: 'citation-diagnosis', label: '官网引用下降诊断', prompt: '诊断官网近 30 天引用下降的内容，按影响范围排序并给出修复建议', tone: 'green' },
        { key: 'knowledge-refresh', label: '知识资产批量更新', prompt: '检查知识资产的更新与向量化状态，整理需要批量处理的内容并安排审核', tone: 'violet' },
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

    function sidebarAccountBar() {
        return `<div class="gf-sidebar__account-bar" aria-label="账户与系统快捷入口">
            <button class="gf-sidebar__account-button" type="button" aria-label="打开 Admin 账户信息" data-shell-dialog="account">
                <span class="gf-sidebar__account-avatar" aria-hidden="true">A</span>
                <span class="gf-sidebar__account-name">Admin</span>
                ${C().icon('chevron-right')}
            </button>
            <button class="gf-icon-button gf-sidebar__utility-button" type="button" aria-label="打开 GEOFlow 二维码" data-shell-dialog="qr">${C().icon('qr-code')}</button>
            <button class="gf-icon-button gf-sidebar__utility-button" type="button" aria-label="打开快捷设置" data-shell-dialog="settings">${C().icon('settings')}</button>
        </div>`;
    }

    function sidebar(currentPage) {
        const activeConversation = new URLSearchParams(window.location.search).get('conversation');
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
            </nav>
            <section class="gf-sidebar__recent" aria-label="最近处理">
                <div class="gf-sidebar__recent-head"><h2 class="gf-sidebar__heading gf-p-0">最近处理</h2><button class="gf-icon-button" type="button" aria-label="管理最近处理记录" data-demo-action="管理最近处理记录演示">${C().icon('sliders-horizontal')}</button></div>
                <div class="gf-sidebar__recent-list">${recentItems.map((item) => `<a class="gf-sidebar__link" href="${pageHref('ai-workspace')}?conversation=${encodeURIComponent(item.key)}" title="${C().escapeHtml(item.label)}" data-recent-conversation="${C().escapeHtml(item.key)}"${currentPage.id === 'ai-workspace' && activeConversation === item.key ? ' aria-current="page"' : ''}><span class="gf-recent-dot gf-recent-dot--${item.tone}"></span><span class="gf-sidebar__label">${C().escapeHtml(item.label)}</span></a>`).join('')}</div>
            </section>
            ${sidebarAccountBar()}
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
            <section class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="gf-modal-title" data-modal-panel>
                <header class="gf-modal__header"><div><h2 class="gf-card__title" id="gf-modal-title" data-modal-title>原型操作说明</h2><p class="gf-card__subtitle" data-modal-message>当前操作只展示交互反馈。</p></div><button type="button" class="gf-icon-button" aria-label="关闭弹窗" data-modal-close>${C().icon('x')}</button></header>
                <div class="gf-modal__body" data-modal-body><div class="gf-callout">${C().icon('info')}<div>所有页面均使用演示数据，不会调用接口、写入数据库或改变现有 GEOFlow 状态。</div></div></div>
                <footer class="gf-modal__footer" data-modal-footer>${C().button('知道了', 'check', 'primary', 'data-modal-close')}</footer>
            </section>
        </div>`;
    }

    function dialogTemplates() {
        return `<template data-shell-dialog-template="account" data-dialog-title="Admin" data-dialog-message="管理当前账户信息、权限与登录安全">
            <div data-dialog-body>
                <div class="gf-account-summary">
                    <span class="gf-account-summary__avatar" aria-hidden="true">A</span>
                    <div><strong>Admin</strong><span>admin@geoflow.local</span></div>
                    ${C().badge('当前账户', 'green')}
                </div>
                <dl class="gf-account-facts">
                    <div><dt>账号</dt><dd>admin</dd></div>
                    <div><dt>角色</dt><dd>超级管理员</dd></div>
                    <div><dt>账户状态</dt><dd><span class="gf-status-dot gf-status-dot--success"></span>正常</dd></div>
                    <div><dt>最近登录</dt><dd>今天 13:42</dd></div>
                </dl>
                <section class="gf-account-section" aria-labelledby="gf-account-permissions">
                    <div class="gf-account-section__head"><div><h3 id="gf-account-permissions">当前权限</h3><p>拥有 GEOFlow 后台全部管理权限</p></div><a href="${pageHref('admin-users-index')}">权限管理${C().icon('arrow-up-right')}</a></div>
                    <div class="gf-permission-tags"><span>任务管理</span><span>内容管理</span><span>分发管理</span><span>系统配置</span><span>用户管理</span></div>
                </section>
                <button class="gf-account-password-toggle" type="button" aria-expanded="false" aria-controls="gf-account-password-panel" data-account-password-toggle>${C().icon('lock-keyhole')}<span><strong>修改密码</strong><small>更新当前 Admin 账户的登录密码</small></span>${C().icon('chevron-down')}</button>
                <form class="gf-account-password-form" id="gf-account-password-panel" hidden data-account-password-form>
                    <div class="gf-field"><label class="gf-label" for="current-password">当前密码</label><input class="gf-input" id="current-password" type="password" autocomplete="current-password" placeholder="输入当前密码" required></div>
                    <div class="gf-field-grid"><div class="gf-field"><label class="gf-label" for="new-password">新密码</label><input class="gf-input" id="new-password" type="password" autocomplete="new-password" minlength="8" placeholder="至少 8 位" required></div><div class="gf-field"><label class="gf-label" for="confirm-password">确认新密码</label><input class="gf-input" id="confirm-password" type="password" autocomplete="new-password" minlength="8" placeholder="再次输入" required></div></div>
                    <div class="gf-form-actions gf-mt-16">${C().button('保存新密码', 'check', 'primary', '', 'submit')}</div>
                </form>
            </div>
            <div data-dialog-footer><a class="gf-button" href="${pageHref('admin-users-index')}">${C().icon('users')}<span>用户与权限管理</span></a>${C().button('关闭', 'x', 'secondary', 'data-modal-close')}</div>
        </template>
        <template data-shell-dialog-template="qr" data-dialog-title="GEOFlow 移动访问" data-dialog-message="扫码打开当前 GEOFlow 工作台演示入口">
            <div data-dialog-body>
                <div class="gf-qr-card">
                    <div class="gf-qr-code" role="img" aria-label="GEOFlow 演示二维码">
                        <span class="gf-qr-finder gf-qr-finder--tl"></span><span class="gf-qr-finder gf-qr-finder--tr"></span><span class="gf-qr-finder gf-qr-finder--bl"></span>
                        ${Array.from({ length: 361 }, (_, index) => {
                            const x = index % 19;
                            const y = Math.floor(index / 19);
                            const inFinder = (x < 6 && y < 6) || (x > 12 && y < 6) || (x < 6 && y > 12);
                            const dark = ((x * x + y * y + 3 * x * y + x + 5 * y) % 11 < 5) || ((x + 2 * y) % 7 === 0);
                            return !inFinder && dark ? `<i style="--qr-x:${x};--qr-y:${y}"></i>` : '';
                        }).join('')}
                        <b>G</b>
                    </div>
                    <div class="gf-qr-card__copy"><strong>GEOFlow 管理后台</strong><span>使用手机扫码查看移动端适配效果</span><code>127.0.0.1:4173</code></div>
                </div>
                <div class="gf-callout gf-mt-20">${C().icon('info')}<div>当前二维码用于静态原型展示，接入正式域名后可替换为真实访问地址。</div></div>
            </div>
            <div data-dialog-footer>${C().button('复制访问地址', 'copy', 'secondary', 'data-copy-prototype-url')}${C().button('关闭', 'x', 'primary', 'data-modal-close')}</div>
        </template>
        <template data-shell-dialog-template="settings" data-dialog-title="快捷设置" data-dialog-message="集中进入常用的网站、AI 与安全配置">
            <div data-dialog-body>
                <div class="gf-settings-shortcuts">
                    <a href="${pageHref('site-settings-index')}"><span class="gf-settings-shortcuts__icon">${C().icon('globe-2')}</span><span><strong>网站设置</strong><small>站点信息、品牌、SEO、首页模块与用户</small></span>${C().icon('chevron-right')}</a>
                    <a href="${pageHref('ai-configurator')}"><span class="gf-settings-shortcuts__icon">${C().icon('bot')}</span><span><strong>AI配置器</strong><small>模型、提示词、工作流与专用能力</small></span>${C().icon('chevron-right')}</a>
                    <a href="${pageHref('security-settings-index')}"><span class="gf-settings-shortcuts__icon">${C().icon('shield-check')}</span><span><strong>安全设置</strong><small>内容安全、登录安全与操作审计</small></span>${C().icon('chevron-right')}</a>
                    <a href="${pageHref('admin-users-index')}"><span class="gf-settings-shortcuts__icon">${C().icon('users')}</span><span><strong>用户与权限</strong><small>管理员账号、角色权限与账户状态</small></span>${C().icon('chevron-right')}</a>
                </div>
            </div>
            <div data-dialog-footer><span class="gf-modal__hint">GEOFlow v2.0 · UI V2 原型</span>${C().button('关闭', 'x', 'secondary', 'data-modal-close')}</div>
        </template>`;
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
        ${dialogTemplates()}
        <div class="gf-toast" role="status" data-toast>原型操作已完成</div>`;
    }

    window.GeoFlowShell = { navigation, recentItems, pageById, pageHref, render };
}());
