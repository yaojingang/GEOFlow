<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function get_admin_welcome_copy(): array {
    return [
        'zh-CN' => [
            'meta' => [
                'badge' => '写在开始之前',
                'switch_label' => 'English',
                'close' => '关闭',
                'links_label' => '欢迎持续关注项目更新，也欢迎交流与合作。',
                'author_link' => '作者 X 主页',
                'github_link' => '项目 GitHub',
                'changelog_link' => '更新日志',
            ],
            'letter' => [
                'title' => '欢迎使用 GEOFlow',
                'subtitle' => '你好，欢迎来到 GEOFlow。',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'content' => '如果你现在正在看这个页面，说明你已经进入了 GEOFlow 的管理后台。先不用急着点功能，我想先用一封简短的见面信，告诉你这套系统是做什么的，它适合什么样的工作，以及我接下来还会把它继续往哪里推进。',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'GEOFlow 不是一个简单的 CMS，也不只是一个 AI 写作工具。它更像一套围绕 GEO 工程设计构建的内容操作系统：把模型配置、素材管理、任务调度、内容生成、审核发布、前台展示，以及 Skill、CLI、API 协作能力，串成一条完整的工作链路。',
                    ],
                    [
                        'type' => 'heading',
                        'content' => '你可以用 GEOFlow 做什么',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            '配置并管理多个 AI 模型',
                            '管理标题库、关键词库、图片库、知识库与提示词',
                            '创建内容任务，自动调度、自动入队、自动生成',
                            '跑通草稿、审核、发布的完整工作流',
                            '搭建适配搜索与 AI 引用场景的前台内容页面',
                            '通过 Skill、CLI、API 持续扩展系统能力',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => '这套系统适合什么场景',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            '官网 GEO 优化与 AI 搜索适配',
                            'GEO 资讯频道、行业内容频道搭建',
                            'GEO 专题站、栏目站、站群系统',
                            '自动化、智能化的 GEO 内容生产系统',
                            '面向 AI 搜索、答案引用、品牌信源建设的内容基础设施',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => '我为什么这样设计它',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'GEOFlow 的出发点不是页面管理，而是内容工程。我更关注的是：一套系统能不能把任务、素材、模型、提示词、审核、发布和前台展示统一起来，并且真正适配 AI 搜索和生成式引擎的内容需求。',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => '所以你会看到它强调任务化、工程化、AI 适配、模板化和可扩展能力，而不是只停留在“生成一篇文章”这件事上。',
                    ],
                    [
                        'type' => 'heading',
                        'content' => '接下来我会继续做什么',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            '继续补齐更多 GEO 相关 Skill',
                            '增强 CLI 与本地自动化操作能力',
                            '加入 AI 搜索数据抓取与分析能力',
                            '支持对标页面的智能识别、样式分析与复刻',
                            '继续强化面向 GEO 的智能内容生成与优化能力',
                            '推进多端同步、模板编辑和预览工作流',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => '关于我',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            '著有《AI营销：从SEO到GEO》等 5 部营销类书籍',
                            '曾在中赫集团、好未来集团、猿辅导等上市公司与独角兽企业负责营销、品牌、市场等管理工作',
                            '猎河科技创始人，旗下 AI 课程平台与 AI 工具产品累计服务用户 1000 万+',
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => '如果你愿意继续关注这套系统的迭代，欢迎去看我的 X、项目 GitHub 和更新日志。也欢迎保持联系，一起把 GEO 这件事做得更工程化、更稳定。',
                    ],
                ],
            ],
        ],
        'en' => [
            'meta' => [
                'badge' => 'Before You Start',
                'switch_label' => '中文',
                'close' => 'Close',
                'links_label' => 'Follow the project for ongoing updates, and feel free to stay in touch.',
                'author_link' => 'Author X Profile',
                'github_link' => 'Project GitHub',
                'changelog_link' => 'Changelog',
            ],
            'letter' => [
                'title' => 'Welcome to GEOFlow',
                'subtitle' => 'Hi, welcome to GEOFlow.',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'content' => 'If you are seeing this page, you have already entered the GEOFlow admin. Before you start clicking around, I want to use a short welcome letter to explain what this system is for, what kinds of work it fits, and where I am taking it next.',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'GEOFlow is not just a CMS, and it is not just an AI writing tool. It is closer to a content operating system built around GEO engineering: model setup, asset management, task scheduling, content generation, review, publishing, frontend delivery, and Skill / CLI / API collaboration in one workflow.',
                    ],
                    [
                        'type' => 'heading',
                        'content' => 'What you can do with GEOFlow',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            'Connect and manage multiple AI models',
                            'Manage title, keyword, image, knowledge, and prompt libraries',
                            'Create content tasks with scheduling, queuing, and automated generation',
                            'Run a full draft-review-publish workflow',
                            'Publish frontend pages that fit search visibility and AI citation scenarios',
                            'Extend the system through Skills, CLI, and APIs',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => 'Where this system fits best',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            'GEO optimization for official websites',
                            'GEO news channels and editorial content hubs',
                            'GEO microsites, category sites, and site clusters',
                            'Intelligent and automated GEO content systems',
                            'Content infrastructure for AI search visibility, citation, and source building',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => 'Why I designed it this way',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'GEOFlow starts from content engineering rather than page management. The goal is to make tasks, assets, models, prompts, review, publishing, and frontend delivery work together in one system, and to align that system with the real needs of AI search and generative engines.',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'That is why it focuses on task execution, operational consistency, AI-search compatibility, themeability, and extensibility, instead of stopping at “generate one article.”',
                    ],
                    [
                        'type' => 'heading',
                        'content' => 'What I will keep building next',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            'More GEO-related Skills',
                            'Stronger CLI and local automation workflows',
                            'AI-search data collection and analysis',
                            'Reference-page analysis, style understanding, and cloning support',
                            'Better GEO-focused content generation and optimization',
                            'Multi-end synchronization plus stronger theme preview and editing flows',
                        ],
                    ],
                    [
                        'type' => 'heading',
                        'content' => 'About me',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            'Author of five marketing books including AI Marketing: From SEO to GEO',
                            'Former marketing, brand, and growth leader at listed and unicorn companies including Zhonghe Group, TAL, and Yuanfudao',
                            'Founder of Liehe Technology, with AI course and tool products serving more than 10 million users',
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => 'If you want to follow the project as it evolves, you can use the links below for my X profile, the GitHub repository, and the changelog. You are also welcome to stay in touch.',
                    ],
                ],
            ],
        ],
    ];
}

function get_admin_update_welcome_copy(array $welcomeState): array {
    $updateState = $welcomeState['update'] ?? [];
    $currentVersion = (string) ($updateState['current_version'] ?? APP_VERSION);
    $latestVersion = (string) ($updateState['latest_version'] ?? '');
    $titleZh = trim((string) ($updateState['payload']['title_zh'] ?? ''));
    $titleEn = trim((string) ($updateState['payload']['title_en'] ?? ''));
    $summaryZh = trim((string) ($updateState['payload']['summary_zh'] ?? ''));
    $summaryEn = trim((string) ($updateState['payload']['summary_en'] ?? ''));
    $tipZh = trim((string) ($updateState['payload']['upgrade_tip_zh'] ?? ''));
    $tipEn = trim((string) ($updateState['payload']['upgrade_tip_en'] ?? ''));
    $releaseDate = trim((string) ($updateState['payload']['release_date'] ?? ''));
    $releaseType = trim((string) ($updateState['payload']['release_type'] ?? 'feature'));

    $releaseTypeMapZh = [
        'feature' => '功能更新',
        'fix' => '问题修复',
        'security' => '安全更新',
    ];
    $releaseTypeMapEn = [
        'feature' => 'Feature update',
        'fix' => 'Bug fix',
        'security' => 'Security update',
    ];

    return [
        'zh-CN' => [
            'meta' => [
                'badge' => '版本更新',
                'switch_label' => 'English',
                'close' => '关闭',
                'links_label' => '建议先查看更新日志，再决定是否现在升级。',
                'author_link' => '作者 X 主页',
                'github_link' => '项目 GitHub',
                'changelog_link' => '更新日志',
            ],
            'letter' => [
                'title' => 'GEOFlow 有新版本可更新',
                'subtitle' => '当前版本 v' . $currentVersion . '，最新版本 v' . $latestVersion . '。',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'content' => $titleZh !== '' ? $titleZh : '后台已经检测到 GEOFlow 上游仓库有一个新版本可用。',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => $summaryZh !== '' ? $summaryZh : '这次更新已经整理成版本元数据和更新日志。你可以先看清楚改了什么，再决定什么时候升级，而不用自己去 GitHub 手动对比。',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            '当前版本：v' . $currentVersion,
                            '最新版本：v' . $latestVersion,
                            '发布类型：' . ($releaseTypeMapZh[$releaseType] ?? $releaseTypeMapZh['feature']),
                            '发布日期：' . ($releaseDate !== '' ? $releaseDate : '暂未标注'),
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => $tipZh !== '' ? $tipZh : '如果你准备升级，建议先备份数据库与 uploads 目录，再按更新日志执行部署步骤。',
                    ],
                ],
            ],
        ],
        'en' => [
            'meta' => [
                'badge' => 'Release Update',
                'switch_label' => '中文',
                'close' => 'Close',
                'links_label' => 'Review the changelog first, then decide when to upgrade.',
                'author_link' => 'Author X Profile',
                'github_link' => 'Project GitHub',
                'changelog_link' => 'Changelog',
            ],
            'letter' => [
                'title' => 'A new GEOFlow version is available',
                'subtitle' => 'Current version v' . $currentVersion . ', latest version v' . $latestVersion . '.',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'content' => $titleEn !== '' ? $titleEn : 'The admin has detected that a newer GEOFlow release is available in the upstream repository.',
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => $summaryEn !== '' ? $summaryEn : 'This update is already exposed through release metadata and the changelog, so you can review the changes before deciding when to upgrade.',
                    ],
                    [
                        'type' => 'list',
                        'items' => [
                            'Current version: v' . $currentVersion,
                            'Latest version: v' . $latestVersion,
                            'Release type: ' . ($releaseTypeMapEn[$releaseType] ?? $releaseTypeMapEn['feature']),
                            'Release date: ' . ($releaseDate !== '' ? $releaseDate : 'Not specified'),
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => $tipEn !== '' ? $tipEn : 'Before upgrading, back up the database and uploads directory, then follow the changelog steps.',
                    ],
                ],
            ],
        ],
    ];
}

function render_admin_welcome_modal(?array $admin): void {
    if (!$admin || empty($admin['id'])) {
        return;
    }

    $welcomeState = admin_welcome_state();
    $copy = ($welcomeState['mode'] ?? 'intro') === 'update'
        ? get_admin_update_welcome_copy($welcomeState)
        : get_admin_welcome_copy();
    $payload = [
        'copy' => $copy,
        'state' => [
            'mode' => $welcomeState['mode'] ?? 'intro',
            'shouldAutoOpen' => current_admin_welcome_auto_open(),
            'dismissUrl' => admin_url('welcome-dismiss.php'),
            'csrfToken' => generate_csrf_token(),
            'links' => [
                'x' => 'https://x.com/yaojingang',
                'github' => 'https://github.com/yaojingang/GEOFlow',
                'changelog' => [
                    'zh-CN' => 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md',
                    'en' => 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG_en.md',
                ],
            ],
        ],
    ];
    ?>
    <div id="admin-welcome-modal" class="hidden fixed inset-0 z-[70]">
        <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6 lg:p-8">
            <div class="w-full max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
                <div class="border-b border-slate-200 bg-white px-6 py-4 sm:px-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div id="admin-welcome-badge" class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600"></div>
                        </div>
                        <div class="flex items-center gap-2 self-start sm:self-auto">
                            <button type="button" data-welcome-switch class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700"></button>
                            <button type="button" data-welcome-close class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-100"></button>
                        </div>
                    </div>
                </div>

                <div class="max-h-[80vh] overflow-y-auto bg-white px-6 py-8 sm:px-8 sm:py-10">
                    <article class="mx-auto max-w-3xl">
                        <h2 id="admin-welcome-title" class="text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl"></h2>
                        <p id="admin-welcome-subtitle" class="mt-4 text-lg leading-8 text-slate-600 sm:text-xl"></p>
                        <div id="admin-welcome-content" class="mt-8 space-y-6 text-[17px] leading-8 text-slate-700"></div>
                    </article>

                    <div class="mx-auto mt-10 max-w-3xl border-t border-slate-200 pt-6">
                        <p id="admin-welcome-links-label" class="text-sm text-slate-600"></p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <a id="admin-welcome-link-x" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-github" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
                            <a id="admin-welcome-link-changelog" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-medium text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50" target="_blank" rel="noopener noreferrer"></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script id="admin-welcome-payload" type="application/json"><?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script>
        (function () {
            const modal = document.getElementById('admin-welcome-modal');
            const payloadNode = document.getElementById('admin-welcome-payload');
            if (!modal || !payloadNode) {
                return;
            }

            const payload = JSON.parse(payloadNode.textContent || '{}');
            const copy = payload.copy || {};
            const state = payload.state || {};
            const localeCycle = ['zh-CN', 'en'];
            let locale = 'zh-CN';
            let dismissedPersisted = !state.shouldAutoOpen;

            const badgeNode = document.getElementById('admin-welcome-badge');
            const titleNode = document.getElementById('admin-welcome-title');
            const subtitleNode = document.getElementById('admin-welcome-subtitle');
            const contentNode = document.getElementById('admin-welcome-content');
            const linksLabelNode = document.getElementById('admin-welcome-links-label');
            const linkXNode = document.getElementById('admin-welcome-link-x');
            const linkGithubNode = document.getElementById('admin-welcome-link-github');
            const linkChangelogNode = document.getElementById('admin-welcome-link-changelog');
            const switchButton = modal.querySelector('[data-welcome-switch]');
            const closeButtons = modal.querySelectorAll('[data-welcome-close]');

            function blockHtml(block) {
                if (!block || !block.type) {
                    return '';
                }

                if (block.type === 'heading') {
                    return `<h3 class="pt-2 text-2xl font-semibold tracking-tight text-slate-900">${block.content || ''}</h3>`;
                }

                if (block.type === 'list') {
                    const items = Array.isArray(block.items) ? block.items : [];
                    return `<ul class="space-y-3 pl-1 text-slate-700">${items.map((item) => `<li class="flex gap-3"><span class="mt-[13px] h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>${item}</span></li>`).join('')}</ul>`;
                }

                return `<p>${block.content || ''}</p>`;
            }

            function render(nextLocale) {
                locale = localeCycle.includes(nextLocale) ? nextLocale : 'zh-CN';
                const localeCopy = copy[locale] || copy['zh-CN'] || {};
                const meta = localeCopy.meta || {};
                const letter = localeCopy.letter || {};
                const blocks = letter.blocks || [];

                badgeNode.textContent = meta.badge || '';
                titleNode.textContent = letter.title || '';
                subtitleNode.textContent = letter.subtitle || '';
                contentNode.innerHTML = blocks.map((block) => blockHtml(block)).join('');
                linksLabelNode.textContent = meta.links_label || '';
                linkXNode.textContent = meta.author_link || '';
                linkXNode.href = state.links?.x || '#';
                linkGithubNode.textContent = meta.github_link || '';
                linkGithubNode.href = state.links?.github || '#';
                linkChangelogNode.textContent = meta.changelog_link || '';
                linkChangelogNode.href = state.links?.changelog?.[locale] || state.links?.changelog?.['zh-CN'] || '#';
                switchButton.textContent = meta.switch_label || (locale === 'zh-CN' ? 'English' : '中文');
                closeButtons.forEach((button) => {
                    button.textContent = meta.close || 'Close';
                });
            }

            function openModal() {
                render('zh-CN');
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }

            async function persistDismissIfNeeded() {
                if (dismissedPersisted || !state.dismissUrl || !state.csrfToken) {
                    return;
                }

                try {
                    const response = await fetch(state.dismissUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams({
                            csrf_token: state.csrfToken
                        })
                    });

                    if (response.ok) {
                        dismissedPersisted = true;
                    }
                } catch (error) {
                    console.error('Failed to persist welcome dismissal', error);
                }
            }

            async function closeModal() {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                await persistDismissIfNeeded();
            }

            switchButton.addEventListener('click', function () {
                render(locale === 'zh-CN' ? 'en' : 'zh-CN');
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.querySelectorAll('[data-open-admin-welcome]').forEach((trigger) => {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    openModal();
                });
            });

            if (state.shouldAutoOpen) {
                openModal();
            }
        })();
    </script>
    <?php
}
