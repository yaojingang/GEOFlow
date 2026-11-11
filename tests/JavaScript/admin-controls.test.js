import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const css = postcss.parse(read('public/assets/css/admin-controls.css'));

function declarations(selector) {
    const rule = css.nodes.find((node) => node.type === 'rule' && node.selector === selector);
    assert.ok(rule, `Missing CSS rule: ${selector}`);

    return Object.fromEntries(rule.nodes.filter((node) => node.type === 'decl').map((node) => [node.prop, node.value]));
}

test('shared arrow styles are scoped to admin pages and preserve control colors and dimensions', () => {
    css.walkRules((rule) => {
        assert.ok(rule.selector.startsWith('.gf-admin-controls'), rule.selector);
    });

    const select = declarations(".gf-admin-controls select:not([multiple]):is(:not([size]), [size='1'])");
    assert.equal(select.appearance, 'none');
    assert.equal(select['padding-right'], 'var(--gf-control-text-reserve)');
    assert.equal(select['text-overflow'], 'ellipsis');
    for (const property of ['background', 'background-color', 'color', 'height', 'padding', 'font-size']) {
        assert.equal(select[property], undefined, `Do not override page-specific ${property}`);
    }
});

test('native arrows use 16px inset and size with 44px reserved for text', () => {
    const tokens = declarations('.gf-admin-controls');
    assert.equal(tokens['--gf-control-arrow-inset'], '1rem');
    assert.equal(tokens['--gf-control-arrow-size'], '1rem');
    assert.equal(tokens['--gf-control-text-reserve'], '2.75rem');
});

test('forced colors restore the native dropdown arrow without changing multi-select listboxes', () => {
    const media = css.nodes.find((node) => node.type === 'atrule' && node.params === '(forced-colors: active)');
    assert.ok(media);
    const rule = media.nodes.find((node) => node.type === 'rule');
    assert.match(rule.selector, /:not\(\[multiple\]\)/);
    assert.match(rule.selector, /\[size='1'\]/);
    const values = Object.fromEntries(rule.nodes.map((node) => [node.prop, node.value]));
    assert.equal(values.appearance, 'auto');
    assert.equal(values['background-image'], 'none');
});

test('forced colors preserve disclosure arrows with the user-selected system text color', () => {
    const media = css.nodes.find((node) => node.type === 'atrule' && node.params === '(forced-colors: active)');
    const rule = media?.nodes.find((node) => node.type === 'rule' && node.selector === '.gf-admin-controls .gf-disclosure::before');
    assert.ok(rule, 'Disclosure arrows need a forced-colors fallback after hiding the native marker');
    const values = Object.fromEntries(rule.nodes.map((node) => [node.prop, node.value]));
    assert.equal(values['background-color'], 'CanvasText');
    assert.equal(values['forced-color-adjust'], 'none');
});

test('article and lead forms have one shared arrow rather than an additional absolute chevron', () => {
    for (const path of ['resources/views/admin/articles/form.blade.php', 'resources/views/admin/lead-forms/form.blade.php']) {
        const template = read(path);
        assert.doesNotMatch(template, /<i\b[^>]*data-lucide="chevron-down"[^>]*class="[^"]*\babsolute\b/);
    }
});

test('dark result filters inherit a light chevron and disabled fields retain readable text', () => {
    const template = read('resources/views/admin/analytics/ai-visibility.blade.php');
    assert.match(template, /data-doubao-results data-gf-control-tone="dark"/);
    assert.match(declarations(".gf-admin-controls [data-gf-control-tone='dark']")['--gf-control-chevron'], /cbd5e1/);
    const disabled = declarations(".gf-admin-controls select:not([multiple]):is(:not([size]), [size='1']):disabled");
    assert.equal(disabled.opacity, undefined);
    assert.equal(disabled.color, undefined);
});

test('shared styles load after page styles in both shells and also cover the login language selector', () => {
    const layout = read('resources/views/admin/layouts/app.blade.php');
    assert.ok(layout.indexOf('assets/css/admin-controls.css') > layout.indexOf("@stack('styles')"));
    const bodyTags = layout.match(/<body\b[^>]+>/g);
    assert.equal(bodyTags.length, 2);
    for (const tag of bodyTags) assert.match(tag, /gf-admin-controls/);
    assert.match(read('resources/views/admin/auth/login.blade.php'), /assets\/css\/admin-controls\.css/);
});

test('compact user menus keep 12px trailing space while mobile language icons stay centered', () => {
    assert.equal(declarations('.gf-admin-controls .gf-user-button').padding, '4px 12px 4px 8px');
    assert.match(read('resources/views/admin/partials/header.blade.php'), /class="gf-user-button flex/);
    const mobile = css.nodes.find((node) => node.type === 'atrule' && node.params === '(max-width: 767px)');
    assert.ok(mobile);
    assert.equal(mobile.nodes[0].nodes.find((node) => node.prop === 'padding').value, '0');
});

test('legacy language arrow accounts for the padding inside its bordered wrapper', () => {
    const language = declarations('.gf-admin-controls .admin-locale-select');
    assert.equal(language['--gf-control-arrow-inset'], '.5rem');
    assert.equal(language['--gf-control-text-reserve'], '2.25rem');
});

test('disclosure arrows rotate on open and prompt pickers reset their older border-based arrow', () => {
    assert.equal(declarations('.gf-admin-controls details[open] > .gf-disclosure::before').transform, 'none');
    assert.equal(declarations('.gf-admin-controls .prompt-test-picker > summary::after').border, '0');
    assert.equal(declarations('.gf-admin-controls .prompt-test-picker[open] > summary::after').transform, 'rotate(180deg)');
});

test('dynamically generated topic evidence and prompt diagnosis disclosures share the same spacing', () => {
    assert.match(read('public/admin/topic-editor.js'), /heading\.className='gf-disclosure'/);
    assert.match(read('resources/js/admin/ai-prompt-test.js'), /element\('summary', labels\.dimensions\[key\], 'gf-disclosure'\)/);
});
