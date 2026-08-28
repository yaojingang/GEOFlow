import { createHash } from 'node:crypto';
import { access, readFile, readdir } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectedPageCount, groups, pages } from './page-definitions.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(scriptDir, '..');
const failures = [];

const check = (condition, message) => {
    if (!condition) failures.push(message);
};

const exists = async (path) => {
    try {
        await access(path);
        return true;
    } catch {
        return false;
    }
};

const walk = async (directory) => {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const target = resolve(directory, entry.name);
        if (entry.isDirectory()) files.push(...await walk(target));
        if (entry.isFile()) files.push(target);
    }

    return files;
};

check(pages.length === expectedPageCount, `页面定义应为 ${expectedPageCount} 个，当前为 ${pages.length} 个`);
check(new Set(pages.map((page) => page.id)).size === expectedPageCount, '页面 ID 必须全部唯一');
check(new Set(pages.map((page) => page.path)).size === expectedPageCount, '页面路径必须全部唯一');
check(new Set(pages.map((page) => page.route)).size === expectedPageCount, '页面路由映射必须全部唯一');
check(new Set(groups.map((group) => group.id)).size === groups.length, '业务分组 ID 必须全部唯一');

const supportedTypes = new Set(['ai-workspace', 'analytics', 'cards', 'dashboard', 'detail', 'error', 'form', 'login', 'review', 'settings', 'table', 'wizard']);
const supportedRoles = new Set(['admin', 'guest', 'super_admin']);
for (const page of pages) {
    check(supportedTypes.has(page.type), `${page.id} 使用了未支持的页面母版 ${page.type}`);
    check(supportedRoles.has(page.role), `${page.id} 使用了未支持的角色 ${page.role}`);
    check(Boolean(page.title?.trim()), `${page.id} 缺少页面标题`);
    check(Boolean(page.subtitle?.trim()), `${page.id} 缺少页面说明`);
    check(Boolean(page.route?.trim()), `${page.id} 缺少路由映射`);
    check(page.path.endsWith('.html') && !page.path.startsWith('/') && !page.path.includes('..'), `${page.id} 的页面路径不安全：${page.path}`);
}

const groupIds = new Set(groups.map((group) => group.id));
for (const group of groups) {
    check(pages.some((page) => page.group === group.id), `业务分组没有页面：${group.id}`);
}
for (const page of pages) {
    check(groupIds.has(page.group), `${page.id} 使用了未定义的分组 ${page.group}`);

    const pageFile = resolve(rootDir, 'pages', page.path);
    check(await exists(pageFile), `缺少页面文件：${page.path}`);
    if (!(await exists(pageFile))) continue;

    const html = await readFile(pageFile, 'utf8');
    check(html.includes(`data-page-id="${page.id}"`), `${page.path} 的 page id 与定义不一致`);
    check(html.includes(`<title>${page.title.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;')} · GEOFlow Admin UI V2</title>`), `${page.path} 的 HTML 标题与定义不一致`);
    check(html.includes('../../assets/css/prototype.css'), `${page.path} 未引用公共 CSS`);
    check(html.includes('../../assets/js/app.js'), `${page.path} 未引用公共启动脚本`);
    check(!/<style\b/i.test(html), `${page.path} 包含内联 style 标签`);
    check(!/\sstyle\s*=/i.test(html), `${page.path} 包含内联 style 属性`);
    check(!/<script(?![^>]*\bsrc=)[^>]*>/i.test(html), `${page.path} 包含内联 script`);
}

const generatedPages = (await walk(resolve(rootDir, 'pages'))).filter((file) => extname(file) === '.html');
check(generatedPages.length === expectedPageCount, `实际生成页面应为 ${expectedPageCount} 个，当前为 ${generatedPages.length} 个`);

const manifest = JSON.parse(await readFile(resolve(rootDir, 'manifest.json'), 'utf8'));
check(manifest.expectedPageCount === expectedPageCount, 'manifest 页面数量与定义不一致');
check(manifest.pages.length === expectedPageCount, 'manifest 页面清单不完整');
check(manifest.pages.every((page, index) => page.id === pages[index].id), 'manifest 页面顺序或 ID 与定义不一致');
check(manifest.pages.every((page, index) => page.path === pages[index].path && page.route === pages[index].route && page.type === pages[index].type), 'manifest 页面字段与定义不一致');

const requiredAssets = [
    'index.html',
    'component-gallery.html',
    'qa-matrix.html',
    'README.md',
    'manifest.json',
    'design-lock.json',
    'qa/review-checklist.json',
    'assets/css/prototype.css',
    'assets/js/page-data.js',
    'assets/js/geoflow-components.js',
    'assets/js/geoflow-shell.js',
    'assets/js/interactions.js',
    'assets/js/app.js',
    'assets/js/catalog.js',
    'assets/vendor/lucide.min.js',
];

for (const asset of requiredAssets) {
    check(await exists(resolve(rootDir, asset)), `缺少公共文件：${asset}`);
}

const shellSource = await readFile(resolve(rootDir, 'assets/js/geoflow-shell.js'), 'utf8');
const buildSource = await readFile(resolve(rootDir, 'scripts/build.mjs'), 'utf8');
const shellPageIds = [
    ...shellSource.matchAll(/pageId:\s*'([^']+)'/g),
    ...shellSource.matchAll(/pageHref\('([^']+)'\)/g),
].map((match) => match[1]);
const pageIds = new Set(pages.map((page) => page.id));
for (const pageId of shellPageIds) {
    check(pageIds.has(pageId), `公共壳层引用了未定义页面：${pageId}`);
}

const authoredFiles = (await walk(rootDir)).filter((file) => {
    const relative = file.slice(rootDir.length + 1);
    return /\.(html|css|js)$/.test(file) && !relative.startsWith('assets/vendor/');
});

for (const file of authoredFiles) {
    const source = await readFile(file, 'utf8');
    check(!/linear-gradient|radial-gradient|backdrop-filter/i.test(source), `${file.slice(rootDir.length + 1)} 使用了设计锁禁止的视觉效果`);
    check(!/transition\s*:\s*all\b/i.test(source), `${file.slice(rootDir.length + 1)} 使用了 transition: all`);
    check(!/href=["']#["']/i.test(source), `${file.slice(rootDir.length + 1)} 包含无目标链接`);
}

const designLock = JSON.parse(await readFile(resolve(rootDir, 'design-lock.json'), 'utf8'));
check(designLock.pageCount === expectedPageCount, 'design-lock 页面数量与定义不一致');

const cssSource = await readFile(resolve(rootDir, 'assets/css/prototype.css'), 'utf8');
const lockedTokens = {
    '--gf-sidebar-width': `${designLock.layout.sidebarExpanded}px`,
    '--gf-sidebar-collapsed': `${designLock.layout.sidebarCollapsed}px`,
    '--gf-topbar-height': `${designLock.layout.topbarHeight}px`,
    '--gf-content-max': `${designLock.layout.standardMaxWidth}px`,
    '--gf-focus-max': `${designLock.layout.focusMaxWidth}px`,
    '--gf-radius-nav': `${designLock.radii.navigation}px`,
    '--gf-radius-control': `${designLock.radii.control}px`,
    '--gf-radius-focus': `${designLock.radii.focusCard}px`,
};
for (const [token, value] of Object.entries(lockedTokens)) {
    check(cssSource.includes(`${token}: ${value};`), `公共 CSS 未遵循设计锁：${token} 应为 ${value}`);
}
check(/\.gf-sidebar__nav\s*\{[^}]*padding:\s*0 12px 16px;/s.test(cssSource), '公共侧栏首项未与主内容画布顶部对齐');
check(/\.gf-sidebar__group:first-of-type\s*\{[^}]*margin-top:\s*0;/s.test(cssSource), '公共侧栏首组仍保留顶部偏移');
check(/html\s*\{[^}]*overflow-x:\s*clip;/s.test(cssSource), '页面根节点的横向溢出规则会破坏固定侧栏');
check(/body\s*\{[^}]*overflow-x:\s*clip;/s.test(cssSource), '页面主体的横向溢出规则会破坏固定侧栏');
check(/\.gf-sidebar\s*\{[^}]*height:\s*100vh;[^}]*position:\s*sticky;[^}]*top:\s*0;/s.test(cssSource), '桌面端公共侧栏缺少视口固定规则');
check(!/\.gf-sidebar__account-bar\s*\{[^}]*border-top:/s.test(cssSource), '公共侧栏底部账户栏仍有横向分隔线');
check(/\.gf-sidebar__group\s*\{[^}]*margin-top:\s*16px;/s.test(cssSource), '桌面端公共侧栏分组间距未使用紧凑节奏');
check(/\.gf-sidebar__items\s*\{[^}]*gap:\s*2px;/s.test(cssSource), '桌面端公共侧栏按钮间距未使用紧凑节奏');
check(/\.gf-sidebar__link\s*\{[^}]*min-height:\s*36px;[^}]*padding:\s*6px 12px;/s.test(cssSource), '桌面端公共侧栏按钮尺寸未使用紧凑节奏');
check(/@media \(max-width: 767px\)[\s\S]*?\.gf-sidebar__link\s*\{[^}]*min-height:\s*40px;[^}]*padding:\s*8px 12px;/s.test(cssSource), '移动端公共侧栏未保留触控尺寸');

const navigationBlock = shellSource.match(/const navigation = \[[\s\S]*?\n    \];/)?.[0] || '';
const expectedNavigationItems = designLock.navigation.flatMap((group) => group.items);
const expectedNavigationKeys = new Set(expectedNavigationItems.map((item) => item.key));
check((navigationBlock.match(/\{ key:/g) || []).length === expectedNavigationItems.length, '公共侧栏菜单数量与设计锁不一致');
for (const group of designLock.navigation) {
    check(navigationBlock.includes(`id: '${group.id}'`), `公共侧栏缺少分组：${group.id}`);
    if (group.label) check(navigationBlock.includes(`label: '${group.label}'`), `公共侧栏分组名称不一致：${group.label}`);
    for (const item of group.items) {
        check(navigationBlock.includes(`{ key: '${item.key}', label: '${item.label}'`), `公共侧栏缺少菜单：${item.label}`);
    }
}
check(pages.every((page) => !page.nav || expectedNavigationKeys.has(page.nav)), '存在无法映射到公共侧栏的页面导航状态');
check(shellSource.includes('data-shell-dialog="account"'), '公共侧栏缺少 Admin 账户入口');
check(shellSource.includes('data-shell-dialog="qr"'), '公共侧栏缺少二维码入口');
check(shellSource.includes('data-shell-dialog="settings"'), '公共侧栏缺少快捷设置入口');
check(shellSource.includes('data-account-password-form'), 'Admin 账户弹窗缺少修改密码表单');
check(shellSource.includes("pageHref('admin-users-index')"), 'Admin 账户弹窗缺少用户与权限管理入口');

const interactionSource = await readFile(resolve(rootDir, 'assets/js/interactions.js'), 'utf8');
check(interactionSource.includes('trapModalFocus'), '弹窗缺少键盘焦点循环');
check(interactionSource.includes('showShellDialog'), '侧栏账户与设置入口缺少公共弹窗交互');
check(buildSource.includes("const assetRevision = '20260822-sidebar-density-7'"), '原型资源缺少缓存版本标识');
check(interactionSource.includes("form.dataset.successMessage"), '演示表单缺少统一提交反馈');
check(interactionSource.includes("document.querySelectorAll('[data-tabs]')"), '详情标签页缺少交互绑定');
check(interactionSource.includes('runAiConversation'), 'AI 工作台缺少任务理解和结果演示逻辑');
check(interactionSource.includes('enterAiConversation'), 'AI 工作台缺少首页进入独立会话的状态切换');
check(interactionSource.includes('updateRunbar'), 'AI 工作台缺少 Agent 执行进度同步逻辑');
check(interactionSource.includes('data-ai-confirmation'), 'AI 工作台缺少任务确认结果交互');
check(interactionSource.includes('syncPromptState'), 'AI 工作台缺少输入内容与发送状态同步逻辑');
check(interactionSource.includes('syncConversationRoute'), 'AI 工作台缺少会话地址与侧栏状态同步逻辑');
check(interactionSource.includes('GeoFlowShell?.recentItems'), 'AI 工作台缺少最近会话恢复逻辑');
check(shellSource.includes('conversation=${encodeURIComponent(item.key)}'), '最近处理缺少 AI 会话恢复入口');

const componentSource = await readFile(resolve(rootDir, 'assets/js/geoflow-components.js'), 'utf8');
check(componentSource.includes('data-ai-landing'), 'AI 工作台缺少任务发起首页');
check(componentSource.includes('data-ai-composer-status'), 'AI 工作台缺少快捷任务填入反馈');
check(componentSource.includes('data-ai-reasoning'), 'AI 工作台缺少任务理解对话');
check(componentSource.includes('data-ai-result'), 'AI 工作台缺少执行结果对话');
check(componentSource.includes('data-ai-new-chat'), 'AI 工作台缺少返回新会话入口');
check(componentSource.includes('data-ai-runbar'), 'AI 工作台缺少 Agent 工作进度栏');
check(componentSource.includes('data-ai-auto-confirm'), 'AI 工作台缺少自动确认选项');
check(componentSource.includes('data-ai-confirm'), 'AI 工作台缺少一键确认入口');
check(componentSource.includes('data-ai-stop'), 'AI 工作台缺少暂停 Agent 工作入口');
check(cssSource.includes('.gf-ai-activity-stream'), '公共 CSS 缺少 Agent 动态执行流样式');
check(cssSource.includes('.gf-ai-capability'), '公共 CSS 缺少 AI 能力说明样式');

const catalogSource = await readFile(resolve(rootDir, 'assets/js/catalog.js'), 'utf8');
check(catalogSource.includes('href="#main-content"'), '原型目录缺少跳到主要内容链接');
check(catalogSource.includes('gf-root-action'), '原型目录缺少窄屏顶部操作样式钩子');

for (const reference of designLock.referencePriority) {
    const referencePath = resolve(rootDir, reference.file);
    check(await exists(referencePath), `设计基准不存在：${reference.file}`);
    if (!(await exists(referencePath))) continue;

    const digest = createHash('sha256').update(await readFile(referencePath)).digest('hex');
    check(digest === reference.sha256, `设计基准已变化，请重新审核：${reference.file}`);
}

if (failures.length) {
    console.error(`GEOFlow Admin UI V2 校验失败，共 ${failures.length} 项：`);
    failures.forEach((failure, index) => console.error(`${index + 1}. ${failure}`));
    process.exitCode = 1;
} else {
    console.log(`GEOFlow Admin UI V2 校验通过：${expectedPageCount} 个页面、${groups.length} 个业务分组、${requiredAssets.length} 个公共入口与资源。`);
}
