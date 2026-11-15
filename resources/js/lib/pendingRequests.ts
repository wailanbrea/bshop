/** Per-tab, per-user recovery journal. It never submits automatically and contains no credentials. */
export function readPending(key: string): Record<string, unknown> | null {
    try { const value = JSON.parse(sessionStorage.getItem(key) || 'null'); return value && typeof value === 'object' && !Array.isArray(value) ? value : null; }
    catch { return null; }
}
export function savePending(key: string, data: Record<string, unknown>): void { sessionStorage.setItem(key, JSON.stringify(data)); }
export function clearPending(key: string): void { sessionStorage.removeItem(key); }
