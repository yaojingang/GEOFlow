<?php

declare(strict_types=1);

return [
    'zh-CN' => [
        'meta' => [
            'badge' => 'GEOFlow 3.0',
            'switch_label' => 'English',
            'close' => '关闭',
            'links_label' => '继续了解 GEOFlow 3.0，可以查看更新日志、项目仓库和作者主页。',
            'author_link' => '作者 X 主页',
            'github_link' => '项目 GitHub',
            'changelog_link' => '更新日志',
        ],
        'letter' => [
            'title' => '欢迎使用 GEOFlow 3.0',
            'subtitle' => 'GEOFlow 是一套面向企业官网、行业信源平台和内部内容运营的开源 GEO 智能运营系统。它围绕可信知识、内容生产、质量审核、多站分发和数据反馈，建立一条可持续运营的工作链路。',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => '你好，欢迎来到 GEOFlow 3.0。这个主版本重新统一了后台体验，也把知识、AI、审核、发布、分发、人工运营与数据分析接成了一套完整的 GEO 运营工作台。',
                ],
                [
                    'type' => 'paragraph',
                    'content' => '3.0 以可信、清晰、可控为设计主线。统一界面降低操作负担，证据、状态、风险与下一步保持可见，方便个人和团队沿同一条链路持续运营。',
                ],
                [
                    'type' => 'heading',
                    'content' => '3.0 的核心能力',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '统一的 Admin UI V3：用清晰、克制、响应式的界面组织完整后台，并提供图文帮助助手、最近访问、移动端操作和 PWA 独立窗口',
                        '可信的内容生产链路：把企业知识库、RAG、AI 生成、文章质检和人工审核接到同一流程',
                        '多端运营链路：支持本站、托管渠道站点、GEOFlow Agent、WordPress、通用 API 和 Chrome 运营助手',
                        '可追踪的工程保障：通过任务与队列状态、分发日志、AI 可见性、访问分析，以及独立更新、完整备份和回滚能力管理运行风险',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'GEOFlow 可以用在哪里',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '企业官网与 GEO 内容频道：持续沉淀产品资料、品牌事实、FAQ、案例和行业内容，让官网成为可维护的 GEO 内容资产',
                        '行业或专题信源平台：围绕特定行业、品牌或问题域发布可验证内容，形成稳定的内容资产与引用入口',
                        '内部内容管理系统：统一管理素材、作者、分类、草稿、审核、发布和数据反馈，支撑团队协作',
                        '内部知识库系统：沉淀企业资料、业务事实和专家经验，支持整理、切片、检索和 RAG 调用',
                        '内容生成管理系统：统一模型、提示词、任务、队列、质检和发布节奏，管理从生成到复盘的全过程',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '建议先完成 4 步',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '配置模型：先添加可用的 Chat 模型；需要知识库检索时，再添加 Embedding 模型',
                        '准备内容资产：导入真实、可验证的业务资料，再完善提示词、标题、关键词、图片和作者',
                        '跑通小样本：创建少量草稿，开启 AI 质检，核对事实、证据、排版、图片、SEO 与 Schema',
                        '逐步扩大运营：确认审核与页面效果后，再启用自动发布、多站分发或人工发布，并持续查看数据反馈',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '使用边界',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'GEOFlow 用于提高内容工程效率，以及内容被 AI 理解、引用和推荐的概率；平台排名与展示结果仍由各答案引擎决定',
                        '知识质量决定内容上限；关键事实、专业判断和高风险内容仍需人工确认',
                        '批量生成或发布前，请先用小样本验证事实、图片、链接、远端页面和回滚路径',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => '这份 3.0 说明会为每位管理员自动展示一次。之后可从后台底部的“项目说明”随时打开。',
                ],
            ],
        ],
    ],
    'en' => [
        'meta' => [
            'badge' => 'GEOFlow 3.0',
            'switch_label' => '中文',
            'close' => 'Close',
            'links_label' => 'To learn more about GEOFlow 3.0, visit the changelog, project repository, or author profile.',
            'author_link' => 'Author X Profile',
            'github_link' => 'Project GitHub',
            'changelog_link' => 'Changelog',
        ],
        'letter' => [
            'title' => 'Welcome to GEOFlow 3.0',
            'subtitle' => 'GEOFlow is an open-source GEO operations system for corporate websites, vertical source platforms, and internal content operations. It connects trusted knowledge, content production, quality review, multi-site distribution, and analytics into a sustainable workflow.',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'Welcome to GEOFlow 3.0. This major release unifies the admin experience and connects knowledge, AI, review, publishing, distribution, manual operations, and analytics in one GEO operations workspace.',
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'GEOFlow 3.0 is guided by trust, clarity, and control. The unified interface reduces operational overhead while keeping evidence, status, risk, and next steps visible, so individuals and teams can work through the same operating loop.',
                ],
                [
                    'type' => 'heading',
                    'content' => 'Core capabilities in 3.0',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Unified Admin UI V3: a clear, restrained, responsive admin with illustrated help, recent pages, mobile support, and a standalone PWA experience',
                        'Trusted content production: enterprise knowledge, RAG, AI generation, article AI quality inspection, and human review in one workflow',
                        'Multi-site and multi-channel operations: the local site, hosted channel sites, GEOFlow Agent, WordPress, generic APIs, and the Chrome operations assistant',
                        'Operational safeguards: task and queue status, distribution logs, AI visibility and traffic analytics, plus independent updates, full backups, and rollback',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Where GEOFlow fits',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Corporate websites and GEO content sections: maintain product information, brand facts, FAQs, case studies, and industry content as durable GEO assets',
                        'Vertical or topic-focused source platforms: publish verifiable content around an industry, brand, or problem space and maintain reliable citation entry points',
                        'Internal content management systems: manage materials, authors, categories, drafts, review, publishing, and analytics in one place for team collaboration',
                        'Internal knowledge-base systems: organize company materials, business facts, and expert knowledge for structuring, chunking, retrieval, and RAG',
                        'Content generation and operations: coordinate models, prompts, tasks, queues, quality inspection, and publishing cadence from generation through review',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Start with these four steps',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Set up models: add a working chat model first; add an embedding model when you need knowledge retrieval',
                        'Build content assets: import accurate, verifiable business materials, then set up prompts, titles, keywords, images, and authors',
                        'Run a small sample: create a few drafts, enable AI quality inspection, and verify facts, evidence, layout, images, SEO, and Schema',
                        'Scale gradually: after reviewing quality and page output, enable automated publishing, multi-site distribution, or manual publishing and keep checking analytics',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Operating boundaries',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'GEOFlow improves content engineering efficiency and the probability that AI systems understand, cite, and recommend your content; ranking and visibility remain decisions made by each answer engine',
                        'Knowledge quality sets the ceiling for downstream content; critical facts, expert judgment, and high-risk content still require human review',
                        'Before bulk generation or publishing, verify facts, images, links, remote pages, and rollback paths with a small sample',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'This 3.0 introduction automatically opens once for each administrator. You can reopen it anytime from Project intro in the admin footer.',
                ],
            ],
        ],
    ],
];
