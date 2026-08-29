export function isStandaloneMode(windowObject = globalThis.window) {
    if (!windowObject) return false;

    return Boolean(
        windowObject.matchMedia?.('(display-mode: standalone)').matches
        || windowObject.navigator?.standalone === true,
    );
}

export function pwaRegistrationOptions(
    windowObject = globalThis.window,
    documentObject = globalThis.document,
) {
    const origin = windowObject?.location?.origin ?? 'http://localhost';
    const manifestHref = documentObject
        ?.querySelector?.('link[rel="manifest"]')
        ?.getAttribute?.('href') ?? '/manifest.webmanifest';
    const manifestUrl = new URL(manifestHref, `${origin}/`);
    const serviceWorkerUrl = new URL('service-worker.js', manifestUrl);
    const scopeUrl = new URL('./', manifestUrl);

    return {
        options: {
            scope: scopeUrl.pathname,
            updateViaCache: 'none',
        },
        url: serviceWorkerUrl.pathname,
    };
}

export async function registerPwaServiceWorker(
    windowObject = globalThis.window,
    navigatorObject = globalThis.navigator,
    documentObject = globalThis.document,
) {
    if (!windowObject?.isSecureContext || !navigatorObject?.serviceWorker) return null;

    try {
        const registration = pwaRegistrationOptions(windowObject, documentObject);

        return await navigatorObject.serviceWorker.register(registration.url, registration.options);
    } catch {
        return null;
    }
}

export function initializePwa({
    windowObject = globalThis.window,
    documentObject = globalThis.document,
    navigatorObject = globalThis.navigator,
} = {}) {
    if (!windowObject || !documentObject) return null;

    const installButtons = [...documentObject.querySelectorAll('[data-pwa-install]')];
    let deferredPrompt = null;

    const hideInstallButtons = () => {
        installButtons.forEach((button) => {
            button.hidden = true;
            button.disabled = false;
        });
    };

    const showInstallButtons = () => {
        installButtons.forEach((button) => {
            button.hidden = false;
            button.disabled = false;
        });
    };

    const markInstalled = () => {
        documentObject.documentElement?.setAttribute('data-pwa-installed', '');
        deferredPrompt = null;
        hideInstallButtons();
    };

    hideInstallButtons();
    if (isStandaloneMode(windowObject)) markInstalled();

    windowObject.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        if (isStandaloneMode(windowObject)) return;
        deferredPrompt = event;
        showInstallButtons();
    });

    windowObject.addEventListener('appinstalled', markInstalled);

    installButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (!deferredPrompt) return;

            const prompt = deferredPrompt;
            deferredPrompt = null;
            installButtons.forEach((installButton) => {
                installButton.disabled = true;
            });

            try {
                await prompt.prompt();
                await prompt.userChoice;
            } finally {
                hideInstallButtons();
            }
        });
    });

    documentObject.documentElement?.setAttribute('data-pwa-service-worker', 'registering');
    void registerPwaServiceWorker(windowObject, navigatorObject, documentObject).then((registration) => {
        documentObject.documentElement?.setAttribute(
            'data-pwa-service-worker',
            registration ? 'registered' : 'unavailable',
        );
    });

    return {
        hideInstallButtons,
        showInstallButtons,
    };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializePwa(), { once: true });
    } else {
        initializePwa();
    }
}
