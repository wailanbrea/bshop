import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/pwa-worker.js', import.meta.url), 'utf8');

function worker(fetch) {
    let handler;
    const self = { location: { origin: 'https://shop.example.test' }, addEventListener(type, listener) { if (type === 'fetch') handler = listener; } };
    vm.runInNewContext(script, { self, fetch, URL, Response });
    return request => {
        let response;
        handler({ request, respondWith(value) { response = value; } });
        return response;
    };
}

test('online application navigation uses the network response', async () => {
    const networkResponse = new Response('online', { status: 200 });
    const dispatch = worker(async () => networkResponse);
    assert.equal(await dispatch({ method: 'GET', mode: 'navigate', url: 'https://shop.example.test/app/cuentas-semanales' }), networkResponse);
});

test('offline app navigation shows a no-store explanation without personal data', async () => {
    const dispatch = worker(async () => { throw new Error('offline'); });
    const response = await dispatch({ method: 'GET', mode: 'navigate', url: 'https://shop.example.test/app/ventas' });
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('cache-control'), 'no-store');
    assert.match(await response.text(), /No se guardan ni envían operaciones sin conexión/);
});

test('worker does not intercept APIs, writes, public tokens or external sites', () => {
    let networkCalls = 0;
    const dispatch = worker(async () => { networkCalls++; });
    for (const request of [
        { method: 'POST', mode: 'cors', url: 'https://shop.example.test/api/v1/business/stores/1/sales/orders' },
        { method: 'GET', mode: 'cors', url: 'https://shop.example.test/api/v1/session' },
        { method: 'GET', mode: 'navigate', url: 'https://shop.example.test/s/private-token' },
        { method: 'GET', mode: 'navigate', url: 'https://other.example.test/app/ventas' },
    ]) assert.equal(dispatch(request), undefined);
    assert.equal(networkCalls, 0);
});

test('manifest starts in app scope and references an existing SVG icon', () => {
    const manifest = JSON.parse(readFileSync(new URL('../../public/manifest.webmanifest', import.meta.url), 'utf8'));
    assert.equal(manifest.start_url, '/app/');
    assert.equal(manifest.scope, '/');
    assert.equal(manifest.display, 'standalone');
    assert.equal(manifest.icons[0].src, '/pwa-icon.svg');
    assert.match(readFileSync(new URL('../../public/pwa-icon.svg', import.meta.url), 'utf8'), /<svg\b/);
});
