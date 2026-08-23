import assert from 'node:assert/strict';
import test from 'node:test';

import {
    controlHasAccessibleName,
    enhanceControlAccessibility,
} from '../../resources/js/admin/form-accessibility.js';

function fixture({ labelText = '名称', placeholder = '', ariaLabel = '' } = {}) {
    const attributes = new Map();
    if (placeholder) attributes.set('placeholder', placeholder);
    if (ariaLabel) attributes.set('aria-label', ariaLabel);

    const label = {
        textContent: labelText,
        htmlFor: '',
        contains: () => false,
    };
    const control = {
        id: '',
        parentElement: null,
        ownerDocument: { querySelectorAll: () => [label] },
        getAttribute: (name) => attributes.get(name) ?? '',
        setAttribute: (name, value) => attributes.set(name, value),
        closest: () => null,
    };
    const container = {
        parentElement: null,
        matches: () => false,
        querySelectorAll: (selector) => selector === 'label' ? [label] : [control],
    };
    control.parentElement = container;

    return { attributes, control, label };
}

test('connects an adjacent visible label to a legacy form control', () => {
    const { control, label } = fixture();

    assert.equal(enhanceControlAccessibility(control), true);
    assert.match(control.id, /^gf-field-\d+$/);
    assert.equal(label.htmlFor, control.id);
    assert.equal(controlHasAccessibleName(control), true);
});

test('keeps an existing accessible name unchanged', () => {
    const { control, label } = fixture({ ariaLabel: '自定义名称' });

    assert.equal(enhanceControlAccessibility(control), false);
    assert.equal(control.id, '');
    assert.equal(label.htmlFor, '');
});

test('uses a meaningful placeholder when no local label exists', () => {
    const { attributes, control } = fixture({ labelText: '', placeholder: '输入站点名称' });

    assert.equal(enhanceControlAccessibility(control), true);
    assert.equal(attributes.get('aria-label'), '输入站点名称');
});

test('inherits the accessible name from a generated editor upload control', () => {
    const { attributes, control } = fixture({ labelText: '' });
    control.parentElement.getAttribute = (name) => name === 'aria-label' ? '上传图片或文件' : '';

    assert.equal(enhanceControlAccessibility(control), true);
    assert.equal(attributes.get('aria-label'), '上传图片或文件');
});
