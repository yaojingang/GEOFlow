import { configureTrustedStorage } from './lib/storage.js';

chrome.runtime.onInstalled.addListener(async () => {
    await configureTrustedStorage();
    await chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true });
});

chrome.runtime.onStartup.addListener(async () => {
    await configureTrustedStorage();
});
