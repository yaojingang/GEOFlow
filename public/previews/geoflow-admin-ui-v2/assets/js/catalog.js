(function () {
    'use strict';

    const root = document.getElementById('prototype-root');
    const view = document.body.dataset.rootView || 'catalog';
    const { groups, pages } = window.GEOFLOW_UI_V2;
    const C = window.GeoFlowComponents;

    function rootHeader(title, subtitle) {
        return `<a class="gf-skip-link gf-sr-only" href="#main-content">跳到主要内容</a><header class="gf-topbar"><a class="gf-wordmark" href="index.html">GEOFlow</a><div class="gf-topbar__context gf-root-context">${C.icon('layout-dashboard')}<span class="gf-topbar__title">${C.escapeHtml(title)}</span></div><div class="gf-topbar__actions"><a class="gf-button gf-root-action" href="component-gallery.html" aria-label="组件展示">${C.icon('blocks')}<span>组件展示</span></a><a class="gf-button gf-root-action" href="qa-matrix.html" aria-label="检查矩阵">${C.icon('list-checks')}<span>检查矩阵</span></a></div></header><main class="gf-main" id="main-content"><div class="gf-content"><div class="gf-catalog-hero"><div><h1 class="gf-page-title">${C.escapeHtml(title)}</h1><p class="gf-page-subtitle">${C.escapeHtml(subtitle)}</p></div><div class="gf-catalog-summary">${C.badge(`${pages.length} 个页面`, 'blue')}${C.badge('独立 CSS / JS', 'green')}${C.badge('演示数据', 'violet')}</div></div>`;
    }

    function rootFooter() {
        return `</div></main><footer class="gf-footer">© 2026 GEOFlow · Admin UI V2 独立原型包</footer>`;
    }

    function catalog() {
        const groupsMarkup = groups.map((group) => {
            const groupPages = pages.filter((page) => page.group === group.id);
            return `<section class="gf-catalog-group" data-catalog-group><div class="gf-catalog-group__head"><div class="gf-catalog-group__copy"><h2>${C.escapeHtml(group.label)}</h2><p class="gf-card__subtitle">${C.escapeHtml(group.description)}</p></div>${C.badge(`${groupPages.length} 页`, 'blue')}</div><div class="gf-catalog-list">${groupPages.map((page) => `<a class="gf-catalog-link" href="pages/${page.path}" data-catalog-page data-search="${C.escapeHtml(`${page.title} ${page.subtitle} ${page.route}`)}"><div class="gf-catalog-link__meta"><span>#${String(page.number).padStart(2, '0')}</span><span>${page.role === 'super_admin' ? '超级管理员' : page.role === 'guest' ? '访客' : '管理员'}</span></div><div class="gf-catalog-link__title">${C.icon(page.icon)} ${C.escapeHtml(page.title)}</div><div class="gf-catalog-link__desc">${C.escapeHtml(page.subtitle)}</div><div class="gf-catalog-link__route">${C.escapeHtml(page.route)}</div></a>`).join('')}</div></section>`;
        }).join('');

        root.innerHTML = `${rootHeader('GEOFlow Admin UI V2 原型目录', '按照两个 GEOFlow 基准原型统一生成的完整后台页面包。')}<section class="gf-card gf-mb-28"><div class="gf-filterbar"><label class="gf-search"><span class="gf-sr-only">搜索页面、模块或路由</span>${C.icon('search')}<input class="gf-input" type="search" placeholder="搜索页面、模块或路由" data-catalog-search></label><span class="gf-filterbar__spacer"></span><a class="gf-button" href="../geoflow-ai-workspace-prototype.html">${C.icon('sparkles')}AI 基准原型</a><a class="gf-button" href="../geoflow-sidebar-layout-prototype.html">${C.icon('panel-left')}布局基准原型</a></div></section><section class="gf-card gf-catalog-empty" role="status" data-catalog-empty hidden><div class="gf-empty"><span class="gf-empty__icon">${C.icon('search-x')}</span><h2>没有匹配的页面</h2><p>请尝试输入页面名称、业务模块或路由关键词。</p></div></section>${groupsMarkup}${rootFooter()}`;

        const search = document.querySelector('[data-catalog-search]');
        search?.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();
            document.querySelectorAll('[data-catalog-page]').forEach((card) => {
                card.hidden = query !== '' && !card.dataset.search.toLowerCase().includes(query);
            });
            document.querySelectorAll('[data-catalog-group]').forEach((group) => {
                group.hidden = !group.querySelector('[data-catalog-page]:not([hidden])');
            });
            const visibleCount = document.querySelectorAll('[data-catalog-page]:not([hidden])').length;
            const empty = document.querySelector('[data-catalog-empty]');
            if (empty) empty.hidden = visibleCount > 0;
        });
    }

    function components() {
        root.innerHTML = `${rootHeader('公共组件展示', '所有原型页面复用同一套组件结构、颜色、圆角和交互尺寸。')}
        <div class="gf-section-stack">
            <section class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">按钮</h2><p class="gf-card__subtitle">40px 标准操作和统一 8px 圆角</p></div></div><div class="gf-card__body"><div class="gf-component-sample">${C.button('主要操作', 'plus', 'primary')}${C.button('次要操作', 'refresh-cw')}${C.button('危险操作', 'trash-2', 'danger')}${C.button('安静操作', 'settings', 'quiet')}<button class="gf-icon-button" type="button" aria-label="更多">${C.icon('ellipsis')}</button></div></div></section>
            <section class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">状态</h2><p class="gf-card__subtitle">蓝、绿、黄、红和中性色语义</p></div></div><div class="gf-card__body"><div class="gf-component-sample">${C.status('运行中', 'working')}${C.status('已完成', 'success')}${C.status('待确认', 'warning')}${C.status('需要关注', 'danger')}${C.status('草稿', 'neutral')}${C.badge('基础能力可用', 'green')}${C.badge('有更新', 'red')}</div></div></section>
            <section class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">表单</h2><p class="gf-card__subtitle">统一 40px 控件和 Blue 600 焦点状态</p></div></div><div class="gf-card__body"><div class="gf-field-grid"><div class="gf-field"><label class="gf-label" for="gallery-task-name">任务名称<span class="gf-label__required" aria-hidden="true">*</span></label><input class="gf-input" id="gallery-task-name" value="品牌问题地图优化" required></div><div class="gf-field"><label class="gf-label" for="gallery-task-status">任务状态</label><select class="gf-select" id="gallery-task-status"><option>运行中</option></select></div><div class="gf-field gf-field--full"><label class="gf-label" for="gallery-task-description">任务说明</label><textarea class="gf-textarea" id="gallery-task-description">组合问题地图、知识资产和质量门禁，生成 8 篇可审核内容。</textarea></div></div></div></section>
            <section class="gf-card"><div class="gf-card__header"><div><h2 class="gf-card__title">空状态与提示</h2><p class="gf-card__subtitle">同一页面母版可覆盖完整状态</p></div></div><div class="gf-card__body"><div class="gf-callout">${C.icon('info')}<div>当前内容为静态演示，不会提交到 GEOFlow 后台。</div></div><div class="gf-callout gf-callout--warning gf-mt-12">${C.icon('triangle-alert')}<div>执行高风险操作前需要核对目标和影响范围。</div></div></div></section>
        </div>${rootFooter()}`;
    }

    function qa() {
        root.innerHTML = `${rootHeader('页面检查矩阵', '每个页面都可以直接查看正常、空、加载、错误和无权限状态。')}<section class="gf-card"><div class="gf-table-wrap"><table class="gf-table gf-qa-table"><thead><tr><th>#</th><th>页面</th><th>模块</th><th>正常</th><th>空状态</th><th>加载</th><th>错误</th><th>无权限</th></tr></thead><tbody>${pages.map((page) => `<tr><td>${String(page.number).padStart(2, '0')}</td><td><div class="gf-table__primary">${C.escapeHtml(page.title)}</div><div class="gf-table__secondary">${C.escapeHtml(page.route)}</div></td><td>${C.escapeHtml(groups.find((group) => group.id === page.group)?.label || page.group)}</td>${['normal', 'empty', 'loading', 'error', 'permission'].map((state) => `<td><a class="gf-qa-check" href="pages/${page.path}${state === 'normal' ? '' : `?state=${state}`}">查看</a></td>`).join('')}</tr>`).join('')}</tbody></table></div><footer class="gf-card__footer">${C.badge(`${pages.length} 个页面`, 'blue')}<span class="gf-help">建议在 1440、1280、768 和 375px 宽度下检查。</span></footer></section>${rootFooter()}`;
    }

    if (view === 'components') components();
    else if (view === 'qa') qa();
    else catalog();

    window.lucide?.createIcons();
}());
