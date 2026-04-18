<?php
/**
 * 智能GEO内容系统 - URL智能采集
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2026-03-27
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin_login();

session_write_close();

$message = '';
$error = '';

$stats = [
    'knowledge_bases' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases")->fetch()['count'] ?? 0,
    'keyword_libraries' => $db->query("SELECT COUNT(*) as count FROM keyword_libraries")->fetch()['count'] ?? 0,
    'title_libraries' => $db->query("SELECT COUNT(*) as count FROM title_libraries")->fetch()['count'] ?? 0,
    'image_libraries' => $db->query("SELECT COUNT(*) as count FROM image_libraries")->fetch()['count'] ?? 0
];

$authors = $db->query("SELECT id, name FROM authors ORDER BY name ASC")->fetchAll();
$knowledge_bases = $db->query("SELECT id, name FROM knowledge_bases ORDER BY name ASC")->fetchAll();
$keyword_libraries = $db->query("SELECT id, name FROM keyword_libraries ORDER BY name ASC")->fetchAll();
$title_libraries = $db->query("SELECT id, name FROM title_libraries ORDER BY name ASC")->fetchAll();
$image_libraries = $db->query("SELECT id, name FROM image_libraries ORDER BY name ASC")->fetchAll();

$default_values = [
    'url' => '',
    'project_name' => '',
    'source_label' => '',
    'content_language' => 'zh-CN',
    'notes' => '',
    'target_knowledge_base_id' => '',
    'target_keyword_library_id' => '',
    'target_title_library_id' => '',
    'target_image_library_id' => '',
    'target_author_id' => '',
    'enable_ai_cleaning' => '1',
    'enable_semantic_analysis' => '1',
    'import_knowledge' => '1',
    'import_keywords' => '1',
    'import_titles' => '1',
    'import_images' => '1',
    'allow_duplicate_import' => '0',
    'image_strategy' => 'body_only'
];

$page_title = __('url_import.page_title');
$page_header = '
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . __('url_import.page_heading') . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . __('url_import.page_subtitle') . '</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <a href="materials.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
            ' . __('url_import.button.back_to_materials') . '
        </a>
        <a href="url-import-history.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="history" class="w-4 h-4 mr-2"></i>
            ' . __('url_import.button.view_history') . '
        </a>
        <a href="../docs/URL智能采集PRD.md" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-slate-900 hover:bg-slate-800">
            <i data-lucide="file-text" class="w-4 h-4 mr-2"></i>
            ' . __('url_import.button.view_prd') . '
        </a>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
            <div class="xl:col-span-2 bg-white shadow rounded-xl overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><?php echo __('url_import.section.new_job'); ?></h2>
                    <p class="mt-1 text-sm text-gray-500"><?php echo __('url_import.section.new_job_desc'); ?></p>
                </div>
                <form id="url-import-form" class="px-6 py-6 space-y-8">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?php echo __('url_import.section.source'); ?></h3>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label for="url" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.url'); ?></label>
                                <input type="url" id="url" name="url" value="<?php echo htmlspecialchars($default_values['url']); ?>" placeholder="https://example.com/article" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <p class="mt-2 text-xs text-gray-500"><?php echo __('url_import.help.url'); ?></p>
                            </div>
                            <div>
                                <label for="project_name" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.project_name'); ?></label>
                                <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($default_values['project_name']); ?>" placeholder="<?php echo __('url_import.placeholder.project_name'); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="source_label" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.source_label'); ?></label>
                                <input type="text" id="source_label" name="source_label" value="<?php echo htmlspecialchars($default_values['source_label']); ?>" placeholder="<?php echo __('url_import.placeholder.source_label'); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="content_language" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.content_language'); ?></label>
                                <select id="content_language" name="content_language" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="zh-CN" selected><?php echo __('locale.zh-CN'); ?></option>
                                    <option value="en"><?php echo __('locale.en'); ?></option>
                                    <option value="auto"><?php echo __('url_import.option.auto_detect'); ?></option>
                                </select>
                            </div>
                            <div>
                                <label for="target_author_id" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.author'); ?></label>
                                <select id="target_author_id" name="target_author_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('url_import.option.not_specified'); ?></option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo (int) $author['id']; ?>"><?php echo htmlspecialchars($author['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label for="notes" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.notes'); ?></label>
                                <textarea id="notes" name="notes" rows="3" placeholder="<?php echo __('url_import.placeholder.notes'); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"><?php echo htmlspecialchars($default_values['notes']); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?php echo __('url_import.section.strategy'); ?></h3>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="flex items-start p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="enable_ai_cleaning" checked class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3">
                                    <span class="block text-sm font-medium text-gray-900"><?php echo __('url_import.strategy.ai_clean'); ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo __('url_import.strategy.ai_clean_desc'); ?></span>
                                </span>
                            </label>
                            <label class="flex items-start p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="enable_semantic_analysis" checked class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3">
                                    <span class="block text-sm font-medium text-gray-900"><?php echo __('url_import.strategy.semantic'); ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo __('url_import.strategy.semantic_desc'); ?></span>
                                </span>
                            </label>
                            <label class="flex items-start p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="capture_body_images" checked class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3">
                                    <span class="block text-sm font-medium text-gray-900"><?php echo __('url_import.strategy.images'); ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo __('url_import.strategy.images_desc'); ?></span>
                                </span>
                            </label>
                            <label class="flex items-start p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="allow_duplicate_import" class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3">
                                    <span class="block text-sm font-medium text-gray-900"><?php echo __('url_import.strategy.allow_duplicate'); ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo __('url_import.strategy.allow_duplicate_desc'); ?></span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?php echo __('url_import.section.targets'); ?></h3>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="target_knowledge_base_id" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.target_knowledge_base'); ?></label>
                                <select id="target_knowledge_base_id" name="target_knowledge_base_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('url_import.option.create_or_later'); ?></option>
                                    <?php foreach ($knowledge_bases as $knowledge_base): ?>
                                        <option value="<?php echo (int) $knowledge_base['id']; ?>"><?php echo htmlspecialchars($knowledge_base['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="target_keyword_library_id" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.target_keyword_library'); ?></label>
                                <select id="target_keyword_library_id" name="target_keyword_library_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('url_import.option.create_or_later'); ?></option>
                                    <?php foreach ($keyword_libraries as $keyword_library): ?>
                                        <option value="<?php echo (int) $keyword_library['id']; ?>"><?php echo htmlspecialchars($keyword_library['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="target_title_library_id" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.target_title_library'); ?></label>
                                <select id="target_title_library_id" name="target_title_library_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('url_import.option.create_or_later'); ?></option>
                                    <?php foreach ($title_libraries as $title_library): ?>
                                        <option value="<?php echo (int) $title_library['id']; ?>"><?php echo htmlspecialchars($title_library['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="target_image_library_id" class="block text-sm font-medium text-gray-700"><?php echo __('url_import.field.target_image_library'); ?></label>
                                <select id="target_image_library_id" name="target_image_library_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('url_import.option.create_or_later'); ?></option>
                                    <?php foreach ($image_libraries as $image_library): ?>
                                        <option value="<?php echo (int) $image_library['id']; ?>"><?php echo htmlspecialchars($image_library['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?php echo __('url_import.section.outputs'); ?></h3>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <label class="flex items-center p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="import_knowledge" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3 text-sm text-gray-700"><?php echo __('url_import.output.knowledge'); ?></span>
                            </label>
                            <label class="flex items-center p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="import_keywords" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3 text-sm text-gray-700"><?php echo __('url_import.output.keywords'); ?></span>
                            </label>
                            <label class="flex items-center p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="import_titles" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3 text-sm text-gray-700"><?php echo __('url_import.output.titles'); ?></span>
                            </label>
                            <label class="flex items-center p-4 border border-gray-200 rounded-lg">
                                <input type="checkbox" name="import_images" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <span class="ml-3 text-sm text-gray-700"><?php echo __('url_import.output.images'); ?></span>
                            </label>
                        </div>
                    </section>

                    <div class="flex flex-col sm:flex-row gap-3 pt-2">
                        <button type="submit" id="url-import-submit" class="inline-flex items-center justify-center px-4 py-2.5 rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="wand-2" class="w-4 h-4 mr-2"></i>
                            <?php echo __('url_import.button.start'); ?>
                        </button>
                        <button type="button" class="inline-flex items-center justify-center px-4 py-2.5 rounded-md text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                            <?php echo __('url_import.button.save_draft'); ?>
                        </button>
                    </div>
                    <div id="url-import-inline-error" class="hidden rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>
                </form>
            </div>

            <div class="space-y-6">
                <div class="bg-white shadow rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-gray-900"><?php echo __('url_import.section.capacity'); ?></h2>
                    <div class="mt-4 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('url_import.stats.knowledge_bases'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo __('url_import.value.count_units', ['count' => (int) $stats['knowledge_bases']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('url_import.stats.keyword_libraries'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo __('url_import.value.count_units', ['count' => (int) $stats['keyword_libraries']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('url_import.stats.title_libraries'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo __('url_import.value.count_units', ['count' => (int) $stats['title_libraries']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('url_import.stats.image_libraries'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo __('url_import.value.count_units', ['count' => (int) $stats['image_libraries']]); ?></span>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-gray-900"><?php echo __('url_import.section.scope'); ?></h2>
                    <ul class="mt-4 space-y-3 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i data-lucide="check-circle-2" class="w-4 h-4 text-green-600 mr-2 mt-0.5"></i>
                            <?php echo __('url_import.scope.single_page'); ?>
                        </li>
                        <li class="flex items-start">
                            <i data-lucide="check-circle-2" class="w-4 h-4 text-green-600 mr-2 mt-0.5"></i>
                            <?php echo __('url_import.scope.preview_first'); ?>
                        </li>
                        <li class="flex items-start">
                            <i data-lucide="shield-check" class="w-4 h-4 text-cyan-600 mr-2 mt-0.5"></i>
                            <?php echo __('url_import.scope.security'); ?>
                        </li>
                    </ul>
                </div>

                <div class="bg-slate-900 text-slate-100 shadow rounded-xl p-6">
                    <div class="flex items-center">
                        <i data-lucide="lightbulb" class="w-5 h-5 text-cyan-300 mr-2"></i>
                        <h2 class="text-lg font-semibold"><?php echo __('url_import.section.recommendation'); ?></h2>
                    </div>
                    <p class="mt-3 text-sm text-slate-300 leading-7">
                        <?php echo __('url_import.recommendation.copy'); ?>
                    </p>
                </div>
            </div>
        </div>

        <div id="url-import-progress-panel" class="hidden bg-white shadow rounded-xl overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?php echo __('url_import.section.progress'); ?></h2>
                        <p id="url-import-current-step" class="mt-1 text-sm text-gray-500"><?php echo __('url_import.progress.waiting'); ?></p>
                    </div>
                    <div id="url-import-progress-text" class="text-sm font-medium text-blue-700">0%</div>
                </div>
                <div class="mt-4 h-2.5 rounded-full bg-gray-100 overflow-hidden">
                    <div id="url-import-progress-bar" class="h-full w-0 bg-blue-600 rounded-full transition-all duration-500"></div>
                </div>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 px-6 py-6">
                <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500"><?php echo __('url_import.section.stage_status'); ?></h3>
                    <div id="url-import-stage-list" class="mt-4 space-y-2 text-sm text-gray-700"></div>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500"><?php echo __('url_import.section.logs'); ?></h3>
                        <div id="url-import-log-status" class="text-xs text-gray-400"><?php echo __('url_import.progress.waiting'); ?></div>
                    </div>
                    <div id="url-import-log-box" class="mt-4 h-64 overflow-y-auto rounded-lg border border-slate-200 bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-100"></div>
                </div>
            </div>
        </div>

        <div id="url-import-result-panel" class="hidden bg-white shadow rounded-xl overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?php echo __('url_import.section.result_preview'); ?></h2>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('url_import.section.result_preview_desc'); ?></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div id="url-import-commit-status" class="hidden text-sm text-emerald-700"></div>
                        <button type="button" id="url-import-commit-button" class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700">
                            <i data-lucide="database" class="w-4 h-4 mr-2"></i>
                            <?php echo __('url_import.button.commit'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 px-6 py-6">
                <div>
                    <div class="text-sm font-medium text-gray-900"><?php echo __('url_import.preview.summary'); ?></div>
                    <div id="url-import-summary" class="mt-3 text-sm leading-7 text-gray-600"></div>
                    <div class="mt-6 text-sm font-medium text-gray-900"><?php echo __('url_import.preview.knowledge'); ?></div>
                    <div id="url-import-knowledge-preview" class="mt-3 text-sm leading-7 text-gray-700"></div>
                </div>
                <div class="space-y-6">
                    <div>
                        <div class="text-sm font-medium text-gray-900"><?php echo __('url_import.preview.keywords'); ?></div>
                        <div id="url-import-keywords" class="mt-3 flex flex-wrap gap-2"></div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-900"><?php echo __('url_import.preview.titles'); ?></div>
                        <ul id="url-import-titles" class="mt-3 space-y-2 text-sm text-gray-700"></ul>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-900"><?php echo __('url_import.preview.images'); ?></div>
                        <ul id="url-import-images" class="mt-3 space-y-2 text-sm text-gray-700"></ul>
                    </div>
                </div>
            </div>
        </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('url-import-form');
    const submitButton = document.getElementById('url-import-submit');
    const errorBox = document.getElementById('url-import-inline-error');
    const progressPanel = document.getElementById('url-import-progress-panel');
    const resultPanel = document.getElementById('url-import-result-panel');
    const progressText = document.getElementById('url-import-progress-text');
    const progressBar = document.getElementById('url-import-progress-bar');
    const currentStepText = document.getElementById('url-import-current-step');
    const stageList = document.getElementById('url-import-stage-list');
    const logBox = document.getElementById('url-import-log-box');
    const logStatus = document.getElementById('url-import-log-status');
    const summaryBox = document.getElementById('url-import-summary');
    const knowledgePreviewBox = document.getElementById('url-import-knowledge-preview');
    const keywordsBox = document.getElementById('url-import-keywords');
    const titlesBox = document.getElementById('url-import-titles');
    const imagesBox = document.getElementById('url-import-images');
    const commitButton = document.getElementById('url-import-commit-button');
    const commitStatus = document.getElementById('url-import-commit-status');

    let currentJobId = null;
    let pollTimer = null;

    function setError(message) {
        errorBox.textContent = message || '';
        errorBox.classList.toggle('hidden', !message);
    }

    function resetResultAreas() {
        summaryBox.textContent = '';
        knowledgePreviewBox.textContent = '';
        keywordsBox.innerHTML = '';
        titlesBox.innerHTML = '';
        imagesBox.innerHTML = '';
        commitStatus.textContent = '';
        commitStatus.classList.add('hidden');
        commitButton.disabled = false;
        commitButton.classList.remove('opacity-60', 'cursor-not-allowed');
        logBox.textContent = '';
        stageList.innerHTML = '';
    }

    function renderStageList(stepLabels, job) {
        stageList.innerHTML = '';
        Object.entries(stepLabels || {}).forEach(([key, meta]) => {
            const label = typeof meta === 'object' && meta !== null ? (meta.label || key) : meta;
            const item = document.createElement('div');
            const isCurrent = job.current_step === key;
            const stepOrder = ['queued', 'fetch', 'extract', 'images', 'ai_clean', 'keywords', 'titles', 'knowledge', 'completed'];
            const currentIndex = stepOrder.indexOf(job.current_step);
            const stepIndex = stepOrder.indexOf(key);
            const isDone = job.status === 'completed' || (stepIndex !== -1 && currentIndex !== -1 && stepIndex < currentIndex);
            item.className = 'flex items-center justify-between rounded-md border px-3 py-2 ' + (isCurrent ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50');
            item.innerHTML = `
                <span>${label}</span>
                <span class="text-xs ${isCurrent ? 'text-blue-700' : 'text-gray-400'}">${isCurrent ? '<?php echo addslashes(__('url_import.progress.in_progress')); ?>' : (isDone ? '<?php echo addslashes(__('url_import.progress.done')); ?>' : '<?php echo addslashes(__('url_import.progress.waiting_short')); ?>')}</span>
            `;
            stageList.appendChild(item);
        });
    }

    function renderResult(result) {
        resultPanel.classList.remove('hidden');
        summaryBox.textContent = result.summary || '<?php echo addslashes(__('url_import.preview.empty_summary')); ?>';
        if (result.knowledge_preview && typeof result.knowledge_preview === 'object') {
            const title = result.knowledge_preview.title || '';
            const content = result.knowledge_preview.content || '';
            knowledgePreviewBox.innerHTML = `<div class="font-medium text-gray-900">${title}</div><div class="mt-2">${content}</div>`;
        } else {
            knowledgePreviewBox.textContent = result.knowledge_preview || '<?php echo addslashes(__('url_import.preview.empty_knowledge')); ?>';
        }
        keywordsBox.innerHTML = '';
        (result.keywords || []).forEach((keyword) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700';
            chip.textContent = keyword;
            keywordsBox.appendChild(chip);
        });
        titlesBox.innerHTML = '';
        (result.titles || []).forEach((title) => {
            const item = document.createElement('li');
            item.className = 'rounded-md border border-gray-200 bg-gray-50 px-3 py-2';
            item.textContent = title;
            titlesBox.appendChild(item);
        });
        imagesBox.innerHTML = '';
        (result.images || []).forEach((image) => {
            const item = document.createElement('li');
            item.className = 'rounded-md border border-gray-200 bg-gray-50 px-3 py-2';
            item.textContent = `${image.label || image.name || '<?php echo addslashes(__('url_import.preview.unnamed_image')); ?>'}${image.source ? ' · ' + image.source : ''}`;
            imagesBox.appendChild(item);
        });

        if (result.import_result && result.import_result.imported_at) {
            commitButton.disabled = true;
            commitButton.classList.add('opacity-60', 'cursor-not-allowed');
            commitStatus.textContent = `<?php echo addslashes(__('url_import.commit.imported_at_prefix')); ?>${result.import_result.imported_at}`;
            commitStatus.classList.remove('hidden');
        }
    }

    async function pollStatus() {
        if (!currentJobId) return;
        const response = await fetch(`${window.adminUrl('url-import-status.php')}?job_id=${currentJobId}`, {
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || '<?php echo addslashes(__('url_import.error.fetch_status_failed')); ?>');
        }

        progressPanel.classList.remove('hidden');
        progressText.textContent = `${data.job.progress_percent}%`;
        progressBar.style.width = `${data.job.progress_percent}%`;
        currentStepText.textContent = data.step_labels?.[data.job.current_step] || data.job.current_step || '<?php echo addslashes(__('url_import.progress.processing')); ?>';
        logStatus.textContent = data.job.status === 'completed' ? '<?php echo addslashes(__('url_import.progress.completed')); ?>' : '<?php echo addslashes(__('url_import.progress.processing')); ?>';
        renderStageList(data.step_labels, data.job);
        logBox.textContent = (data.logs || []).map((log) => `[${log.created_at}] ${log.message}`).join('\n');
        logBox.scrollTop = logBox.scrollHeight;

        if (data.job.status === 'completed') {
            AdminUtils.hideLoading(submitButton);
            renderResult(data.result || {});
            clearInterval(pollTimer);
            pollTimer = null;
            return;
        }

        if (data.job.status === 'failed') {
            AdminUtils.hideLoading(submitButton);
            setError(data.job.error_message || '<?php echo addslashes(__('url_import.error.job_failed')); ?>');
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        setError('');
        resultPanel.classList.add('hidden');
        progressPanel.classList.remove('hidden');
        resetResultAreas();
        AdminUtils.showLoading(submitButton);

        try {
            const response = await fetch(window.adminUrl('url-import-start.php'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || '<?php echo addslashes(__('url_import.error.start_failed')); ?>');
            }

            currentJobId = data.job_id;
            await pollStatus();
            clearInterval(pollTimer);
            pollTimer = setInterval(() => {
                pollStatus().catch((error) => {
                    clearInterval(pollTimer);
                    pollTimer = null;
                    AdminUtils.hideLoading(submitButton);
                    setError(error.message);
                });
            }, 2000);
        } catch (error) {
            AdminUtils.hideLoading(submitButton);
            setError(error.message || '<?php echo addslashes(__('url_import.error.start_failed')); ?>');
        }
    });

    commitButton.addEventListener('click', async function () {
        if (!currentJobId) {
            setError('<?php echo addslashes(__('url_import.error.commit_before_parse')); ?>');
            return;
        }

        commitButton.disabled = true;
        commitButton.classList.add('opacity-60', 'cursor-not-allowed');

        try {
            const payload = new FormData();
            payload.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            payload.append('job_id', String(currentJobId));

            const response = await fetch(window.adminUrl('url-import-commit.php'), {
                method: 'POST',
                body: payload,
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || '<?php echo addslashes(__('url_import.error.commit_failed')); ?>');
            }

            commitStatus.textContent = data.message || '<?php echo addslashes(__('url_import.commit.success')); ?>';
            commitStatus.classList.remove('hidden');
            await pollStatus();
        } catch (error) {
            commitButton.disabled = false;
            commitButton.classList.remove('opacity-60', 'cursor-not-allowed');
            setError(error.message || '<?php echo addslashes(__('url_import.error.commit_failed')); ?>');
        }
    });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
