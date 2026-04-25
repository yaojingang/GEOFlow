<?php

declare(strict_types=1);

/**
 * 供「版本更新」模式弹窗使用。
 */
return static function (array $welcomeState): array {
    $updateState = $welcomeState['update'] ?? [];
    $defaultVersion = (string) config('geoflow.welcome_intro_version', '1.2.0');
    $currentVersion = (string) ($updateState['current_version'] ?? $defaultVersion);
    $latestVersion = (string) ($updateState['latest_version'] ?? '');
    $payload = is_array($updateState['payload'] ?? null) ? $updateState['payload'] : [];
    $titleZh = trim((string) ($payload['title_zh'] ?? ''));
    $titleEn = trim((string) ($payload['title_en'] ?? ''));
    $summaryZh = trim((string) ($payload['summary_zh'] ?? ''));
    $summaryEn = trim((string) ($payload['summary_en'] ?? ''));
    $tipZh = trim((string) ($payload['upgrade_tip_zh'] ?? ''));
    $tipEn = trim((string) ($payload['upgrade_tip_en'] ?? ''));
    $releaseDate = trim((string) ($payload['release_date'] ?? ''));
    $releaseType = trim((string) ($payload['release_type'] ?? 'feature'));

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
                'subtitle' => '当前版本 v'.$currentVersion.'，最新版本 v'.$latestVersion.'。',
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
                            '当前版本：v'.$currentVersion,
                            '最新版本：v'.$latestVersion,
                            '发布类型：'.($releaseTypeMapZh[$releaseType] ?? $releaseTypeMapZh['feature']),
                            '发布日期：'.($releaseDate !== '' ? $releaseDate : '暂未标注'),
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
                'subtitle' => 'Current version v'.$currentVersion.', latest version v'.$latestVersion.'.',
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
                            'Current version: v'.$currentVersion,
                            'Latest version: v'.$latestVersion,
                            'Release type: '.($releaseTypeMapEn[$releaseType] ?? $releaseTypeMapEn['feature']),
                            'Release date: '.($releaseDate !== '' ? $releaseDate : 'Not specified'),
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
};
