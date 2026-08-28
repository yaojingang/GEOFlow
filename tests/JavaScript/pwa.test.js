import assert from 'node:assert/strict';
import test from 'node:test';

import {
    initializePwa,
    isStandaloneMode,
    pwaRegistrationOptions,
    registerPwaServiceWorker,
} from '../../resources/js/pwa.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    async dispatch(type, event = {}) {
        for (const listener of this.listeners.get(type) ?? []) {
            await listener(event);
        }
    }
}

class FakeButton extends FakeEventTarget {
    constructor() {
        super();
        this.disabled = false;
        this.hidden = false;
    }
}

function fixture({ standalone = false, secure = true } = {}) {
    const button = new FakeButton();
    const documentElement = {
        attributes: new Set(),
        attributeValues: new Map(),
        getAttribute(name) {
            return this.attributeValues.get(name) ?? null;
        },
        setAttribute(name, value = '') {
            this.attributes.add(name);
            this.attributeValues.set(name, value);
        },
    };
    const documentObject = {
        documentElement,
        manifestHref: '/manifest.webmanifest',
        querySelector(selector) {
            if (selector !== 'link[rel="manifest"]') return null;

            return {
                getAttribute: () => this.manifestHref,
            };
        },
        querySelectorAll(selector) {
            return selector === '[data-pwa-install]' ? [button] : [];
        },
    };
    const windowObject = new FakeEventTarget();
    windowObject.isSecureContext = secure;
    windowObject.location = { origin: 'http://localhost' };
    windowObject.matchMedia = () => ({ matches: standalone });
    windowObject.navigator = { standalone: false };

    const registrations = [];
    const navigatorObject = {
        serviceWorker: {
            async register(url, options) {
                registrations.push({ options, url });

                return { options, url };
            },
        },
    };

    return { button, documentElement, documentObject, navigatorObject, registrations, windowObject };
}

test('registers a root-scoped service worker without using the HTTP cache', async () => {
    const { documentObject, navigatorObject, registrations, windowObject } = fixture();

    const registration = await registerPwaServiceWorker(windowObject, navigatorObject, documentObject);

    assert.deepEqual(registration, {
        options: { scope: '/', updateViaCache: 'none' },
        url: '/service-worker.js',
    });
    assert.deepEqual(registrations, [registration]);
});

test('keeps manifest, service worker, and scope inside a configured subdirectory', () => {
    const { documentObject, windowObject } = fixture();
    documentObject.manifestHref = '/geoflow/manifest.webmanifest';

    assert.deepEqual(pwaRegistrationOptions(windowObject, documentObject), {
        options: { scope: '/geoflow/', updateViaCache: 'none' },
        url: '/geoflow/service-worker.js',
    });
});

test('does not register a service worker outside a secure context', async () => {
    const { navigatorObject, registrations, windowObject } = fixture({ secure: false });

    assert.equal(await registerPwaServiceWorker(windowObject, navigatorObject), null);
    assert.deepEqual(registrations, []);
});

test('exposes the service worker registration state for runtime diagnostics', async () => {
    const { documentElement, documentObject, navigatorObject, windowObject } = fixture();

    initializePwa({ documentObject, navigatorObject, windowObject });
    assert.equal(documentElement.getAttribute('data-pwa-service-worker'), 'registering');

    await Promise.resolve();
    await Promise.resolve();

    assert.equal(documentElement.getAttribute('data-pwa-service-worker'), 'registered');
});

test('reveals the install action only while the browser prompt is available', async () => {
    const { button, documentObject, windowObject } = fixture();
    let prevented = false;
    let promptCalls = 0;

    initializePwa({ documentObject, navigatorObject: {}, windowObject });
    assert.equal(button.hidden, true);

    await windowObject.dispatch('beforeinstallprompt', {
        preventDefault() {
            prevented = true;
        },
        async prompt() {
            promptCalls += 1;
        },
        userChoice: Promise.resolve({ outcome: 'accepted' }),
    });

    assert.equal(prevented, true);
    assert.equal(button.hidden, false);

    await button.dispatch('click');

    assert.equal(promptCalls, 1);
    assert.equal(button.hidden, true);
    assert.equal(button.disabled, false);
});

test('keeps the install action hidden in standalone mode', async () => {
    const { button, documentElement, documentObject, windowObject } = fixture({ standalone: true });

    initializePwa({ documentObject, navigatorObject: {}, windowObject });
    await windowObject.dispatch('beforeinstallprompt', { preventDefault() {} });

    assert.equal(isStandaloneMode(windowObject), true);
    assert.equal(button.hidden, true);
    assert.equal(documentElement.attributes.has('data-pwa-installed'), true);
});
