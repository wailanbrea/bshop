import { test as base, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/** Serve the actual compiled UI in-process when local HTTP is unavailable. APIs remain mocked. */
async function mockAppAssets(page: Page) {
    const publicRoot = path.resolve('public');
    const manifest = JSON.parse(fs.readFileSync(path.join(publicRoot, 'build/manifest.json'), 'utf8'));
    const entry = manifest['resources/js/app.ts'];
    const css = [...new Set<string>([manifest['resources/css/app.css'].file, ...(entry.css || [])])];
    const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="browser-test">${css.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body><div id="app"></div></body></html>`;
    await page.route('**/*', async route => {
        const request = route.request();
        if (request.url().startsWith('blob:') || request.url().startsWith('data:')) return route.continue();
        if (request.isNavigationRequest()) return route.fulfill({ contentType: 'text/html', body: html });
        const url = new URL(request.url());
        if (url.pathname.startsWith('/api/')) return route.fallback();
        const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
        if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({ status: 404 });
        const ext = path.extname(file);
        return route.fulfill({ body: fs.readFileSync(file), contentType: ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.png': 'image/png' } as Record<string,string>)[ext] || 'application/octet-stream' });
    });
}

export const permissions = ['business.manage', 'stores.view', 'stores.manage', 'warehouses.view', 'warehouses.manage', 'members.manage', 'catalog.view', 'catalog.manage', 'catalog.costs', 'inventory.view', 'inventory.manage'];
export const business = { id: 1, name: 'KeylaShop', timezone: 'America/Santo_Domingo', default_currency: 'DOP' };
export const stores = [
    { id: 1, name: 'Sucursal principal', code: 'CENTRAL', address: null, phone: null, is_active: true },
    { id: 2, name: 'Sucursal norte', code: 'NORTE', address: null, phone: null, is_active: true },
];
export const roles = [{ id: 1, code: 'owner', label: 'Propietario' }, { id: 2, code: 'manager', label: 'Administrador' }, { id: 3, code: 'seller', label: 'Vendedor' }];
export const members = [{ id: 1, user_id: 1, name: 'Propietaria QA', email: 'owner@example.test', status: 'active', user_active: true, role_ids: [1], role_labels: ['Propietario'], store_ids: [1, 2], can_manage: true }];

type State = { stores: typeof stores; writes: number; failCreate: boolean; delay: number };
type Fixtures = { businessState: State };

/** All API traffic is intercepted: viewport regression tests never mutate a client database. */
async function mockBusiness(page: Page, state: State) {
    await page.route('**/api/v1/**', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const pathname = url.pathname.replace('/api/v1', '');
        const method = request.method();
        const json = (data: unknown, status = 200) => route.fulfill({ status, json: { data } });
        if (pathname === '/session') return json({ user: { id: 1, name: 'Propietaria QA', email: 'owner@example.test' }, business, roles: ['Propietario'], permissions });
        if (pathname === '/business/reservations/settings' && method === 'GET') return json({hours:null});
        if (pathname === '/business/weekly/settings' && method === 'GET') return json({close_weekday:4});
        if (pathname === '/business/workspace') return json({ business, stores: state.stores, permissions });
        if (/^\/business\/stores\/\d+\/warehouses$/.test(pathname) && method === 'GET') return json([{ id: 1, store_id: Number(pathname.split('/')[3]), name: 'Almacén principal', is_default: true, is_active: true }]);
        if (/^\/business\/stores\/\d+\/sales\/sellers$/.test(pathname) && method === 'GET') return json([]);
        if (pathname === '/business/team') return json({ members, roles, stores: state.stores, invitations: [] });
        if (pathname === '/business/catalog/options') return json({ categories: [], brands: [], attributes: [] });
        if (pathname === '/business/products' && method === 'GET') return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });
        if (/\/warehouses\/\d+\/(stock|kardex)$/.test(pathname) && method === 'GET') return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });
        if (pathname === '/business/stores' && method === 'POST') {
            state.writes++;
            if (state.delay) await new Promise(resolve => setTimeout(resolve, state.delay));
            if (state.failCreate) return route.fulfill({ status: 422, json: { message: 'Datos inválidos.', errors: { code: ['El código ya está en uso.'] } } });
            const values = request.postDataJSON();
            const created = { ...values, id: 3 };
            state.stores.push(created);
            return json(created, 201);
        }
        if (pathname === '/login') return route.fulfill({ status: 422, json: { errors: { email: ['El correo o la contraseña no son correctos.'] } } });
        if (pathname.startsWith('/invitations/')) return json({ company_name: business.name, recipient: 'q***@example.test', expires_at: '2030-12-01T12:00:00Z' });
        // Unplanned writes must fail instead of reaching the actual application.
        return route.fulfill({ status: 403, json: { message: 'Operation blocked by tablet test fixture.' } });
    });
}

export const test = base.extend<Fixtures>({
    businessState: async ({ page }, use) => {
        if (process.env.BSHOP_BROWSER_OFFLINE === '1') await mockAppAssets(page);
        const state: State = { stores: structuredClone(stores), writes: 0, failCreate: false, delay: 0 };
        await mockBusiness(page, state);
        await use(state);
    },
});
export { expect };
