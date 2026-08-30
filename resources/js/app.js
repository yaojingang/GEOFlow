import '../css/admin-ui-v3-stability.css';
import '../css/admin-action-dialog.css';
import './pwa';
import './admin/action-dialog';
import './admin/ui-v3-shell';
import './admin/article-batch-export';
import { loadAiSourceProvidersIndex } from './admin/ai-source-providers-loader';
import { loadArticleAiQualityProgress } from './admin/article-ai-quality-progress-loader';
import { loadLibraryDetailActions } from './admin/library-detail-actions-loader';
import { loadTitleGenerationProgress } from './admin/title-generation-progress-loader';
import './admin/library-entry-form';
import './bootstrap';

const loadPageModule = (selector, loader) => {
    if (!document.querySelector(selector)) return;
    void loader();
};

loadPageModule('#article-create-assistant', () => import('./admin/article-create-assistant'));
loadPageModule('[data-copy-target]', () => import('./admin/manual-publications'));
loadPageModule('[data-analytics-log-chart], [data-analytics-trend], [data-analytics-filter-form]', () => import('./admin/analytics'));
loadPageModule('[data-system-updater-auto-reload], [data-system-updater-copy], [data-system-updater-error-dialog], [data-system-updater-authorized-action]', () => import('./admin/system-updates'));
loadPageModule('[data-ai-workspace]', () => import('./admin/ai-workspace'));
loadPageModule('[data-ai-model-create-form]', () => import('./admin/ai-model-create'));
loadPageModule('[data-ai-model-edit-form]', () => import('./admin/ai-model-edit'));
loadPageModule('[data-ai-source-providers-index]', () => loadAiSourceProvidersIndex(
    document.querySelector('[data-ai-source-providers-index]'),
    () => import('./admin/ai-source-providers-index'),
));
loadPageModule('[data-task-form]', () => import('./admin/task-form'));
loadPageModule('[data-task-index-readiness-dialog]', () => import('./admin/task-index-readiness'));
loadPageModule('[data-title-generation-progress]', () => loadTitleGenerationProgress(
    document.querySelector('[data-title-generation-progress]'),
    () => import('./admin/title-generation-progress'),
));
loadPageModule('[data-ai-quality-progress]', () => loadArticleAiQualityProgress(
    document.querySelector('[data-ai-quality-progress]'),
    () => import('./admin/article-ai-quality-progress'),
));
loadPageModule('[data-ai-quality-collapsible]', () => import('./admin/article-ai-quality-collapse'));
loadPageModule('[data-ai-optimization-panel]', () => import('./admin/article-ai-optimization'));
loadPageModule('[data-title-generation-form]', () => import('./admin/title-generation-form'));
loadPageModule('[data-materials-standalone], [data-image-upload-form]', () => import('./admin/materials-standalone'));
loadPageModule('[data-library-detail-actions]', () => loadLibraryDetailActions(
    document.querySelector('[data-library-detail-actions]'),
    () => import('./admin/library-detail-actions'),
));
