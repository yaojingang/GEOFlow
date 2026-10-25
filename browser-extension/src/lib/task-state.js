export function activeTaskId(currentTask) {
    if (currentTask?.publication?.status !== 'in_progress') return null;

    const id = Number(currentTask.publication.id);

    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

export function hasConflictingActiveTask(currentTask, publication) {
    const activeId = activeTaskId(currentTask);
    const candidateId = Number(publication?.id);

    return activeId !== null && activeId !== candidateId;
}

export function resumeClaimedTask(currentTask, publication, nowIso = new Date().toISOString()) {
    if (publication?.status !== 'in_progress') return currentTask;
    if (hasConflictingActiveTask(currentTask, publication)) return currentTask;

    if (activeTaskId(currentTask) === Number(publication.id)) {
        return { ...currentTask, publication };
    }

    return {
        publication,
        tabId: null,
        startedAt: publication.claim?.claimed_at || nowIso,
    };
}

export function publicationClipboardText(payload = {}, copyTitle = false) {
    if (copyTitle) return payload.title ?? '';

    const body = payload.body_plain ?? '';
    if (! payload.append_source_link || ! payload.source_url) return body;
    try {
        const url = new URL(payload.source_url);
        if (! ['http:', 'https:'].includes(url.protocol) || url.username || url.password) return body;

        return `${body}\n\n${payload.source_url}`;
    } catch {
        return body;
    }
}
