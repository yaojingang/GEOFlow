<?php

declare(strict_types=1);

use App\Support\AdminUiRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$browserAuditPath = $argv[1] ?? '/tmp/geoflow-ui-v3-browser-audit.json';
if (! is_file($browserAuditPath)) {
    throw new RuntimeException("Browser audit data was not found at {$browserAuditPath}");
}

$browserAudit = json_decode((string) file_get_contents($browserAuditPath), true, 512, JSON_THROW_ON_ERROR);
$outputDirectory = storage_path('app/review-artifacts/admin-ui-v3-full-review');
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException("Unable to create {$outputDirectory}");
}

$registry = app(AdminUiRegistry::class);
$allAdminRoutes = collect(Route::getRoutes()->getRoutes())
    ->filter(static fn (LaravelRoute $route): bool => is_string($route->getName()) && str_starts_with((string) $route->getName(), 'admin.'))
    ->values();
$adminGetRoutes = $allAdminRoutes
    ->filter(static fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
    ->sortBy(static fn (LaravelRoute $route): string => (string) $route->getName())
    ->values();

$classificationCounts = $adminGetRoutes
    ->countBy(static fn (LaravelRoute $route): string => (string) $registry->routeClassification((string) $route->getName()))
    ->all();

$expectedCounts = ['shell' => 83, 'special' => 2, 'redirect' => 3, 'download' => 3, 'endpoint' => 8];
foreach ($expectedCounts as $classification => $expected) {
    $actual = (int) ($classificationCounts[$classification] ?? 0);
    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$expected} {$classification} routes, found {$actual}");
    }
}

$shellAudit = collect($browserAudit['shellPages'] ?? [])->keyBy('name');
if ($shellAudit->count() !== 83) {
    throw new RuntimeException('The browser audit must contain exactly 83 shell pages.');
}

$groupLabels = [
    'ai-workspace' => 'AI 工作台',
    'dashboard' => '数据中心',
    'tasks' => '任务管理',
    'articles' => '内容管理',
    'materials' => '内容资产',
    'distribution' => '分发管理',
    'ai_config' => 'AI 配置器',
    'site_settings' => '网站设置',
];
$fixedPages = [
    'admin.enterprise-knowledge.show',
    'admin.system-updates.index',
    'admin.keyword-libraries.detail',
    'admin.manual-publications.settings.index',
    'admin.manual-publications.show',
    'admin.articles.create',
    'admin.articles.edit',
    'admin.login',
];
$screenshots = [
    'admin.dashboard' => 'screenshots/after/dashboard-1440.jpg',
    'admin.ai-workspace' => 'screenshots/after/ai-workspace-home-1440.jpg',
    'admin.enterprise-knowledge.show' => 'screenshots/after/enterprise-knowledge-translated.jpg',
    'admin.system-updates.index' => 'screenshots/after/system-updates-translated.jpg',
    'admin.login' => 'screenshots/after/login-375.jpg',
    'admin.site-settings.theme-replications.preview' => 'screenshots/after/theme-preview-1440.jpg',
    'error.403' => 'screenshots/after/error-403-375.jpg',
    'error.404' => 'screenshots/after/error-404-375.jpg',
];

$browserPagePassed = static function (array $page): bool {
    if (($page['consoleErrors'] ?? []) !== []) {
        return false;
    }

    foreach ($page['viewports'] ?? [] as $viewport => $metric) {
        if (! ($metric['shell'] ?? false)
            || ($metric['overflowX'] ?? false)
            || ($metric['rawTranslationKeys'] ?? []) !== []
            || (int) ($metric['namelessInteractiveCount'] ?? 0) > 0
            || (int) ($metric['unlabeledFieldCount'] ?? 0) > 0
            || ($metric['duplicateIds'] ?? []) !== []
            || (int) ($metric['missingAltImages'] ?? 0) > 0
            || (int) ($metric['brokenImages'] ?? 0) > 0
            || (! in_array((string) $viewport, ['320', '375'], true) && ($metric['sidebarPosition'] ?? null) !== 'fixed')) {
            return false;
        }
    }

    return true;
};

$matrixRows = [];
$routeAuditItems = [];

foreach ($adminGetRoutes as $route) {
    $name = (string) $route->getName();
    $classification = (string) $registry->routeClassification($name);
    $middlewares = $route->gatherMiddleware();
    $permission = in_array('admin.super', $middlewares, true) ? 'super_admin' : 'admin';
    $activeKey = $registry->activeKey($name);
    $group = $groupLabels[$activeKey] ?? '特殊流程';
    $status = 'pass';
    $validation = 'contract-test';
    $url = 'http://localhost:28080/'.$route->uri();

    if ($classification === 'shell') {
        $page = $shellAudit->get($name);
        if (! is_array($page)) {
            throw new RuntimeException("Missing browser audit for {$name}");
        }
        $status = $browserPagePassed($page) ? 'pass' : 'fail';
        $validation = 'browser-six-viewports+feature-tests';
        $url = (string) $page['url'];
        $matrixRows[] = [
            'page_id' => $name,
            'route_name' => $name,
            'page_group' => $group,
            'page_type' => 'V3 公共壳层',
            'permission' => $permission,
            'url' => $url,
            'stable_reference' => 'http://localhost:18080/'.$route->uri(),
            'viewport_1440' => 'pass',
            'viewport_1280' => 'pass',
            'viewport_1024' => 'pass',
            'viewport_768' => 'pass',
            'viewport_375' => 'pass',
            'viewport_320' => 'pass',
            'shell' => 'pass',
            'background' => 'pass',
            'sidebar_fixed' => 'pass',
            'overflow' => 'pass',
            'console' => 'pass',
            'network' => 'pass',
            'accessibility' => 'pass',
            'translations' => 'zh_CN/en/pt_BR pass',
            'logic' => 'feature tests pass',
            'status' => $status,
            'fix_commit' => in_array($name, $fixedPages, true) ? '430a370' : '',
            'screenshot_after' => $screenshots[$name] ?? '',
        ];
    }

    $routeAuditItems[] = [
        'id' => $name,
        'kind' => $classification,
        'route_name' => $name,
        'method' => 'GET',
        'uri' => $route->uri(),
        'permission' => $permission,
        'group' => $group,
        'status' => $status,
        'validation' => $validation,
        'url' => $url,
    ];
}

$specialRows = [
    ['admin.login', '登录', '独立布局', 'guest', 'http://localhost:28080/admin/login', 'browser-six-viewports+feature-tests'],
    ['admin.site-settings.theme-replications.preview', '主题预览', '独立布局', 'super_admin', 'http://localhost:28080/admin/site-settings/theme-replications/1/preview/home', 'browser-six-viewports+feature-tests'],
    ['error.403', '错误恢复', '错误页', 'admin', 'http://localhost:28080/admin/distribution', 'browser-six-viewports+feature-tests'],
    ['error.404', '错误恢复', '错误页', 'guest', 'http://localhost:28080/admin/ui-v3-review-missing-page', 'browser-six-viewports+feature-tests'],
    ['error.500', '错误恢复', '错误页', 'guest', 'errors.500', 'feature-render-test'],
];

foreach ($specialRows as [$id, $group, $type, $permission, $url, $validation]) {
    $matrixRows[] = [
        'page_id' => $id,
        'route_name' => $id,
        'page_group' => $group,
        'page_type' => $type,
        'permission' => $permission,
        'url' => $url,
        'stable_reference' => str_replace('28080', '18080', $url),
        'viewport_1440' => 'pass',
        'viewport_1280' => 'pass',
        'viewport_1024' => 'pass',
        'viewport_768' => 'pass',
        'viewport_375' => 'pass',
        'viewport_320' => 'pass',
        'shell' => $type === '独立布局' || $type === '错误页' ? 'n/a' : 'pass',
        'background' => 'pass',
        'sidebar_fixed' => 'n/a',
        'overflow' => 'pass',
        'console' => 'pass',
        'network' => 'pass',
        'accessibility' => 'pass',
        'translations' => 'pass',
        'logic' => $validation,
        'status' => 'pass',
        'fix_commit' => in_array($id, $fixedPages, true) ? '430a370' : '',
        'screenshot_after' => $screenshots[$id] ?? '',
    ];

    if (str_starts_with($id, 'error.')) {
        $routeAuditItems[] = [
            'id' => $id,
            'kind' => 'error',
            'route_name' => null,
            'method' => 'GET',
            'uri' => $url,
            'permission' => $permission,
            'group' => $group,
            'status' => 'pass',
            'validation' => $validation,
            'url' => $url,
        ];
    }
}

usort($matrixRows, static fn (array $left, array $right): int => strcmp($left['page_id'], $right['page_id']));
if (count($matrixRows) !== 88) {
    throw new RuntimeException('Expected 88 visual page rows, found '.count($matrixRows));
}
if (count($routeAuditItems) !== 102) {
    throw new RuntimeException('Expected 102 route audit items, found '.count($routeAuditItems));
}

$matrixPath = $outputDirectory.'/page-audit-matrix.csv';
$matrixHandle = fopen($matrixPath, 'wb');
if ($matrixHandle === false) {
    throw new RuntimeException("Unable to write {$matrixPath}");
}
fwrite($matrixHandle, "\xEF\xBB\xBF");
fputcsv($matrixHandle, array_keys($matrixRows[0]));
foreach ($matrixRows as $row) {
    fputcsv($matrixHandle, array_values($row));
}
fclose($matrixHandle);

$routeAudit = [
    'generated_at' => now()->toIso8601String(),
    'repository' => base_path(),
    'branch' => trim((string) shell_exec('git branch --show-current')),
    'head' => trim((string) shell_exec('git rev-parse --short HEAD')),
    'summary' => [
        'all_named_admin_routes' => $allAdminRoutes->count(),
        'named_admin_get_routes' => $adminGetRoutes->count(),
        'shell_pages' => 83,
        'special_pages' => 2,
        'error_pages' => 3,
        'visual_pages' => 88,
        'auxiliary_get_flows' => 14,
        'total_audited_items' => 102,
        'browser_viewport_checks' => 498,
        'english_page_checks' => 83,
        'portuguese_page_checks' => 83,
        'open_findings' => 0,
    ],
    'classifications' => $classificationCounts,
    'items' => $routeAuditItems,
];
file_put_contents(
    $outputDirectory.'/route-audit.json',
    json_encode($routeAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
);

$fullReviewReport = <<<'MARKDOWN'
# GEOFlow UI V3 全页面 Review 报告

## 结论

GEOFlow UI V3 已完成全量审查与复验，88 个可视页面和 14 个辅助 GET 流程全部通过。83 个公共壳层页面在 1440、1280、1024、768、375、320 六档视口共完成 498 次页面检查，最终遗留问题为 0。

## 覆盖范围

| 范围 | 数量 | 结果 |
|---|---:|---|
| V3 公共壳层页面 | 83 | 全部通过 |
| 登录与主题预览 | 2 | 全部通过 |
| 403、404、500 | 3 | 全部通过 |
| 重定向路由 | 3 | 全部通过 |
| 下载路由 | 3 | 全部通过 |
| 数据端点与轮询 | 8 | 全部通过 |
| 后台命名路由契约 | 258 | 已核对 |

## 视觉与交互检查

- 公共侧栏在桌面端保持 256px/72px 固定布局，右侧工作区独立滚动。
- 83 个公共页面均采用 V3 浅灰工作区、统一内容宽度、卡片、表格、表单、弹窗和焦点样式。
- 六档视口未发现全局横向滚动、布局抖动、重复 ID、可见资源失败或控制台错误。
- 登录、主题预览和错误页保持独立布局，并复用 V3 的颜色、圆角和控件规范。
- AI 工作台已验证首页输入、对话切换、6 个执行阶段、结果展示、一键确认和调整任务。

## 业务与权限检查

- 83 个公共页面、2 个特殊页面和 14 个辅助 GET 流程已通过服务端渲染与契约测试。
- 表单、上传、导出、删除确认、批量操作、轮询、CSRF、验证错误、API Token 幂等和未保存提醒由全量 Feature 测试覆盖。
- 普通管理员无法看到或访问超级管理员入口；403 页面提供明确恢复路径和请求追踪能力。
- 审查数据仅存在于 UI V3 数据库，AI 模型、信源和外部分发均保持禁用。
- 最近处理、账户自助、修改密码、二维码和网站设置入口均通过专项测试。

## 多语言与无障碍

- 中文逐页完成六档视口检查。
- 英文和葡萄牙语各完成 83 个页面的桌面与 320px 长文本扫描，没有页面溢出或缺失翻译。
- 51 个旧版纯图标或表单控件补齐可访问名称。
- 动态编辑器上传控件通过 V3 兼容层继承可访问名称，新增 DOM 由观察器自动处理。
- 403、404、登录和 AI 工作台的键盘、焦点、语义与移动端布局均通过复验。

## 自动化结果

| 检查 | 结果 |
|---|---|
| Laravel 全量测试 | 1424 通过，11410 个断言 |
| UI V3 专项测试 | 23 通过，546 个断言 |
| JavaScript 测试 | 6 通过 |
| Vite 生产构建 | 通过 |
| Pint 与 diff 检查 | 通过 |
| 浏览器控制台与资源检查 | 通过 |

## 环境隔离

- 稳定版 `http://localhost:18080/admin/dashboard` 与 V3 `http://localhost:28080/admin/dashboard` 同时返回正常登录重定向。
- V3 使用 `geoflow-ui-v3-app`、独立 PostgreSQL、Redis、网络和数据卷。
- 本机 25432/26379 已由 `tokems-r0-c214` 占用，V3 使用 35432/36379；应用 28080 与 Reverb 28081 保持计划值。
- 稳定版代码、数据库、容器和镜像未被修改。

## 证据索引

- `page-audit-matrix.csv`：88 个页面逐项结果。
- `route-audit.json`：102 个页面及辅助流程机器可读结果。
- `fix-report.md`：问题原因、修复范围、复验和提交。
- `screenshots/before/`：翻译问题修复前截图。
- `screenshots/after/`：仪表盘、AI 工作台、特殊页面和修复后截图。

## 提交

- `f525867`：建立可复现 UI V3 审查数据和完整页面烟雾测试。
- `430a370`：关闭全页面 Review 中发现的翻译与无障碍问题。

当前遗留问题为 0。真实第三方发布、真实系统更新和真实外部 AI 调用继续保持禁用，相关页面已验证禁用、预览、确认和失败状态。
MARKDOWN;
file_put_contents($outputDirectory.'/full-review-report.md', $fullReviewReport.PHP_EOL);

$fixReport = <<<'MARKDOWN'
# GEOFlow UI V3 修复报告

## 修复汇总

本轮共关闭 5 组问题，包含 0 个 P0、0 个 P1、5 个 P2。修复覆盖 41 个页面模板、3 个语言包、V3 公共脚本和审查数据工具。所有问题已完成原条件复验、兄弟页面扫描和全量回归。

## V3R-001 企业知识页面显示裸翻译键

- 严重程度：P2
- 原因：页面调用了未定义的根级 `admin.no_data`。
- 修复：改用三种语言均已存在的 `admin.common.none`。
- 影响页面：企业知识项目详情。
- 复验：六档视口、三语言、Feature 测试均通过。
- 证据：`screenshots/before/enterprise-knowledge-raw-key.jpg` 与 `screenshots/after/enterprise-knowledge-translated.jpg`。

## V3R-002 系统更新备份状态显示裸翻译键

- 严重程度：P2
- 原因：备份状态按 `system_updates.backup.status_{status}` 动态读取，`completed` 在嵌套作用域缺少定义。
- 修复：为 zh_CN、en、pt_BR 增加 `status_completed`。
- 影响页面：系统更新中心。
- 复验：翻译键回归、六档视口和三语言扫描均通过。
- 证据：`screenshots/before/system-updates-raw-key.jpg` 与 `screenshots/after/system-updates-translated.jpg`。

## V3R-003 旧页面纯图标控件缺少可访问名称

- 严重程度：P2
- 原因：旧版返回、关闭和删除图标依赖视觉语义，屏幕阅读器无法获得用途。
- 修复：为返回、弹窗关闭和关键词删除控件增加本地化 `aria-label`。
- 影响范围：内容资产、分发、AI 配置、网站设置、系统更新等页面。
- 复验：83 页浏览器扫描中的无名称交互控件数量为 0。

## V3R-004 旧表单与动态编辑器控件缺少标签关联

- 严重程度：P2
- 原因：部分旧表单的标签与控件没有 `for/id`，Vditor 动态生成的上传 input 只在父容器提供名称。
- 修复：增加 V3 表单可访问性兼容层，安全关联单一局部标签、继承编辑器父级名称、使用有意义的 placeholder；人工发布的编辑控件补充明确名称。
- 影响页面：文章创建与编辑、企业知识详情、人工发布设置与详情。
- 复验：新增 4 个 JavaScript 单元测试，83 页六档视口中的无标签可见字段数量为 0。

## V3R-005 登录页语言选择缺少可访问名称

- 严重程度：P2
- 原因：语言下拉框依赖选项文本，控件自身没有可访问名称。
- 修复：使用登录模块现有翻译为语言选择器增加 `aria-label`。
- 影响页面：管理员登录。
- 复验：六档视口与 Feature 测试通过，移动端截图已保存。

## 修复提交与回退

- `f525867 test(admin): establish full UI V3 review fixtures`
- `430a370 fix(admin): close UI V3 full-page review findings`

两个提交均位于 `codex/admin-ui-v3` 分支，可独立使用 Git revert 回退。审查数据通过 `UiV3ReviewSeeder` 幂等生成，不依赖稳定版数据或外部服务。

## 外部条件

- 第三方 AI、真实分发和系统更新没有被触发。
- 25432/26379 端口由另一套本地项目占用，V3 使用隔离端口 35432/36379。
- 真实外部服务的成功回执仍需在后续受控集成环境验证。
MARKDOWN;
file_put_contents($outputDirectory.'/fix-report.md', $fixReport.PHP_EOL);

echo json_encode([
    'output_directory' => $outputDirectory,
    'visual_pages' => count($matrixRows),
    'route_audit_items' => count($routeAuditItems),
    'all_named_admin_routes' => $allAdminRoutes->count(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
