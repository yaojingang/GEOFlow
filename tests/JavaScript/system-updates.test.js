import assert from 'node:assert/strict';
import test from 'node:test';

import {
    copySystemUpdaterCommand,
    initializeSystemUpdaterAutoReload,
    initializeSystemUpdaterAuthorizationDialogs,
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

test('authorized updater actions collect central prompt fields and preserve the original submitter', async () => {
    class FakeElement {
        closest() { return null; }
    }
    class FakeInput extends FakeElement {
        constructor() {
            super();
            this.value = '';
        }
    }
    class FakeButton extends FakeElement {}
    class FakeForm extends FakeElement {
        constructor() {
            super();
            this.authorization = new FakeInput();
            this.password = new FakeInput();
            this.dataset = {
                authorizationLabel: 'Authorization code',
                authorizationPatternMessage: 'Enter six digits',
                dialogConfirmLabel: 'Update system',
                dialogGuidance: 'A verified backup is available.',
                dialogMessage: 'The service will restart.',
                dialogTitle: 'Update GEOFlow',
                dialogTone: 'warning',
                passwordLabel: 'Current password',
                passwordRequired: 'true',
                requiredMessage: 'Required',
            };
            this.submitter = null;
        }
        closest() { return this; }
        querySelector(selector) {
            if (selector.includes('updater_authorization_code')) return this.authorization;
            if (selector.includes('current_admin_password')) return this.password;
            return null;
        }
        requestSubmit(submitter) { this.submitter = submitter; }
    }

    const listeners = new Map();
    const root = {
        addEventListener(type, listener) { listeners.set(type, listener); },
    };
    const promptCalls = [];
    const windowRef = {
        AdminActionDialog: {
            async prompt(options) {
                promptCalls.push(options);
                return { authorization: '123456', password: 'secret-123' };
            },
        },
        Element: FakeElement,
        HTMLButtonElement: FakeButton,
        HTMLFormElement: FakeForm,
        HTMLInputElement: FakeInput,
    };
    const form = new FakeForm();
    const button = new FakeInput();
    const event = {
        target: form,
        submitter: button,
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };

    initializeSystemUpdaterAuthorizationDialogs(root, windowRef);
    await listeners.get('submit')(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(promptCalls.length, 1);
    assert.equal(promptCalls[0].fields.length, 2);
    assert.equal(promptCalls[0].fields[0].pattern, '[0-9]{6}');
    assert.equal(form.authorization.value, '123456');
    assert.equal(form.password.value, 'secret-123');
    assert.equal(form.submitter, button);
});
