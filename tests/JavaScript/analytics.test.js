import assert from 'node:assert/strict';
import test from 'node:test';

import { nearestTrendPointIndex } from '../../resources/js/admin/analytics.js';

const pointXs = Array.from({ length: 60 }, (_, index) => 48 + ((704 - 48) * index) / 59);
const bounds = { left: 100, width: 720 };
const viewBox = { x: 0, width: 720 };

test('trend pointer mapping follows plotted SVG points at both edges', () => {
    assert.equal(nearestTrendPointIndex(148, bounds, viewBox, pointXs), 0);
    assert.equal(nearestTrendPointIndex(804, bounds, viewBox, pointXs), 59);
});

test('trend pointer mapping selects the nearest plotted point', () => {
    const targetIndex = 24;
    const clientX = bounds.left + pointXs[targetIndex];

    assert.equal(nearestTrendPointIndex(clientX, bounds, viewBox, pointXs), targetIndex);
    assert.equal(nearestTrendPointIndex(-100, bounds, viewBox, pointXs), 0);
    assert.equal(nearestTrendPointIndex(2000, bounds, viewBox, pointXs), 59);
});
