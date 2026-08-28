export function normalizeGeoflowBaseUrl(input) {
    const raw = String(input ?? '').trim();
    let url;
    try {
        url = new URL(raw);
    } catch {
        throw new Error('Enter a valid GEOFlow URL.');
    }

    if (url.username || url.password) {
        throw new Error('GEOFlow URLs cannot contain credentials.');
    }
    if (url.search) {
        throw new Error('GEOFlow URLs cannot contain a query string.');
    }
    if (url.hash) {
        throw new Error('GEOFlow URLs cannot contain a fragment.');
    }

    const localHosts = new Set(['localhost', '127.0.0.1', '[::1]']);
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && localHosts.has(url.hostname))) {
        throw new Error('Remote GEOFlow instances must use HTTPS.');
    }

    url.pathname = url.pathname.replace(/\/+$/, '');

    return url.toString().replace(/\/$/, '');
}

export function originPermissionPattern(input) {
    let url;
    try {
        url = new URL(String(input ?? '').trim());
    } catch {
        throw new Error('Enter a valid website URL.');
    }
    if (url.username || url.password) {
        throw new Error('Website URLs cannot contain credentials.');
    }
    const localHosts = new Set(['localhost', '127.0.0.1', '[::1]']);
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && localHosts.has(url.hostname))) {
        throw new Error('Remote websites must use HTTPS.');
    }

    return `${url.origin}/*`;
}
