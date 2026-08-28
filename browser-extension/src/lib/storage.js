const CONNECTION_KEY = 'geoflow_browser_connection';
const PENDING_KEY = 'geoflow_pending_authorization';
const TASK_KEY = 'geoflow_current_task';

export async function configureTrustedStorage(storage = chrome.storage) {
    await storage.local.setAccessLevel?.({ accessLevel: 'TRUSTED_CONTEXTS' });
    await storage.session.setAccessLevel?.({ accessLevel: 'TRUSTED_CONTEXTS' });
}

export async function getConnection(storage = chrome.storage) {
    return (await storage.local.get(CONNECTION_KEY))[CONNECTION_KEY] ?? null;
}

export async function setConnection(connection, storage = chrome.storage) {
    await storage.local.set({ [CONNECTION_KEY]: connection });
}

export async function clearConnection(storage = chrome.storage) {
    await storage.local.remove(CONNECTION_KEY);
    await storage.session.remove([PENDING_KEY, TASK_KEY]);
}

export async function getPendingAuthorization(storage = chrome.storage) {
    return (await storage.session.get(PENDING_KEY))[PENDING_KEY] ?? null;
}

export async function setPendingAuthorization(value, storage = chrome.storage) {
    await storage.session.set({ [PENDING_KEY]: value });
}

export async function clearPendingAuthorization(storage = chrome.storage) {
    await storage.session.remove(PENDING_KEY);
}

export async function getCurrentTask(storage = chrome.storage) {
    return (await storage.session.get(TASK_KEY))[TASK_KEY] ?? null;
}

export async function setCurrentTask(value, storage = chrome.storage) {
    await storage.session.set({ [TASK_KEY]: value });
}

export async function clearCurrentTask(storage = chrome.storage) {
    await storage.session.remove(TASK_KEY);
}
