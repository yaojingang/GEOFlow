import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const runtimeReverb = window.GEOFLOW_REVERB_CONFIG ?? {};
const reverbPath = (runtimeReverb.path ?? '').trim().replace(/\/+$/, '');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const reverbScheme = runtimeReverb.scheme ?? 'https';
const reverbPort = runtimeReverb.port ?? (reverbScheme === 'https' ? 443 : 80);
const reverbKey = runtimeReverb.key;
const reverbHost = runtimeReverb.host;
const authEndpoint = (runtimeReverb.authEndpoint ?? '/broadcasting/auth').trim();

if (!runtimeReverb.enabled || !reverbKey || !reverbHost) {
    window.Echo = null;
} else {
    const echoOptions = {
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: authEndpoint.startsWith('/') ? authEndpoint : `/${authEndpoint}`,
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        },
    };

    if (reverbPath !== '') {
        echoOptions.wsPath = reverbPath.startsWith('/') ? reverbPath : `/${reverbPath}`;
    }

    window.Echo = new Echo(echoOptions);
}
