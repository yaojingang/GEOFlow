<?php
/**
 * SEOGEO 智能内容中台 · 首页自定义版块初始化
 * 落库到 site_settings: homepage_modules / homepage_style
 * 走 HomepageModuleBuilder 归一化，保证字段与 URL 合规
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Site\HomepageModuleBuilder;
use App\Services\Site\SiteAppearanceService;

$raw = [
    [
        'type' => 'hero',
        'layout' => 'single',
        'enabled' => true,
        'sort_order' => 10,
        'title' => 'SEOGEO 智能内容中台',
        'subtitle' => '内容生产 · 多平台分发 · 智能体编排 · 排名与品牌监控一体化',
        'body' => '面向企业官网与行业信源，把知识库、AI 生成、人工审核、多端分发与数据反馈接成一条完整链路，让内容成为可持续运营的 GEO 资产。',
        'link_text' => '进入内容生产后台',
        'link_url' => '/geoflow/geo_admin/login',
        'alignment' => 'center',
    ],
    [
        'type' => 'feature_grid',
        'layout' => 'grid',
        'enabled' => true,
        'sort_order' => 20,
        'title' => '智能中台 · 已上线能力',
        'subtitle' => 'SEOGEO 自有中台与智能体入口，一个后台统管全链路',
        'body' => implode("\n", [
            '内容生产后台 | AI 写稿 · 选题 · 审核 · 分发全流程管理 | /geoflow/geo_admin/login',
            'MCP 智能体 v3 | 26 个内容 / 审核 / 分发 / 监控 / 智能体工具在线 | /geoflow/mcp/',
            'n8n 工作流编排 | 138 个工作流 · 38 个 webhook 在线编排 | https://n8n.aixgn.cn/',
            '禅道数字员工 | 需求 / 任务 / Bug / 日报自动归集与留痕 | https://3.aixgn.cn/',
            '数字员工矩阵 | 6 节点在线 · 巡检 / 告警 / 日报（内部能力） | ',
            '总览看板 | 全链路数据一屏总览 | /dashboard/',
        ]),
    ],
    [
        'type' => 'feature_grid',
        'layout' => 'grid',
        'enabled' => true,
        'sort_order' => 30,
        'title' => '开源内容引擎 · 候选接入',
        'subtitle' => '成熟开源项目纳入 MCP 与编排链路，按平台与环节对接',
        'body' => implode("\n", [
            'MoneyPrinterTurbo | 约 97k 星 · 关键词到脚本 / 素材 / 配音 / 字幕 / 成片全自动短视频 | https://github.com/harry0703/MoneyPrinterTurbo',
            'Easel（浙大 · 北大开源） | 社媒运营 Agent · 热点 / 策划 / 创作 / 发布 / 归因闭环 · 112+ Skill | https://github.com/ZJU-REAL/Easel',
            'social-auto-upload | 9.5k+ 星 · 抖音 / B站 / 小红书 / 快手 / 视频号 / 百家号自动发布 | https://github.com/dreammis/social-auto-upload',
            'MatrixMedia 矩媒 | 7 平台视频矩阵发布 · CLI / MCP / HTTP API 可直驱 | https://github.com/hanliang97/MatrixMedia',
            'PostBot（候选） | 微信 / 知乎 / 百家号 / 小红书 / 头条 / B站 · 平台覆盖最全 | ',
            'MediaCrawler MCP | 小红书 / 抖音 / 快手 / B站 / 微博 / 贴吧舆情数据爬取 | ',
        ]),
    ],
    [
        'type' => 'feature_grid',
        'layout' => 'grid',
        'enabled' => true,
        'sort_order' => 40,
        'title' => '监控与反馈 · 建设中',
        'subtitle' => '排名追踪与品牌舆情采集任务正在补齐，接入后在此呈现实时报告',
        'body' => implode("\n", [
            '排名监控 SerpBear | 百度 / 谷歌关键词每日排名追踪（定时任务待补齐） | /serpbear/',
            '品牌舆情 Gego | 全网品牌提及 / 竞品监控（采集任务待补齐） | /gego/',
        ]),
    ],
];

$modules = HomepageModuleBuilder::normalizeModules($raw, false, HomepageModuleBuilder::MAX_MODULES);
$style = HomepageModuleBuilder::defaultStyle();

app(SiteAppearanceService::class)->saveValidated([
    'homepage_style' => (string) json_encode($style, JSON_UNESCAPED_UNICODE),
    'homepage_modules' => (string) json_encode($modules, JSON_UNESCAPED_UNICODE),
]);

\App\Support\Site\SiteSettingsBag::forget();

echo 'modules_saved=' . count($modules) . PHP_EOL;
foreach ($modules as $m) {
    echo ' - ' . $m['type'] . ' | ' . $m['title'] . PHP_EOL;
}
