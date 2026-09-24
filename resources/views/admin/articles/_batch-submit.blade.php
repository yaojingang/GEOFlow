const batchForm = document.getElementById('batch-form');
if (batchForm) {
    function clearBatchSubmission() {
        delete batchForm.dataset.articleBatchBusy;
        delete batchForm.dataset.articleBatchSubmitted;
        document.getElementById('batch-selected-ids')?.replaceChildren();
        updateSelectedCount();
    }

    function selectedBatchState() {
        const selected = Array.from(document.querySelectorAll('.article-checkbox:checked'));
        const action = document.getElementById('batch-action')?.value ?? '';
        const status = document.getElementById('status-select')?.value ?? '';
        const review = document.getElementById('review-select')?.value ?? '';
        return { selected, action, fingerprint: JSON.stringify([action, status, review, selected.map(node => [node.value, node.dataset.workflowVersion])]) };
    }

    function prepareBatchSubmission(state) {
        const container = document.getElementById('batch-selected-ids');
        if (!container) return false;
        container.replaceChildren();
        const seen = new Set();
        for (const checkbox of state.selected) {
            if (seen.has(checkbox.value)) continue;
            seen.add(checkbox.value);
            const values = { 'article_ids[]': checkbox.value };
            if (checkbox.dataset.workflowVersion !== undefined) values[`workflow_versions[${checkbox.value}]`] = checkbox.dataset.workflowVersion;
            for (const [name, value] of Object.entries(values)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                container.appendChild(input);
            }
        }
        batchForm.action = ARTICLE_BATCH_ROUTES[state.action];
        batchForm.dataset.articleBatchBusy = 'true';
        batchForm.dataset.articleBatchSubmitted = 'true';
        const execute = document.querySelector('[data-batch-execute]');
        if (execute) execute.disabled = true;
        return true;
    }

    batchForm.addEventListener('submit', async function(event) {
        if (event.defaultPrevented) return;
        const state = selectedBatchState();
        if (state.action === 'export_markdown') return;
        if (batchForm.dataset.articleBatchBusy === 'true') {
            event.preventDefault();
            return;
        }
        document.getElementById('batch-selected-ids')?.replaceChildren();
        if (state.selected.length === 0 || !ARTICLE_BATCH_ROUTES[state.action]) {
            event.preventDefault();
            showArticleNotice(state.selected.length === 0 ? (IS_TRASH_VIEW ? TRASH_I18N.alertSelect : ARTICLES_I18N.selectArticles) : ARTICLES_I18N.selectAction, document.getElementById('batch-action'));
            return;
        }
        const required = state.action === 'batch_update_status' ? ['status-select', ARTICLES_I18N.selectStatus]
            : state.action === 'batch_update_review' ? ['review-select', ARTICLES_I18N.selectReview] : null;
        if (required && !document.getElementById(required[0])?.value) {
            event.preventDefault();
            showArticleNotice(required[1], document.getElementById(required[0]));
            return;
        }
        const confirmation = state.action === 'batch_restore' ? [TRASH_I18N.confirmBatchRestore, 'success']
            : state.action === 'batch_force_delete' ? [TRASH_I18N.confirmBatchForceDelete, 'danger']
            : state.action === 'delete_articles' ? [ARTICLES_I18N.confirmDeleteSelected, 'danger'] : null;
        if (!confirmation) {
            if (!prepareBatchSubmission(state)) event.preventDefault();
            return;
        }
        event.preventDefault();
        const generation = batchForm.dataset.articleBatchGeneration || '0';
        batchForm.dataset.articleBatchBusy = 'true';
        updateSelectedCount();
        try {
            const confirmed = await confirmArticleAction(confirmation[0].replace('__COUNT__', String(state.selected.length)), confirmation[1], event.submitter);
            if (!confirmed || generation !== (batchForm.dataset.articleBatchGeneration || '0')
                || selectedBatchState().fingerprint !== state.fingerprint) return;
            if (prepareBatchSubmission(selectedBatchState())) {
                HTMLFormElement.prototype.submit.call(batchForm);
            }
        } finally {
            if (batchForm.dataset.articleBatchSubmitted !== 'true') clearBatchSubmission();
        }
    });
    window.addEventListener('pageshow', clearBatchSubmission);
    updateSelectedCount();
}
