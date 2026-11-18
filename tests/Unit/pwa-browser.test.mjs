import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import http from 'node:http';
import { once } from 'node:events';
import test from 'node:test';
import { chromium } from '@playwright/test';

test('real browser shows only the offline page and does not replay writes', async () => {
    let writes = 0;
    const worker = readFileSync(new URL('../../public/pwa-worker.js', import.meta.url));
    const server = http.createServer((request, response) => {
        if (request.url === '/pwa-worker.js') {
            response.writeHead(200, { 'Content-Type': 'application/javascript' }).end(worker);
        } else if (request.method === 'POST') {
            writes++;
            response.writeHead(200).end('ok');
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><title>Online QA</title><p>Online QA</p>');
        }
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    let browser;
    try {
        browser = await chromium.launch();
        const context = await browser.newContext();
        const page = await context.newPage();
        const origin = `http://127.0.0.1:${server.address().port}`;
        await page.goto(`${origin}/app/`);
        await page.evaluate(async () => { await navigator.serviceWorker.register('/pwa-worker.js', { scope: '/' }); await navigator.serviceWorker.ready; });
        await page.reload();
        assert.equal(await page.evaluate(() => !!navigator.serviceWorker.controller), true);
        assert.deepEqual(await page.evaluate(() => caches.keys()), []);
        await context.setOffline(true);
        const response = await page.goto(`${origin}/app/ventas`);
        assert.equal(response.status(), 503);
        assert.match(await page.locator('body').innerText(), /No se guardan ni envían operaciones sin conexión/);
        assert.equal(await page.evaluate(async () => { try { await fetch('/api/v1/orders', { method: 'POST' }); return 'sent'; } catch { return 'offline'; } }), 'offline');
        await context.setOffline(false);
        await page.goto(`${origin}/app/`);
        assert.equal(writes, 0);
        assert.deepEqual(await page.evaluate(() => caches.keys()), []);
        await context.close();
    } finally {
        await browser?.close();
        server.close();
        await once(server, 'close');
    }
});
