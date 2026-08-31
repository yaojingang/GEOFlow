import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    combineRetrievalReadiness,
    chooseRetrievalMode,
} from '../../resources/js/admin/ai-quality-retrieval-selector.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

const readiness = {
    1: {
        name: '知识库一',
        modes: {
            atomic_first: { available: true, blockers: [] },
            chunk: { available: true, blockers: [] },
            knowledge_broad: { available: true, blockers: [] },
        },
    },
    2: {
        name: '知识库二',
        modes: {
            atomic_first: { available: false, blockers: [{ message: '原子事实未发布' }] },
            chunk: { available: true, blockers: [] },
            knowledge_broad: { available: true, blockers: [] },
        },
    },
};

test('registers the retrieval selector as a page-scoped module', () => {
    assert.match(appSource, /data-ai-quality-retrieval-selector/);
});

test('requires every selected knowledge base to support a mode', () => {
    const combined = combineRetrievalReadiness(['1', '2'], readiness);

    assert.equal(combined.atomic_first.available, false);
    assert.equal(combined.chunk.available, true);
    assert.match(combined.atomic_first.blockers[0], /知识库二/);
});

test('keeps one concise reason when no knowledge base is selected', () => {
    const combined = combineRetrievalReadiness([], readiness, {
        emptySelection: '请先选择知识库',
    });

    assert.deepEqual(combined.atomic_first, {
        available: false,
        blockers: ['请先选择知识库'],
    });
    assert.equal(combined.chunk.available, false);
    assert.equal(combined.knowledge_broad.available, false);
});

test('deduplicates repeated blocker reasons for the help popover', () => {
    const repeated = {
        2: {
            name: '',
            modes: {
                atomic_first: {
                    available: false,
                    blockers: [{ message: '原子事实未发布' }, { message: '原子事实未发布' }],
                },
                chunk: { available: true, blockers: [] },
                knowledge_broad: { available: true, blockers: [] },
            },
        },
    };

    const combined = combineRetrievalReadiness(['2'], repeated);

    assert.deepEqual(combined.atomic_first.blockers, ['原子事实未发布']);
});

test('new untouched configurations choose the highest available mode', () => {
    const combined = combineRetrievalReadiness(['1', '2'], readiness);

    assert.equal(chooseRetrievalMode('', combined, false), 'chunk');
    assert.equal(chooseRetrievalMode('knowledge_broad', combined, true), 'knowledge_broad');
    assert.equal(chooseRetrievalMode('atomic_first', combined, true), '');
});
