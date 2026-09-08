export function taskDraftContext(card) {
    return card?.id && Number.isInteger(card.revision) && card.revision > 0
        ? { task_draft_id: card.id, task_draft_revision: card.revision }
        : {};
}

export function syncTaskCardActions(root, current, generating, labels = {}) {
    root.querySelectorAll('[data-task-draft]').forEach((card) => {
        const active = card.dataset.taskDraft === current?.id
            && Number(card.dataset.taskRevision) === current?.revision
            && ['collecting', 'ready'].includes(current?.status);
        card.querySelectorAll('button').forEach((button) => {
            button.disabled = generating || !active;
            button.title = active ? '' : labels.old ?? 'Use the latest task summary';
        });
        card.classList.toggle('is-old', !active && ['collecting', 'ready'].includes(card.dataset.taskStatus));
    });
}

export function renderTaskCard(target, data, { documentRef, labels = {}, safeUrl, onPrompt, onConfirm, onAdjust }) {
    if (!data?.id || !Array.isArray(data.rows)) return;
    const element = (tag, className, text) => {
        const node = documentRef.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    };
    const card = element('section', 'gf-ai-task-card');
    card.dataset.taskDraft = data.id;
    card.dataset.taskRevision = String(data.revision);
    card.dataset.taskStatus = data.status;
    const header = element('header');
    header.append(element('h3', '', data.title), element('span', 'gf-ai-task-card__badge', labels[data.status] ?? data.status));
    card.append(header);
    if (data.guidance) card.append(element('p', 'gf-ai-task-card__guidance', data.guidance));
    (Array.isArray(data.questions) ? data.questions : []).slice(0, 2).forEach((question) => {
        const group = element('div', 'gf-ai-task-card__question');
        group.append(element('p', '', question.label));
        const choices = element('div', 'gf-ai-task-card__choices');
        (Array.isArray(question.options) ? question.options : []).slice(0, 6).forEach((option) => {
            const button = element('button', '', option.label);
            button.type = 'button';
            button.addEventListener('click', () => onPrompt(String(option.prompt), option.choice, data));
            choices.append(button);
        });
        group.append(choices);
        card.append(group);
    });
    const details = element('details', 'gf-ai-task-card__details');
    details.open = data.status === 'ready' || data.status === 'created';
    details.append(element('summary', '', labels.details ?? 'Saved settings'));
    const list = element('dl', 'gf-ai-task-card__rows');
    data.rows.forEach((row) => {
        const pair = element('div');
        pair.append(element('dt', '', row.label), element('dd', '', row.value));
        list.append(pair);
    });
    details.append(list);
    card.append(details);
    const actions = element('div', 'gf-ai-task-card__actions');
    const button = (label, action, className = '') => {
        const node = element('button', className, label);
        node.type = 'button';
        node.addEventListener('click', action);
        actions.append(node);
    };
    if (data.status === 'ready') {
        button(labels.confirm ?? 'Create task', () => onConfirm(data), 'is-primary');
        button(labels.adjust ?? 'Adjust settings', onAdjust);
    }
    if (['collecting', 'ready'].includes(data.status)) {
        button(labels.cancel ?? 'Cancel creation', () => onPrompt(labels.cancelPrompt ?? 'Cancel task creation'));
    }
    (Array.isArray(data.links) ? data.links : []).forEach((link) => {
        const url = safeUrl(link.url);
        if (!url) return;
        const anchor = element('a', '', link.label);
        anchor.href = url;
        actions.append(anchor);
    });
    card.append(actions);
    target.append(card);
    return card;
}
