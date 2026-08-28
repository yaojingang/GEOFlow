import test from 'node:test';
import assert from 'node:assert/strict';

import { mergeRecentPage, RECENT_PAGE_SIZE } from '../../resources/js/admin/sidebar-recent.js';

test('recent sidebar merges chat pages in server order and rejects non-chat items', () => {
    const first = [
        { kind: 'chat', id: 'chat-2', title: '周报' },
        { kind: 'feature', id: 'admin.analytics', title: '数据中心' },
    ];
    const next = [
        { kind: 'feature', id: 'admin.tasks.index', title: '任务管理' },
        { kind: 'chat', id: 'chat-1', title: '诊断' },
    ];

    assert.deepEqual(mergeRecentPage(first, next), [first[0], next[1]]);
});

test('recent sidebar reset pages honor archived conversation tombstones', () => {
    const items = [
        { kind: 'chat', id: 'archived-chat', title: '已归档' },
        { kind: 'chat', id: 'visible-chat', title: '保留' },
    ];

    assert.deepEqual(
        mergeRecentPage([], items, { reset: true, excludedIds: new Set(['archived-chat']) }),
        [items[1]],
    );
    assert.equal(RECENT_PAGE_SIZE, 10);
});
