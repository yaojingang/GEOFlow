export const SIDEBAR_DEFAULT_WIDTH = 256;
export const SIDEBAR_MIN_WIDTH = 224;
export const SIDEBAR_MAX_WIDTH = 384;

export function normalizeSidebarWidth(value, fallback = SIDEBAR_DEFAULT_WIDTH) {
    const parsed = typeof value === 'number' ? value : Number.parseFloat(value);
    const safeFallback = Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, fallback));

    if (!Number.isFinite(parsed)) return safeFallback;

    return Math.round(Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, parsed)));
}
