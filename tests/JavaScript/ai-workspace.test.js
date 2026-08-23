import assert from 'node:assert/strict';
import test from 'node:test';

import {
    composerControlsState,
    errorDialogContent,
    isSubmissionCurrent,
    runProgressStage,
    runStateGroup,
    shouldAcceptAnswerDelta,
    shouldAcceptRunSnapshot,
    shouldApplyRunSnapshot,
    shouldFetchRunUpdate,
    shouldSubmitPrompt,
} from '../../resources/js/admin/ai-workspace.js';

test('runtime errors become a guided dialog with preserved-draft actions', () => {
    const labels = {
        errorRuntimeTitle: '任务草稿已保留',
        errorRuntimeDescription: '完成模型配置后即可继续发送。',
        errorRuntimeHint: '输入内容仍在输入框中。',
        continueEditing: '返回继续编辑',
        openConfigurator: '前往 AI 配置器',
    };

    assert.deepEqual(errorDialogContent('runtime', '运行时当前关闭。', labels), {
        kind: 'runtime',
        title: '任务草稿已保留',
        description: '完成模型配置后即可继续发送。',
        detail: '运行时当前关闭。',
        hint: '输入内容仍在输入框中。',
        closeLabel: '返回继续编辑',
        primaryAction: 'configurator',
        primaryLabel: '前往 AI 配置器',
    });
});

test('session and network errors receive focused recovery copy', () => {
    const labels = {
        errorTitle: '操作暂未完成', errorHint: '请检查后重试。', returnToPage: '返回页面',
        errorSessionTitle: '登录状态已失效', errorSessionDescription: '请刷新并重新登录。',
        errorSessionHint: '刷新前可以先复制草稿。', refreshPage: '刷新并重新登录',
        errorNetworkTitle: '连接暂时中断', errorNetworkDescription: '请检查网络连接。',
        errorNetworkHint: '草稿仍保留在当前页面。',
    };

    assert.equal(errorDialogContent('session', 'expired', labels).primaryAction, 'reload');
    assert.equal(errorDialogContent('session', 'expired', labels).title, '登录状态已失效');
    assert.equal(errorDialogContent('network', 'offline', labels).primaryAction, null);
    assert.equal(errorDialogContent('network', 'offline', labels).hint, '草稿仍保留在当前页面。');
});

test('ordered snapshots reject duplicates and out-of-order events', () => {
    const current = { id: 'run-1', sequence: 5, version: 7 };

    assert.equal(shouldAcceptRunSnapshot(current, { id: 'run-1', sequence: 4, version: 99 }), false);
    assert.equal(shouldAcceptRunSnapshot(current, { id: 'run-1', sequence: 5, version: 7 }), false);
    assert.equal(shouldAcceptRunSnapshot(current, { id: 'run-1', sequence: 5, version: 8 }), true);
    assert.equal(shouldAcceptRunSnapshot(current, { id: 'run-1', sequence: 6, version: 1 }), true);
    assert.equal(shouldAcceptRunSnapshot(current, { id: 'run-2', sequence: 1, version: 1 }), true);
});

test('run snapshots require an explicit switch before replacing another active run', () => {
    const current = { id: 'run-new', sequence: 1, version: 1 };
    const delayed = { id: 'run-old', sequence: 99, version: 99 };

    assert.equal(shouldApplyRunSnapshot(current, delayed), false);
    assert.equal(shouldApplyRunSnapshot(current, delayed, true), true);
    assert.equal(shouldApplyRunSnapshot(null, delayed), true);
});

test('compact realtime events fetch only newer updates for the active conversation', () => {
    const current = { id: 'run-1', sequence: 5, version: 7 };

    assert.equal(shouldFetchRunUpdate(current, {
        run_id: 'run-1', conversation_id: 'conversation-1', sequence: 6, version: 7,
    }, 'conversation-1'), true);
    assert.equal(shouldFetchRunUpdate(current, {
        run_id: 'run-1', conversation_id: 'conversation-1', sequence: 5, version: 7,
    }, 'conversation-1'), false);
    assert.equal(shouldFetchRunUpdate(current, {
        run_id: 'run-2', conversation_id: 'conversation-1', sequence: 99, version: 99,
    }, 'conversation-1'), false);
    assert.equal(shouldFetchRunUpdate(null, {
        run_id: 'run-1', conversation_id: 'conversation-2', sequence: 1, version: 1,
    }, 'conversation-1'), false);
});

test('submission responses require the same view generation and conversation', () => {
    assert.equal(isSubmissionCurrent(4, 4, 'conversation-new', 'conversation-new'), true);
    assert.equal(isSubmissionCurrent(5, 4, 'conversation-new', 'conversation-new'), false);
    assert.equal(isSubmissionCurrent(4, 4, 'conversation-new', 'conversation-old'), false);
});

test('composer submits on Enter while preserving Shift+Enter and IME composition', () => {
    assert.equal(shouldSubmitPrompt({ key: 'Enter', shiftKey: false, isComposing: false }), true);
    assert.equal(shouldSubmitPrompt({ key: 'Enter', shiftKey: true, isComposing: false }), false);
    assert.equal(shouldSubmitPrompt({ key: 'Enter', shiftKey: false, isComposing: true }), false);
    assert.equal(shouldSubmitPrompt({ key: 'Escape', shiftKey: false, isComposing: false }), false);
});

test('composer keeps drafts editable and explains unavailable runtime on submit', () => {
    assert.deepEqual(composerControlsState(false, false, false), { inputDisabled: false, submitDisabled: true });
    assert.deepEqual(composerControlsState(false, false, true), { inputDisabled: false, submitDisabled: false });
    assert.deepEqual(composerControlsState(true, true, true), { inputDisabled: true, submitDisabled: true });
    assert.deepEqual(composerControlsState(true, false, true), { inputDisabled: false, submitDisabled: false });
});

test('active run states map to stable progress stages', () => {
    assert.equal(runProgressStage('received'), 'intake');
    assert.equal(runProgressStage('answering'), 'intake');
    assert.equal(runProgressStage('planning'), 'planning');
    assert.equal(runProgressStage('validating_plan'), 'planning');
    assert.equal(runProgressStage('queued'), 'execution');
    assert.equal(runProgressStage('running'), 'execution');
    assert.equal(runProgressStage('completed'), null);
    assert.equal(runProgressStage('awaiting_approval'), null);
});

test('all workflow terminal and attention states have stable presentation groups', () => {
    assert.equal(runStateGroup('completed'), 'success');
    assert.equal(runStateGroup('partially_completed'), 'warning');
    assert.equal(runStateGroup('outcome_unknown'), 'warning');
    assert.equal(runStateGroup('failed'), 'danger');
    assert.equal(runStateGroup('rejected'), 'danger');
    assert.equal(runStateGroup('cancelled'), 'neutral');
    assert.equal(runStateGroup('awaiting_approval'), 'attention');
    assert.equal(runStateGroup('awaiting_step_approval'), 'attention');
    assert.equal(runStateGroup('clarifying'), 'attention');
    assert.equal(runStateGroup('running'), 'active');
});

test('answer deltas reject stale runs, terminal runs, duplicates, and old generations', () => {
    const stream = { runId: 'run-1', runSequence: 6, sequence: 2, text: 'ab' };

    assert.equal(shouldAcceptAnswerDelta({ id: 'run-2', state: 'answering', sequence: 1 }, stream, {
        run_id: 'run-1', run_sequence: 7, chunk_sequence: 3, delta: 'c',
    }), false);
    assert.equal(shouldAcceptAnswerDelta({ id: 'run-1', state: 'cancelled', sequence: 7 }, stream, {
        run_id: 'run-1', run_sequence: 7, chunk_sequence: 3, delta: 'c',
    }), false);
    assert.equal(shouldAcceptAnswerDelta({ id: 'run-1', state: 'answering', sequence: 7 }, stream, {
        run_id: 'run-1', run_sequence: 6, chunk_sequence: 3, delta: 'c',
    }), false);
    assert.equal(shouldAcceptAnswerDelta({ id: 'run-1', state: 'answering', sequence: 6 }, stream, {
        run_id: 'run-1', run_sequence: 6, chunk_sequence: 2, delta: 'c',
    }), false);
    assert.equal(shouldAcceptAnswerDelta({ id: 'run-1', state: 'answering', sequence: 6 }, stream, {
        run_id: 'run-1', run_sequence: 8, chunk_sequence: 1, delta: 'new',
    }), true);
});
