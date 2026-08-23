import '../css/admin-ui-v3-stability.css';
import './admin/ui-v3-shell';
import './bootstrap';

const loadPageModule = (selector, loader) => {
    if (!document.querySelector(selector)) return;
    void loader();
};

loadPageModule('#article-create-assistant', () => import('./admin/article-create-assistant'));
loadPageModule('[data-copy-target]', () => import('./admin/manual-publications'));
loadPageModule('[data-analytics-log-chart], [data-analytics-trend], [data-analytics-filter-form]', () => import('./admin/analytics'));
loadPageModule('[data-ai-workspace]', () => import('./admin/ai-workspace'));
