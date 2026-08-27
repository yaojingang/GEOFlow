import assert from 'node:assert/strict';
import test from 'node:test';

import {
    initializeSystemUpdaterAutoReload,
    updaterReloadDelay,
} from '../../resources/js/admin/system-updates.js';

test('updater reload delay accepts only bounded millisecond values', () => {
    assert.equal(updaterReloadDelay('5000'), 5000);
    assert.equal(updaterReloadDelay('999'), null);
    assert.equal(updaterReloadDelay('60001'), null);
    assert.equal(updaterReloadDelay('invalid'), null);
});

test('active updater schedules one page reload', () => {
    let scheduledDelay = null;
    let reloads = 0;
    const root = {
        querySelector: () => ({ dataset: { systemUpdaterAutoReload: '5000' } }),
    };

    initializeSystemUpdaterAutoReload(
        root,
        (callback, delay) => {
            scheduledDelay = delay;
            callback();
            return 42;
        },
        () => {
            reloads++;
        },
    );

    assert.equal(scheduledDelay, 5000);
    assert.equal(reloads, 1);
});
