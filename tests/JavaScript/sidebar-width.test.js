import test from 'node:test';
import assert from 'node:assert/strict';

import {
    SIDEBAR_DEFAULT_WIDTH,
    SIDEBAR_MAX_WIDTH,
    SIDEBAR_MIN_WIDTH,
    normalizeSidebarWidth,
} from '../../resources/js/admin/sidebar-width.js';

test('sidebar width stays within the supported desktop range', () => {
    assert.equal(normalizeSidebarWidth(180), SIDEBAR_MIN_WIDTH);
    assert.equal(normalizeSidebarWidth(312.4), 312);
    assert.equal(normalizeSidebarWidth(460), SIDEBAR_MAX_WIDTH);
});

test('sidebar width falls back when persisted data is unavailable or invalid', () => {
    assert.equal(normalizeSidebarWidth(null), SIDEBAR_DEFAULT_WIDTH);
    assert.equal(normalizeSidebarWidth(''), SIDEBAR_DEFAULT_WIDTH);
    assert.equal(normalizeSidebarWidth('invalid'), SIDEBAR_DEFAULT_WIDTH);
});
