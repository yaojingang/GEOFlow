(function () {
    'use strict';

    const pageId = document.body.dataset.pageId;
    const root = document.getElementById('prototype-root');
    const page = window.GEOFLOW_UI_V2.pages.find((candidate) => candidate.id === pageId);

    if (!root || !page) {
        document.body.innerHTML = '<main class="gf-login-page"><section class="gf-login-card"><div class="gf-login-brand">GEOFlow</div><h1>原型页面配置缺失</h1><p>请从原型目录重新进入页面。</p></section></main>';
        return;
    }

    const state = new URLSearchParams(window.location.search).get('state') || 'normal';
    const content = window.GeoFlowComponents.renderPage(page, state);
    root.innerHTML = window.GeoFlowShell.render(page, content);
    window.GeoFlowInteractions.init();
    window.lucide?.createIcons();
}());
