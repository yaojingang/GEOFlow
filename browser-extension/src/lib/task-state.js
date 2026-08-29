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
