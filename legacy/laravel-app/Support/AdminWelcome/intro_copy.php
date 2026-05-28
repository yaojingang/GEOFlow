<?php

declare(strict_types=1);

return [
    'zh-CN' => [
        'meta' => [
            'badge' => '首次部署说明',
            'switch_label' => 'English',
            'close' => '关闭',
            'links_label' => '需要更多细节时，可以查看项目仓库和更新日志。',
            'author_link' => '作者 X 主页',
            'github_link' => '项目 GitHub',
            'changelog_link' => '更新日志',
        ],
        'letter' => [
            'title' => 'GEOFlow 2.0 首次部署说明',
            'subtitle' => '这是一份首次登录后的最小闭环检查清单。先确认安全、模型、素材、任务、数据分析和分发链路，再进入批量生产。',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => '如果你现在看到这份说明，表示后台已经可以访问。首次部署后，最重要的不是马上批量生成内容，而是先确认账号安全、模型配置、素材准备、队列运行、数据看板和分发目标都处在可控状态。',
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'GEOFlow 2.0 的后台首页已经调整为导航入口：单站点运营用于完成模型、素材、任务、文章、本地站点和用户管理；多站点分发用于管理渠道、目标站点包和远端同步；数据分析用于统一查看内容生产、任务状态、分发结果、访问日志和 AI 爬虫趋势。',
                ],
                [
                    'type' => 'heading',
                    'content' => '部署完成后先检查',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '立即修改默认管理员密码，并确认后台入口路径、语言和站点 URL 是否正确',
                        '检查 PostgreSQL、Redis、队列、调度器和存储目录权限是否正常',
                        '在 AI 配置器中至少添加一个可用 chat 模型；如需知识库召回，再添加 embedding 模型',
                        '在网站设置中确认站点名称、SEO 信息、前台模板和公开访问域名',
                        '打开数据分析页，确认内容、任务、分发和日志模块可以正常加载',
                        '准备标题库、关键词库、知识库、图片库和作者，先用少量真实资料做验证',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '推荐的第一轮上手流程',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '先配置模型和提示词，再配置素材库，不要直接大批量创建任务',
                        '新建一个小任务，生成 1 到 3 篇文章，先进入草稿或审核状态',
                        '检查文章标题、正文、图片、Markdown 排版、SEO 字段和结构化数据',
                        '确认本地前台页面正常后，再开启自动发布或批量任务',
                        '如需分发到外部站点，再进入分发管理创建目标渠道并测试连接',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '后台首页与数据分析',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '首页主要承担导航作用，保留三步配置引导，并按单站点运营、多站点分发和配套 Skill 资源组织入口',
                        '数据分析页集中展示系统总览、单站运营、多站分发和自助日志四类数据',
                        '先用默认时间范围检查趋势图、任务状态、分发队列、访问日志和 AI 爬虫识别是否有数据',
                        '需要排查内容或渠道问题时，优先从数据分析页定位日期、任务、文章和渠道，再回到对应管理页处理',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'GEOFlow 2.0 的分发管理',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '分发渠道用于管理独立目标站点，每个渠道都有自己的 Agent 地址、密钥和站点设置',
                        '推荐优先使用静态模式，发布文章时同步生成首页、文章页、站点地图和 TXT 地图文件',
                        '渠道详情页可以下载目标站点包，上传解压后再通过“测试连接”确认接口可用',
                        '修改目标站标题、版权、模板或分类等设置后，可使用“更新目标站点”重新同步页面',
                        '分发队列和最近日志会记录文章标题、时间、动作、状态、远程链接和错误信息，便于排查',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '上线前的安全与备份建议',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '不要把 .env、API Key、数据库数据、日志、缓存或上传文件提交到公开仓库',
                        '生产环境使用强密码、HTTPS、可信反向代理配置和最小化暴露端口',
                        '升级或迁移前先备份数据库、.env、uploads、storage 和目标渠道站点包',
                        '修改配置、语言包或视图后，清理 Laravel 配置缓存和视图缓存',
                        '批量发布前先做小样本测试，确认内容质量、图片同步和远端页面都符合预期',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '你可以用 GEOFlow 2.0 做什么',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '搭建面向 AI 搜索和答案引用的内容站点',
                        '把知识库、素材库、提示词和任务调度集中到一个后台管理',
                        '通过审核发布流程控制内容质量，而不是只追求自动生成数量',
                        '把同一批内容按渠道同步到多个目标站点，逐步形成可维护的 GEO 内容网络',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => '后续如果需要了解版本变化、部署细节或参与改进，可以查看项目 GitHub、更新日志和相关说明文档。',
                ],
            ],
        ],
    ],
    'en' => [
        'meta' => [
            'badge' => 'First Deployment',
            'switch_label' => '中文',
            'close' => 'Close',
            'links_label' => 'Use these links when you need repository details or release notes.',
            'author_link' => 'Author X Profile',
            'github_link' => 'Project GitHub',
            'changelog_link' => 'Changelog',
        ],
        'letter' => [
            'title' => 'GEOFlow 2.0 First Deployment Guide',
            'subtitle' => 'Use this as the first-login minimum checklist. Confirm security, models, materials, tasks, analytics, and distribution before bulk production.',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'If you can see this guide, the admin is reachable. After the first deployment, the priority is not bulk generation; it is confirming account security, model connectivity, material readiness, queue workers, analytics dashboards, and distribution targets.',
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'The GEOFlow 2.0 dashboard now works as a navigation hub: single-site operations cover models, materials, tasks, articles, local site settings, and users; multi-site distribution covers channels, target-site packages, and remote sync; Analytics centralizes production, task, distribution, access-log, and AI-crawler trends.',
                ],
                [
                    'type' => 'heading',
                    'content' => 'Check these first',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Change the default admin password, then verify the admin path, language, and site URL',
                        'Confirm PostgreSQL, Redis, queue workers, scheduler, and writable storage paths',
                        'Add at least one working chat model; add an embedding model if you need knowledge-base recall',
                        'Review site name, SEO settings, frontend theme, and public domain in Site Settings',
                        'Open Analytics and confirm the content, task, distribution, and log sections load correctly',
                        'Prepare title, keyword, knowledge, image, and author libraries with a small set of real materials first',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Recommended first run',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Configure models and prompts before creating large tasks',
                        'Create a small task that generates one to three articles and keeps them in draft or review first',
                        'Check titles, body content, images, Markdown rendering, SEO fields, and structured data',
                        'Verify the local frontend output before enabling automated publishing or larger batches',
                        'If you need external delivery, create a distribution channel and test its connection',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Dashboard and Analytics',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'The dashboard is mainly a navigation hub, keeping the three-step setup guide and grouping entry points by single-site operations, multi-site distribution, and supporting Skills',
                        'Analytics separates system overview, single-site operations, multi-site distribution, and self-service log data',
                        'Start with the default date range and check charts, task status, distribution queue, access logs, and AI crawler recognition',
                        'When debugging content or channel issues, locate the date, task, article, and channel in Analytics first, then return to the matching admin page',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Distribution management in 2.0',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'A distribution channel represents an independent target site with its own Agent URL, secret, and site settings',
                        'Static mode is recommended first: publishing can regenerate the homepage, article pages, sitemap, and TXT map files',
                        'Download the target-site package from the channel detail page, upload and extract it, then use Test Connection',
                        'After changing target title, copyright, theme, or categories, use Update Target Site to resync pages',
                        'The queue and recent logs show article title, time, action, status, remote URL, and errors for easier troubleshooting',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Security and backup before production',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Never commit .env files, API keys, database data, logs, caches, or uploaded files to a public repository',
                        'Use strong passwords, HTTPS, trusted proxy settings, and minimal exposed ports in production',
                        'Back up the database, .env, uploads, storage, and target-site packages before upgrades or migrations',
                        'Clear Laravel config and view caches after changing configuration, language files, or views',
                        'Run small-sample publishing tests before bulk delivery, especially for image sync and remote pages',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'What GEOFlow 2.0 is for',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Build content sites that are easier for AI search and answer engines to understand and cite',
                        'Manage knowledge, materials, prompts, and task scheduling in one admin',
                        'Control content quality through review and publishing workflows instead of only optimizing for volume',
                        'Sync content to multiple target sites and gradually build a maintainable GEO content network',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'For release changes, deployment notes, or contributing, use the GitHub repository, changelog, and project documentation.',
                ],
            ],
        ],
    ],
];
