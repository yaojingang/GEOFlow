import assert from 'node:assert/strict';
import test from 'node:test';

import {
    createSseParser,
    fallbackConversationTitle,
    parseSseBuffer,
    trustedFeatureUrl,
} from '../../resources/js/admin/ai-workspace.js';
import { markdownBlockSources, normalizeAnswerMarkdown } from '../../resources/js/admin/ai-workspace/markdown.js';

test('SSE parser preserves incomplete chunks and returns ordered events', () => {
    const first = parseSseBuffer('', 'event: status\ndata: {"stage":"under');

    assert.deepEqual(first.events, []);
    assert.equal(first.rest, 'event: status\ndata: {"stage":"under');

    const second = parseSseBuffer(first.rest, 'standing"}\n\nevent: delta\ndata: {"content":"你');
    assert.deepEqual(second.events, [{ event: 'status', data: { stage: 'understanding' } }]);
    assert.equal(second.rest, 'event: delta\ndata: {"content":"你');

    const third = parseSseBuffer(second.rest, '好"}\n\nevent: done\ndata: {"message_id":"m1"}\n\n');
    assert.deepEqual(third.events, [
        { event: 'delta', data: { content: '你好' } },
        { event: 'done', data: { message_id: 'm1' } },
    ]);
    assert.equal(third.rest, '');
});

test('incremental SSE parser handles arbitrary transport fragmentation', () => {
    const events = [];
    const parser = createSseParser((event) => events.push(event));

    for (const chunk of [
        'event: status\nda',
        'ta: {"stage":"retrieving"}\n\nevent: de',
        'lta\ndata: {"content":"A"}\n\nevent: delta\ndata: {"content":"B"}',
    ]) parser.push(chunk);
    parser.finish();

    assert.deepEqual(events, [
        { event: 'status', data: { stage: 'retrieving' } },
        { event: 'delta', data: { content: 'A' } },
        { event: 'delta', data: { content: 'B' } },
    ]);
});

test('SSE parser joins multiline data fields and tolerates plain text', () => {
    const parsed = parseSseBuffer('', [
        'event: delta',
        'data: {"content":',
        'data: "分片"}',
        '',
        'event: error',
        'data: unavailable',
        '',
        '',
    ].join('\n'));

    assert.deepEqual(parsed.events, [
        { event: 'delta', data: { content: '分片' } },
        { event: 'error', data: 'unavailable' },
    ]);
});

test('trusted feature URLs stay on the current origin and admin prefix', () => {
    const origin = 'https://geoflow.test';

    assert.equal(
        trustedFeatureUrl('/admin/tasks?state=active', origin, '/admin'),
        'https://geoflow.test/admin/tasks?state=active',
    );
    assert.equal(
        trustedFeatureUrl('https://geoflow.test/admin/articles', origin, '/admin'),
        'https://geoflow.test/admin/articles',
    );
    assert.equal(trustedFeatureUrl('/administer', origin, '/admin'), null);
    assert.equal(trustedFeatureUrl('/site/articles', origin, '/admin'), null);
    assert.equal(trustedFeatureUrl('https://evil.example/admin/tasks', origin, '/admin'), null);
    assert.equal(trustedFeatureUrl('//evil.example/admin/tasks', origin, '/admin'), null);
    assert.equal(trustedFeatureUrl('javascript:alert(1)', origin, '/admin'), null);
    assert.equal(trustedFeatureUrl('https://user:pass@geoflow.test/admin/tasks', origin, '/admin'), null);
    assert.equal(
        trustedFeatureUrl('/geoflow/admin/tasks', origin, '/geoflow/admin'),
        'https://geoflow.test/geoflow/admin/tasks',
    );
    assert.equal(trustedFeatureUrl('/admin/tasks', origin, '/geoflow/admin'), null);
});

test('conversation title fallback stays short and replaces low-information greetings', () => {
    assert.equal(fallbackConversationTitle('你好你好你好'), '日常交流');
    assert.equal(fallbackConversationTitle('Hello!!!'), '日常交流');
    assert.equal(fallbackConversationTitle('👋👋👋'), '日常交流');
    assert.equal(
        fallbackConversationTitle('这是一个超过十五个字符的会话标题测试'),
        '这是一个超过十五个字符的会话标',
    );
});

test('streaming markdown is split into stable top-level blocks, including an incomplete final block', () => {
    assert.deepEqual(markdownBlockSources([
        '# 结论',
        '',
        '第一段。',
        '',
        '- 步骤一',
        '- 步骤二',
        '',
        '```json',
        '{"status":"running"}',
    ].join('\n')), [
        '# 结论\n\n',
        '第一段。\n\n',
        '- 步骤一\n- 步骤二\n\n',
        '```json\n{"status":"running"}',
    ]);
});

test('short list items omit terminal periods without changing prose or code fences', () => {
    assert.equal(normalizeAnswerMarkdown([
        '- 已准备目标地址。',
        '- 已明确渠道类型.',
        '',
        '1. 进入内容分发页面。',
        '2. 保存渠道配置。',
        '',
        '普通说明段落需要保留句号。',
        '',
        '```text',
        '- 代码示例保留句号。',
        '```',
    ].join('\n')), [
        '- 已准备目标地址',
        '- 已明确渠道类型',
        '',
        '1. 进入内容分发页面',
        '2. 保存渠道配置',
        '',
        '普通说明段落需要保留句号。',
        '',
        '```text',
        '- 代码示例保留句号。',
        '```',
    ].join('\n'));
});

test('workspace client contains cancellation, safe markdown, and trusted link guards', async () => {
    const workspaceSource = await import('node:fs/promises')
        .then((fs) => fs.readFile(new URL('../../resources/js/admin/ai-workspace.js', import.meta.url), 'utf8'));
    const markdownSource = await import('node:fs/promises')
        .then((fs) => fs.readFile(new URL('../../resources/js/admin/ai-workspace/markdown.js', import.meta.url), 'utf8'));

    assert.match(workspaceSource, /new AbortController\(\)/u);
    assert.match(workspaceSource, /renderStopped/u);
    assert.match(workspaceSource, /pending\.content\.trim\(\) === ''/u);
    assert.match(workspaceSource, /trustedFeatureUrl\(/u);
    assert.match(workspaceSource, /requestAnimationFrame/u);
    assert.match(workspaceSource, /createStreamingMarkdownRenderer/u);
    assert.match(workspaceSource, /data\.userInitial|dataset\.userInitial/u);
    assert.match(workspaceSource, /3_000/u);
    assert.match(workspaceSource, /8_000/u);
    assert.match(workspaceSource, /event === 'title'/u);
    assert.match(workspaceSource, /conversation_title/u);
    assert.match(workspaceSource, /related_media/u);
    assert.match(workspaceSource, /thumbnail_url/u);
    assert.match(workspaceSource, /createElement\('img'\)/u);
    assert.match(workspaceSource, /image\.loading = 'lazy'/u);
    assert.match(workspaceSource, /createElement\('dialog'\)/u);
    assert.doesNotMatch(workspaceSource, /slice\(0, 30\)/u);
    assert.match(markdownSource, /DOMPurify\.sanitize/u);
    assert.match(markdownSource, /marked\.lexer/u);
    assert.match(markdownSource, /link\.replaceWith\(document\.createTextNode/u);
    assert.doesNotMatch(markdownSource, /allowedTags[^;]*['"]img['"]/u);
    assert.doesNotMatch(workspaceSource, /runs\/|approval|trace/iu);
});
