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
        this.recoveryEpoch = null;
        this.recoveryDiscovered = false;
    }

    async request(path, { method = 'GET', body = null, idempotencyKey = null } = {}) {
        if (this.token && !['GET', 'HEAD'].includes(method) && !this.recoveryDiscovered) {
            await this.request('/api/v1/browser-operations/session');
        }
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-GEOFlow-Browser-Protocol': PROTOCOL_VERSION,
            'X-GEOFlow-Client-Version': this.version,
        };
        if (this.token && this.recoveryEpoch && !['GET', 'HEAD'].includes(method)) headers['X-GEOFlow-Recovery-Epoch'] = this.recoveryEpoch;
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

        if (path === '/api/v1/browser-operations/session') {
            const recovery = envelope.data?.recovery;
            if (recovery != null && recovery.supported !== false && (recovery.supported !== true || !/^[a-f0-9]{32}$/.test(recovery.epoch ?? ''))) {
                throw new GeoFlowApiError('invalid_recovery_contract', 'GEOFlow returned an invalid recovery epoch.', 0);
            }
            this.recoveryEpoch = recovery?.supported === true ? recovery.epoch : null;
            this.recoveryDiscovered = true;
        }
        return envelope.data;
    }
}
