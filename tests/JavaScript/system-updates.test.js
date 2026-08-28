import assert from 'node:assert/strict';
import test from 'node:test';

import {
    copySystemUpdaterCommand,
    initializeSystemUpdaterAutoReload,
    initializeSystemUpdaterErrorDialog,
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

test('copy command reads the rendered command and updates the visible label', async () => {
    let copied = '';
    const label = { textContent: '复制命令' };
    const button = {
        dataset: {
            systemUpdaterCopy: '#updater-command-install',
            copiedLabel: '已复制',
        },
        querySelector: () => label,
    };
    const root = {
        querySelector: (selector) => selector === '#updater-command-install'
            ? { textContent: '  sudo geoflow-updater doctor --instance primary  ' }
            : null,
    };

    const copiedSuccessfully = await copySystemUpdaterCommand(
        button,
        root,
        async (value) => {
            copied = value;
        },
    );

    assert.equal(copiedSuccessfully, true);
    assert.equal(copied, 'sudo geoflow-updater doctor --instance primary');
    assert.equal(label.textContent, '已复制');
});

test('updater error dialog opens in the center and can be dismissed', () => {
    let showCount = 0;
    let closeCount = 0;
    let focusCount = 0;
    const closeButton = {
        focus: () => {
            focusCount++;
        },
    };
    const dialog = {
        open: true,
        querySelectorAll: () => [closeButton],
        showModal: () => {
            dialog.open = true;
            showCount++;
        },
        close: () => {
            dialog.open = false;
            closeCount++;
        },
    };
    const root = {
        querySelector: () => dialog,
    };

    const controller = initializeSystemUpdaterErrorDialog(root);

    assert.ok(controller);
    assert.equal(showCount, 1);
    assert.equal(focusCount, 1);
    assert.equal(closeCount, 1);

    controller.close();
    assert.equal(closeCount, 2);
});

test('updater error remains server-visible when the dialog API is unavailable', () => {
    const dialog = { open: true };
    const root = { querySelector: () => dialog };

    const controller = initializeSystemUpdaterErrorDialog(root);

    assert.equal(controller, null);
    assert.equal(dialog.open, true);
});
