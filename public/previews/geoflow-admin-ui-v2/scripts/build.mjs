import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectedPageCount, groups, pages } from './page-definitions.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(scriptDir, '..');

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

if (pages.length !== expectedPageCount) {
    throw new Error(`页面数量应为 ${expectedPageCount}，当前为 ${pages.length}`);
}

const duplicateIds = pages.filter((page, index) => pages.findIndex((candidate) => candidate.id === page.id) !== index);
const duplicatePaths = pages.filter((page, index) => pages.findIndex((candidate) => candidate.path === page.path) !== index);

if (duplicateIds.length || duplicatePaths.length) {
    throw new Error('页面 ID 或文件路径存在重复');
}

const htmlForPage = (page) => `<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>${escapeHtml(page.title)} · GEOFlow Admin UI V2</title>
    <link rel="stylesheet" href="../../assets/css/prototype.css">
    <script src="../../assets/vendor/lucide.min.js" defer></script>
    <script src="../../assets/js/page-data.js" defer></script>
    <script src="../../assets/js/geoflow-components.js" defer></script>
    <script src="../../assets/js/geoflow-shell.js" defer></script>
    <script src="../../assets/js/interactions.js" defer></script>
    <script src="../../assets/js/app.js" defer></script>
</head>
<body data-page-id="${escapeHtml(page.id)}">
    <div id="prototype-root"></div>
</body>
</html>
`;

const publicPages = pages.map((page, index) => ({ ...page, number: index + 1 }));
const pageDataSource = `/* PROTOTYPE: GEOFlow Admin UI V2 演示数据，仅用于界面评审。 */\nwindow.GEOFLOW_UI_V2 = ${JSON.stringify({ expectedPageCount, groups, pages: publicPages }, null, 4)};\n`;

await writeFile(resolve(rootDir, 'assets/js/page-data.js'), pageDataSource, 'utf8');
await writeFile(resolve(rootDir, 'manifest.json'), `${JSON.stringify({ schema: 'geoflow-admin-ui-v2/v1', generatedAt: '2026-08-03', expectedPageCount, groups, pages: publicPages }, null, 2)}\n`, 'utf8');

for (const page of publicPages) {
    const target = resolve(rootDir, 'pages', page.path);
    await mkdir(dirname(target), { recursive: true });
    await writeFile(target, htmlForPage(page), 'utf8');
}

const designLock = JSON.parse(await readFile(resolve(rootDir, 'design-lock.json'), 'utf8'));
if (designLock.pageCount !== expectedPageCount) {
    throw new Error('design-lock.json 的页面数量与定义不一致');
}

console.log(`Generated ${publicPages.length} GEOFlow Admin UI V2 prototype pages.`);
