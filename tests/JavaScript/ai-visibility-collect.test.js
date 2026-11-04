import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/admin/analytics/_ai-visibility-collect.blade.php', import.meta.url), 'utf8');
const labels = {
    'admin.analytics.ai_visibility.collect.selection_counter': 'Selected :count/:cap',
    'admin.analytics.ai_visibility.collect.select_all': 'Select all',
    'admin.analytics.ai_visibility.collect.select_all_clear': 'Clear selection',
};
const source = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replaceAll('{{ $collectSelectionCap }}', '50')
    .replace(/@json\(__\('([^']+)'\)\)/g, (_, key) => JSON.stringify(labels[key]));

function element(attributes = {}) {
    return {
        attributes, textContent: '', listeners: {},
        getAttribute(name) { return this.attributes[name] ?? null; },
        hasAttribute(name) { return name in this.attributes; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener(name, listener) { this.listeners[name] = listener; },
        trigger(name) { this.listeners[name]?.(); },
    };
}

function fixture(librarySizes = [3, 3]) {
    const libraries = librarySizes.map((size, library) => {
        const boxes = Array.from({ length: size }, (_, index) => ({
            ...element(index === 0 ? { 'data-ai-visibility-recent-success': '1' } : {}),
            checked: false, disabled: false,
        }));
        const id = `library-${library}`;
        return {
            id, boxes,
            querySelectorAll(selector) {
                return selector.endsWith(':checked') ? boxes.filter(box => box.checked) : boxes;
            },
            button: element({ 'data-ai-visibility-select-all': id }),
            counter: element({ 'data-ai-visibility-counter': id }),
        };
    });
    const boxes = libraries.flatMap(library => library.boxes);
    const globalCounter = element();
    const document = {
        getElementById: id => libraries.find(library => library.id === id),
        querySelector: () => globalCounter,
        querySelectorAll(selector) {
            if (selector === '[data-ai-visibility-select-all]') return libraries.map(library => library.button);
            if (selector === '[data-ai-visibility-counter]') return libraries.map(library => library.counter);
            return selector.endsWith(':checked') ? boxes.filter(box => box.checked) : boxes;
        },
    };
    vm.runInNewContext(source, { document });
    return { libraries, boxes, globalCounter, select(box) { box.checked = true; box.trigger('change'); } };
}

test('bulk selection skips recent success while manual selection permits resampling and clear removes it', () => {
    const page = fixture();
    const library = page.libraries[0];
    library.button.trigger('click');
    assert.deepEqual(library.boxes.map(box => box.checked), [false, true, true]);
    assert.equal(library.counter.textContent, 'Selected 2/50');
    page.select(library.boxes[0]);
    assert.equal(library.counter.textContent, 'Selected 3/50');
    assert.equal(library.button.textContent, 'Clear selection');
    library.button.trigger('click');
    assert.equal(library.boxes.some(box => box.checked), false);
    assert.equal(page.globalCounter.textContent, 'Selected 0/50');
});

test('bulk selection shares the global cap across libraries and ignores disabled keywords', () => {
    const page = fixture([31, 31]);
    page.libraries[0].boxes[1].disabled = true;
    page.libraries[0].button.trigger('click');
    page.libraries[1].button.trigger('click');
    assert.equal(page.boxes.filter(box => box.checked).length, 50);
    assert.equal(page.libraries[0].counter.textContent, 'Selected 29/50');
    assert.equal(page.libraries[1].counter.textContent, 'Selected 21/50');
    assert.equal(page.globalCounter.textContent, 'Selected 50/50');
});

test('checking an earlier checkbox at the cap rejects that click and preserves previous selections', () => {
    const page = fixture([51]);
    page.boxes.slice(1).forEach(box => page.select(box));
    assert.equal(page.boxes.filter(box => box.checked).length, 50);
    page.select(page.boxes[0]);
    assert.equal(page.boxes[0].checked, false);
    assert.equal(page.boxes[50].checked, true);
    assert.equal(page.globalCounter.textContent, 'Selected 50/50');
});

test('a library containing only recent successes remains available for explicit manual resampling', () => {
    const page = fixture([1]);
    page.libraries[0].button.trigger('click');
    assert.equal(page.boxes[0].checked, false);
    page.select(page.boxes[0]);
    assert.equal(page.boxes[0].checked, true);
    assert.equal(page.libraries[0].button.textContent, 'Clear selection');
});
