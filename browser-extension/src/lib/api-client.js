export const PROTOCOL_VERSION = '1';

export class GeoFlowApiError extends Error {
    constructor(code, message, status, details = {}) {
        super(message);
        this.name = 'GeoFlowApiError';
        this.code = code;
        this.status = status;
        this.details = details;
    }
}

export class GeoFlowApiClient {
    constructor({ baseUrl, token = null, version = '0.1.0' }) {
        this.baseUrl = String(baseUrl).replace(/\/$/, '');
        this.token = token;
        this.version = version;
    }

    async request(path, { method = 'GET', body = null, idempotencyKey = null } = {}) {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-GEOFlow-Browser-Protocol': PROTOCOL_VERSION,
            'X-GEOFlow-Client-Version': this.version,
        };
        if (this.token) headers.Authorization = `Bearer ${this.token}`;
        if (idempotencyKey) headers['X-Idempotency-Key'] = idempotencyKey;

        let response;
        try {
            response = await fetch(`${this.baseUrl}${path}`, {
                method,
                headers,
                body: body === null ? null : JSON.stringify(body),
                credentials: 'omit',
                cache: 'no-store',
            });
        } catch {
            throw new GeoFlowApiError('network_error', 'Could not reach GEOFlow.', 0);
        }

        const envelope = await response.json().catch(() => null);
        if (! response.ok || ! envelope?.success) {
            throw new GeoFlowApiError(
                envelope?.error?.code ?? 'http_error',
                envelope?.error?.message ?? `GEOFlow returned HTTP ${response.status}.`,
                response.status,
                envelope?.error?.details ?? {},
            );
        }

        return envelope.data;
    }
}
