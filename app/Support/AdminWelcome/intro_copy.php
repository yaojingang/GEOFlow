<?php

declare(strict_types=1);

return [
    'zh-CN' => [
        'meta' => [
            'badge' => '项目说明',
            'switch_label' => 'English',
            'close' => '关闭',
            'links_label' => '需要更多细节时，可以查看项目仓库、更新日志和作者主页。',
            'author_link' => '作者 X 主页',
            'github_link' => '项目 GitHub',
            'changelog_link' => '更新日志',
        ],
        'letter' => [
            'title' => '写给 GEOFlow 使用者的一封信',
            'subtitle' => 'GEOFlow 是一套面向 AI 搜索与多站点分发的内容工程后台。它不承诺排名，而是用真实资料、结构化内容和持续验证，提高被理解、引用和推荐的概率。',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => '你好，欢迎使用 GEOFlow。这个系统的出发点不是把内容生产变成一次性的批量生成，而是把资料、事实、提示词、审核、发布、分发和数据复盘连接成一个可持续的 GEO 工作流。',
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'GEO 的核心不是“骗过算法”，而是让真实、可靠、结构化的内容更容易被答案引擎识别。系统可以帮你提效，但内容质量、业务事实和人工判断仍然是基础。',
                ],
                [
                    'type' => 'heading',
                    'content' => 'GEOFlow 是什么',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '把 AI 模型、提示词、知识库、素材库、任务和文章管理集中到一个后台',
                        '把本站、目标 Agent 站点、WordPress 和通用 API 分发渠道接入同一条发布链路',
                        '用观测归因查看内容生产、任务状态、分发结果、访问日志和 AI 爬虫趋势',
                        '用审核、引用和复盘机制，减少只追求生成数量带来的内容风险',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'GEO 的几个基本常识',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'GEO 优化的是答案引擎采纳事实、观点和页面的概率，不是保证某个平台固定排名',
                        'AI 更容易引用清晰、准确、有来源、可验证、持续更新且结构化良好的内容',
                        '不要用虚假事实或堆砌关键词赌概率，长期有效的 GEO 依赖事实、证据、语义结构和品牌一致性',
                        '高风险内容需要人工审核，重要生成结果最好能引用具体知识库证据',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '推荐的使用方法',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '先配置 Chat 模型、Embedding 模型和提示词，再沉淀知识库、标题库、关键词库、图片和作者',
                        '先用小任务生成少量文章，检查标题、事实、Markdown 排版、图片、SEO 字段和 Schema',
                        '通过审核后再发布到本站，或同步到目标 Agent、WordPress、通用 API 等渠道',
                        '定期查看观测归因，用任务状态、分发日志、访问日志和 AI 爬虫数据反向修正知识资产和内容',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '这套系统的边界',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '它能自动化内容工程，但不能替代业务事实、专家判断和最终审核',
                        '它能提升管理效率和被引用概率，但不能保证任何 AI 或搜索平台一定展示',
                        '知识库越系统、越准确、越有证据，后续内容的质量上限越高',
                        '批量发布前请先小样本测试，确认事实、图片、排版、链接和远端页面都符合预期',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => '这封信只会在当前说明版本下自动弹出一次。以后如果需要回看，可以从后台底部的“项目说明”再次打开。',
                ],
            ],
        ],
    ],
    'en' => [
        'meta' => [
            'badge' => 'Project Intro',
            'switch_label' => '中文',
            'close' => 'Close',
            'links_label' => 'Use these links when you need repository details, release notes, or the author profile.',
            'author_link' => 'Author X Profile',
            'github_link' => 'Project GitHub',
            'changelog_link' => 'Changelog',
        ],
        'letter' => [
            'title' => 'A Letter to GEOFlow Users',
            'subtitle' => 'GEOFlow is a content engineering admin for AI search and multi-site distribution. It does not promise rankings; it improves the probability of being understood, cited, and recommended through facts, structure, and continuous validation.',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'content' => 'Welcome to GEOFlow. The goal is not one-off bulk generation. The goal is to connect materials, facts, prompts, review, publishing, distribution, and analytics into a repeatable GEO workflow.',
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'GEO is not about tricking algorithms. It is about making truthful, reliable, well-structured content easier for answer engines to recognize. The system can improve efficiency, but content quality, business facts, and human judgment remain the foundation.',
                ],
                [
                    'type' => 'heading',
                    'content' => 'What GEOFlow is',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'A single admin for AI models, prompts, knowledge bases, materials, tasks, and articles',
                        'A publishing chain that connects the local site, target Agent sites, WordPress, and generic API channels',
                        'An analytics surface for production, task status, distribution results, access logs, and AI crawler trends',
                        'A quality workflow that uses review, evidence, and feedback instead of only optimizing for volume',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'GEO basics',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'GEO optimizes the probability that answer engines adopt your facts, viewpoints, and pages; it cannot guarantee fixed rankings',
                        'AI systems are more likely to cite clear, accurate, sourced, verifiable, fresh, and well-structured content',
                        'Do not gamble with fabricated facts or keyword stuffing. Durable GEO depends on evidence, semantic structure, and brand consistency',
                        'High-risk content needs human review, and important generated content should cite specific knowledge-base evidence',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Recommended workflow',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Configure chat models, embedding models, and prompts before building knowledge, title, keyword, image, and author libraries',
                        'Generate a few articles first, then review titles, facts, Markdown layout, images, SEO fields, and Schema',
                        'After review, publish to the local site or sync to target Agent, WordPress, and generic API channels',
                        'Use Analytics regularly to refine materials and content through task status, distribution logs, access logs, and AI crawler data',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'System boundaries',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'It can automate content engineering, but it cannot replace business facts, expert judgment, or final review',
                        'It can improve operational efficiency and citation probability, but it cannot guarantee visibility on any AI or search platform',
                        'The more systematic, accurate, and evidence-backed your knowledge base is, the higher the ceiling for downstream content',
                        'Before bulk publishing, test a small sample and verify facts, images, layout, links, and remote pages',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'This letter auto-opens only once for the current intro version. You can always reopen it later from Project intro in the admin footer.',
                ],
            ],
        ],
    ],
];
