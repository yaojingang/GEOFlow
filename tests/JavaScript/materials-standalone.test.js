import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeFailClosedMaterialConfirmations,
    initializeImageUploadForm,
    initializeMaterialImagePreviews,
} from '../../resources/js/admin/materials-standalone.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
        this.attributes = new Map();
        this.className = '';
        this.dataset = {};
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type) {
        const event = {
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));

        return event;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }
}

class FakeButton extends FakeEventTarget {
    constructor() {
        super();
        this.disabled = true;
        this.attributes.set('aria-disabled', 'true');
    }
}

class FakeDeleteForm extends FakeEventTarget {
    constructor(message) {
        super();
        this.dataset.confirmMessage = message;
        this.button = new FakeButton();
    }

    querySelector(selector) {
        return selector === '[data-material-delete-submit]' ? this.button : null;
    }
}

function deleteFixture(confirmAction, form = new FakeDeleteForm('Delete this item?')) {
    const root = {
        querySelectorAll(selector) {
            return selector === '[data-material-delete-form]' ? [form] : [];
        },
    };
    initializeFailClosedMaterialConfirmations(root, confirmAction);

    return form;
}

test('material deletion stays disabled until its confirmation handler is ready', () => {
    const form = new FakeDeleteForm('Delete this item?');

    assert.equal(form.button.disabled, true);
    deleteFixture(() => false, form);
    assert.equal(form.button.disabled, false);
    assert.equal(form.button.attributes.has('aria-disabled'), false);
});

test('material deletion delegates confirmation to the shared central controller', () => {
    assert.equal(deleteFixture(() => false).dispatch('submit').defaultPrevented, false);
    assert.equal(deleteFixture(() => {
        throw new Error('confirmation unavailable');
    }).dispatch('submit').defaultPrevented, false);
    assert.equal(deleteFixture(() => true).dispatch('submit').defaultPrevented, false);
});

test('the materials module is loaded only on its standalone surfaces', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-materials-standalone\], \[data-image-upload-form\]'[\s\S]*import\('\.\/admin\/materials-standalone'\)/,
    );
});

class FakeClassList {
    constructor(values = ['hidden']) {
        this.values = new Set(values);
    }

    toggle(value, force) {
        if (force) this.values.add(value);
        else this.values.delete(value);
    }

    contains(value) {
        return this.values.has(value);
    }
}

class FakeUploadElement extends FakeEventTarget {
    constructor() {
        super();
        this.children = [];
        this.classList = new FakeClassList();
        this.files = [];
        this.textContent = '';
        this.focused = false;
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren() {
        this.children = [];
    }

    focus() {
        this.focused = true;
    }
}

class FakeUploadForm extends FakeEventTarget {
    constructor() {
        super();
        this.dataset = {
            maxUploadBytes: '2048',
            allowedTypes: 'image/jpeg,image/png,image/gif,image/webp',
            allowedExtensions: 'jpg,jpeg,png,gif,webp',
            selectError: 'Select images',
            invalidError: 'Supported formats only',
            selectedLabel: '{count} selected',
            uploadingLabel: 'Uploading',
        };
        this.input = new FakeUploadElement();
        this.dropzone = new FakeUploadElement();
        this.dropzone.classList = new FakeClassList(['border-gray-300', 'bg-gray-50']);
        this.filesPanel = new FakeUploadElement();
        this.fileList = new FakeUploadElement();
        this.status = new FakeUploadElement();
        this.submitButton = new FakeUploadElement();
        this.submitButton.disabled = false;
        this.submitLabel = new FakeUploadElement();
        this.submitLabel.textContent = 'Upload';
        this.serverError = null;
        this.ownerDocument = {
            createElement() {
                return new FakeUploadElement();
            },
        };
    }

    querySelector(selector) {
        return new Map([
            ['input[type="file"][name="images[]"]', this.input],
            ['[data-image-upload-dropzone]', this.dropzone],
            ['[data-image-upload-files]', this.filesPanel],
            ['[data-image-upload-file-list]', this.fileList],
            ['[data-image-upload-status]', this.status],
            ['[data-image-upload-submit]', this.submitButton],
            ['[data-image-upload-submit-label]', this.submitLabel],
            ['[data-image-upload-server-error]', this.serverError],
        ]).get(selector) ?? null;
    }
}

test('image upload announces selection errors and the in-progress state', () => {
    const emptyForm = new FakeUploadForm();
    initializeImageUploadForm(emptyForm);
    const emptySubmit = emptyForm.dispatch('submit');
    assert.equal(emptySubmit.defaultPrevented, true);
    assert.equal(emptyForm.status.textContent, 'Select images');
    assert.equal(emptyForm.input.focused, true);

    const invalidForm = new FakeUploadForm();
    initializeImageUploadForm(invalidForm);
    invalidForm.input.files = [{ name: 'script.php', type: 'text/php', size: 100 }];
    invalidForm.input.dispatch('change');
    assert.equal(invalidForm.status.textContent, 'Supported formats only');
    assert.equal(invalidForm.dispatch('submit').defaultPrevented, true);

    const validForm = new FakeUploadForm();
    initializeImageUploadForm(validForm);
    validForm.input.files = [{ name: 'photo.webp', type: '', size: 2048 }];
    validForm.input.dispatch('change');
    assert.equal(validForm.status.textContent, '1 selected');
    assert.equal(validForm.dispatch('submit').defaultPrevented, false);
    assert.equal(validForm.attributes.get('aria-busy'), 'true');
    assert.equal(validForm.submitButton.disabled, true);
    assert.equal(validForm.submitLabel.textContent, 'Uploading');
});

test('image upload clears aria-invalid after replacing an invalid selection with a valid file', () => {
    const form = new FakeUploadForm();
    form.input.setAttribute('aria-invalid', 'true');
    form.input.setAttribute('aria-describedby', 'image-upload-help image-upload-status image-upload-error');
    form.serverError = new FakeUploadElement();
    form.serverError.id = 'image-upload-error';
    form.serverError.removed = false;
    form.serverError.remove = function () {
        this.removed = true;
    };
    form.dropzone.classList = new FakeClassList(['border-red-300', 'bg-red-50']);
    initializeImageUploadForm(form);

    form.input.files = [{ name: 'script.php', type: 'text/php', size: 100 }];
    form.input.dispatch('change');
    assert.equal(form.input.attributes.get('aria-invalid'), 'true');
    assert.equal(form.dropzone.classList.contains('border-red-300'), true);
    assert.equal(form.dropzone.classList.contains('bg-red-50'), true);
    assert.equal(form.dropzone.classList.contains('border-gray-300'), false);
    assert.equal(form.dropzone.classList.contains('bg-gray-50'), false);

    form.input.files = [{ name: 'photo.webp', type: 'image/webp', size: 1024 }];
    form.input.dispatch('change');
    assert.equal(form.input.attributes.has('aria-invalid'), false);
    assert.equal(form.input.getAttribute('aria-describedby'), 'image-upload-help image-upload-status');
    assert.equal(form.serverError.removed, true);
    assert.equal(form.status.textContent, '1 selected');
    assert.equal(form.dropzone.classList.contains('border-red-300'), false);
    assert.equal(form.dropzone.classList.contains('bg-red-50'), false);
    assert.equal(form.dropzone.classList.contains('border-gray-300'), true);
    assert.equal(form.dropzone.classList.contains('bg-gray-50'), true);

    form.input.files = [];
    form.input.dispatch('change');
    assert.equal(form.dropzone.classList.contains('border-red-300'), false);
    assert.equal(form.dropzone.classList.contains('bg-red-50'), false);
    assert.equal(form.dropzone.classList.contains('border-gray-300'), true);
    assert.equal(form.dropzone.classList.contains('bg-gray-50'), true);
});

test('image preview trigger populates the dialog and requests the shared modal manager', () => {
    const trigger = new FakeEventTarget();
    trigger.dataset = {
        imageSrc: '/storage/photo.webp',
        imageName: 'photo.webp',
        imageDimensions: '120x80',
        imageSize: '4 KB',
        imageUrl: '/storage/photo.webp',
    };
    const title = new FakeUploadElement();
    const preview = new FakeUploadElement();
    const info = new FakeUploadElement();
    const link = new FakeUploadElement();
    const events = [];
    const ownerDocument = {
        defaultView: {
            CustomEvent: class {
                constructor(type, options) {
                    this.type = type;
                    this.detail = options.detail;
                }
            },
        },
        dispatchEvent(event) {
            events.push(event);
        },
        querySelector(selector) {
            return new Map([
                ['[data-image-preview-title]', title],
                ['[data-image-preview-image]', preview],
                ['[data-image-preview-info]', info],
                ['[data-image-preview-url]', link],
            ]).get(selector) ?? null;
        },
    };
    const root = {
        ownerDocument,
        dataset: {
            imageDimensionsLabel: 'Dimensions',
            imageSizeLabel: 'Size',
        },
        querySelectorAll(selector) {
            return selector === '[data-image-preview-trigger]' ? [trigger] : [];
        },
    };

    initializeMaterialImagePreviews(root);
    trigger.dispatch('click');

    assert.equal(title.textContent, 'photo.webp');
    assert.equal(preview.getAttribute('src'), '/storage/photo.webp');
    assert.equal(preview.getAttribute('alt'), 'photo.webp');
    assert.equal(info.textContent, 'Dimensions: 120x80 | Size: 4 KB');
    assert.equal(link.getAttribute('href'), '/storage/photo.webp');
    assert.equal(link.textContent, '/storage/photo.webp');
    assert.equal(events[0].type, 'geoflow:modal:open');
    assert.equal(events[0].detail.name, 'image-preview');
    assert.equal(events[0].detail.opener, trigger);
});
