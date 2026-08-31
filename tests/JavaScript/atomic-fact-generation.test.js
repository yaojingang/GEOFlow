import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { atomicFactGenerationPresentation } from '../../resources/js/admin/atomic-fact-generation.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const moduleSource = readFileSync(new URL('../../resources/js/admin/atomic-fact-generation.js', import.meta.url), 'utf8');
const copy = {
    status: { running: '正在分析知识内容', completed: '生成完成', partial: '部分完成' },
    title: { running: '正在提炼原子事实', completed: '原子事实生成完成', partial: '生成完成，部分内容需要处理' },
    message: { running: '正在分析', completed: '已生成 __COUNT__ 条候选事实', partial: '已保留 __COUNT__ 条可用候选事实' },
};

test('registers atomic fact generation as a page-scoped module', () => {
    assert.match(appSource, /data-atomic-fact-generation-form/);
    assert.match(appSource, /initializeAtomicFactGeneration/);
});

test('supports refresh recovery, progress metrics and cancellation', () => {
    assert.match(moduleSource, /activeGenerationRun/);
    assert.match(moduleSource, /cancel_url/);
    assert.match(moduleSource, /elapsed_seconds/);
    assert.match(moduleSource, /progress_percent/);
});

test('presents active generation as the extraction step', () => {
    const presentation = atomicFactGenerationPresentation({ status: 'running', stage: 'running', active: true }, copy);

    assert.equal(presentation.title, '正在提炼原子事实');
    assert.equal(presentation.stepIndex, 1);
    assert.equal(presentation.terminal, false);
    assert.equal(presentation.tone, 'loading');
});

test('presents completed and partial runs as reviewable results with a factual count', () => {
    const completed = atomicFactGenerationPresentation({ status: 'completed', active: false, candidate_count: 12 }, copy);
    const partial = atomicFactGenerationPresentation({ status: 'partial', active: false, candidate_count: 7 }, copy);

    assert.equal(completed.message, '已生成 12 条候选事实');
    assert.equal(completed.stepIndex, 2);
    assert.equal(completed.successful, true);
    assert.equal(partial.message, '已保留 7 条可用候选事实');
    assert.equal(partial.successful, true);
});
