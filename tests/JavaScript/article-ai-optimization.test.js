import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/admin/article-ai-optimization.js', import.meta.url), 'utf8');
const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

test('loads the article optimization controller only on its page', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-ai-optimization-panel\]'[\s\S]*import\('\.\/admin\/article-ai-optimization'\)/,
    );
});

test('opens the optimization panel when the quality card is nested inside the article form', async () => {
    const listeners = new Map();
    const classNames = new Set(['hidden']);
    const classList = {
        add: (name) => classNames.add(name),
        remove: (name) => classNames.delete(name),
        toggle: (name, force) => {
            if (force === undefined) {
                if (classNames.has(name)) classNames.delete(name);
                else classNames.add(name);
                return classNames.has(name);
            }
            if (force) classNames.add(name);
            else classNames.delete(name);
            return force;
        },
    };
    const startButton = {
        disabled: false,
        classList,
        addEventListener: () => {},
    };
    const panelNodes = new Map([
        ['[data-ai-optimization-i18n]', { textContent: '{}' }],
        ['[data-ai-optimization-initial]', { textContent: 'null' }],
        ['[data-ai-optimization-start]', startButton],
    ]);
    const panel = {
        classList,
        dataset: { featureEnabled: 'true' },
        querySelector: (selector) => panelNodes.get(selector) ?? null,
    };
    const openButton = {
        addEventListener: (type, listener) => listeners.set(type, listener),
        focus: () => {},
    };
    const form = {
        addEventListener: () => {},
        querySelector: () => null,
        toggleAttribute: () => {},
    };
    const root = {
        closest: (selector) => selector === '#article-edit-form' ? form : null,
        querySelector: (selector) => {
            if (selector === '[data-ai-optimization-panel]') return panel;
            if (selector === '[data-ai-optimization-open]') return openButton;
            return null;
        },
    };

    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    globalThis.document = {
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '#ai-quality-result' ? [root] : [],
    };
    globalThis.window = {
        addEventListener: () => {},
        crypto: { randomUUID: () => 'test-request-key' },
    };

    try {
        await import(`../../resources/js/admin/article-ai-optimization.js?ancestor-form=${Date.now()}`);
        assert.equal(typeof listeners.get('click'), 'function');
        listeners.get('click')();
        assert.equal(classNames.has('hidden'), false);
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});

test('renders untrusted candidate snippets through text nodes', () => {
    assert.match(source, /node\.textContent = String\(text \|\| ''\)/);
    assert.match(source, /modifications\?\.replaceChildren\(\)/);
    assert.doesNotMatch(source, /\.innerHTML\s*=/);
});

test('uses a request key and blocks optimization after unsaved editor input', () => {
    assert.match(source, /crypto\?\.randomUUID/);
    assert.match(source, /request_key: requestKey/);
    assert.match(source, /form\.addEventListener\('input', markDirty\)/);
    assert.match(source, /geo-article-editor-input/);
    assert.match(source, /startButton\.disabled = articleDirty/);
    assert.match(source, /applyButton\.disabled = articleDirty/);
    assert.match(source, /rollbackButton\.disabled = articleDirty/);
    assert.match(source, /applyButton\?\.addEventListener\('click'[\s\S]*if \(articleDirty\)/);
    assert.match(source, /rollbackButton\?\.addEventListener\('click'[\s\S]*if \(articleDirty\)/);
    assert.match(source, /form\.inert = locked/);
    assert.match(source, /form\.toggleAttribute\('aria-busy', locked\)/);
    assert.match(source, /applyButton\?\.addEventListener\('click'[\s\S]*lockArticleForm\(true\)[\s\S]*lockArticleForm\(false\)/);
    assert.match(source, /rollbackButton\?\.addEventListener\('click'[\s\S]*lockArticleForm\(true\)[\s\S]*lockArticleForm\(false\)/);
});

test('keeps safe terminal candidates visible and clears a previous candidate for a new run', () => {
    assert.match(source, /if \(current\?\.can_preview === true\)/);
    assert.match(source, /const canApply = current\?\.can_apply === true/);
    assert.match(source, /applyButton\?\.classList\.toggle\('hidden', !canApply\)/);
    assert.match(source, /applyButton\.disabled = articleDirty \|\| !canApply/);
    assert.match(source, /candidateLoadedFor = ''/);
    assert.match(source, /candidatePanel\?\.classList\.add\('hidden'\)/);
});

test('refreshes the candidate preview when a later round produces a new best result', () => {
    assert.match(source, /candidateSignature = \[runId, current\?\.best_score, current\?\.completed_rounds, current\?\.candidate_hash\]/);
    assert.match(source, /candidateLoadedFor === candidateSignature/);
    assert.match(source, /candidateLoadedFor = candidateSignature/);
});

test('ignores a late candidate response after a newer optimization state arrives', () => {
    assert.match(source, /candidateRequestGeneration/);
    assert.match(source, /const requestGeneration = \+\+candidateRequestGeneration/);
    assert.match(source, /requestGeneration !== candidateRequestGeneration/);
    assert.match(source, /candidateSignature !== currentCandidateSignature\(\)/);
});

test('uses the server polling contract for active and automatic candidate-ready states', () => {
    assert.match(source, /const active = current\?\.active === true/);
    assert.match(source, /current\?\.should_poll === true/);
    assert.doesNotMatch(source, /activeStates\.has/);
});

test('distinguishes reaching the target score from clearing all manual review items', () => {
    assert.match(source, /Number\(current\?\.best_score \|\| 0\) >= Number\(current\?\.target_score \|\| 0\)/);
    assert.match(source, /i18n\.stateTargetScoreReview/);
});

test('shows the rejected candidate score when an optimization did not improve the article', () => {
    assert.match(source, /current\?\.stop_reason === 'candidate_not_improved'/);
    assert.match(source, /current\?\.last_attempt/);
    assert.match(source, /replaceTokens\(i18n\.score/);
});

test('confirms every mutating optimization action through the shared central dialog', () => {
    assert.match(source, /const confirmOptimizationAction = async/);
    assert.match(source, /if \(!window\.AdminActionDialog\?\.confirm\) return false/);
    for (const action of ['startButton', 'applyButton', 'cancelButton', 'rollbackButton']) {
        assert.match(
            source,
            new RegExp(`${action}\\?*\\.addEventListener\\('click'[\\s\\S]*?await confirmOptimizationAction`),
        );
    }
});

test('confirms the save-and-run quality action before the article form is resubmitted', () => {
    assert.match(source, /const qualityButton = root\.querySelector\('\[data-ai-quality-submit\]'\)/);
    assert.match(source, /form\.addEventListener\('submit', async \(event\) =>/);
    assert.match(source, /event\.submitter !== qualityButton/);
    assert.match(source, /await confirmOptimizationAction\([\s\S]*?qualityTitle/);
    assert.match(source, /form\.requestSubmit\(qualityButton\)/);
});
