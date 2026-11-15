import { test as base, expect } from './tablet.fixture';
import type { CatalogImportBatch, ImportRow } from '../../resources/js/catalogImport';

type State = { batch: CatalogImportBatch | null; invalid: boolean; rows: number; dropFirst: boolean; used: boolean; previews: number; confirms: number; reverts: number; created: number; ids: string[] };
const test = base.extend<{ importState: State }>({
    importState: async ({ page, businessState }, use) => {
        const state: State = { batch: null, invalid: false, rows: 2, dropFirst: false, used: false, previews: 0, confirms: 0, reverts: 0, created: 0, ids: [] };
        const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        function rows(): ImportRow[] {
            return Array.from({ length: state.rows }, (_, i) => ({ row: i + 2, product_key: '', name: `Producto CSV ${i + 1}`, sku: `CSV-${i + 1}`, barcode: '', sale_price: '125.50', cost_price: null, product_type: 'physical', errors: state.invalid && i === 0 ? [{ field: 'category_id', message: 'La referencia está inactiva.' }] : [] }));
        }
        function present(page = 1) {
            const batch = structuredClone(state.batch!); const all = rows();
            batch.rows = all.slice((page - 1) * 25, page * 25); batch.error_count = state.invalid ? 1 : 0;
            batch.pagination = { current_page: page, last_page: Math.ceil(state.rows / 25), total: state.rows, per_page: 25 };
            batch.can_confirm = batch.status === 'preview' && !state.invalid && !batch.is_expired;
            return batch;
        }
        await page.route('**/api/v1/business/catalog/imports**', async route => {
            const request = route.request(); const url = new URL(request.url());
            const path = url.pathname.replace('/api/v1/business/catalog/imports', '');
            if (path === '/template') return route.fulfill({ contentType: 'text/csv; charset=UTF-8', body: 'name,sku,sale_price\nPlantilla,CSV,1.00\n' });
            if (path.endsWith('/errors')) return route.fulfill({ contentType: 'text/csv; charset=UTF-8', body: 'fila,sku,error\n2,CSV-1,Referencia inactiva\n' });
            if (path === '' && request.method() === 'GET') return route.fulfill({ json: { data: state.batch ? [{ id, filename: 'catalogo.csv', status: state.batch.status, row_count: state.rows, product_count: state.rows, error_count: state.invalid ? 1 : 0, expires_at: state.batch.expires_at, created_at: '2026-11-12T12:00:00Z' }] : [], meta: { current_page: 1, last_page: 1, total: state.batch ? 1 : 0 } } });
            if (path === '/preview') {
                state.previews++;
                state.batch = { id, filename: 'catalogo.csv', status: 'preview', row_count: state.rows, product_count: state.rows, error_count: state.invalid ? 1 : 0, expires_at: '2030-11-12T12:00:00Z', is_expired: false, can_confirm: !state.invalid, can_revert: false, committed_at: null, reverted_at: null, products: [], rows: rows(), pagination: { current_page: 1, last_page: 1, total: state.rows, per_page: 25 } };
                return route.fulfill({ status: 201, json: { data: present() } });
            }
            if (path.endsWith('/confirm')) {
                state.confirms++; state.ids.push(path.split('/')[1]);
                if (state.batch!.status !== 'committed') {
                    state.created = state.rows; state.batch!.status = 'committed'; state.batch!.committed_at = '2026-11-12T12:00:00Z'; state.batch!.can_revert = true;
                    state.batch!.products = rows().map((row, i) => ({ id: i + 1, name: row.name }));
                }
                if (state.dropFirst && state.confirms === 1) return route.abort('failed');
                return route.fulfill({ json: { data: present() } });
            }
            if (path.endsWith('/revalidate')) return route.fulfill({ json: { data: present() } });
            if (path.endsWith('/revert')) {
                state.reverts++;
                if (state.used) return route.fulfill({ status: 409, json: { message: 'El lote tiene productos usados en inventario, ventas o imágenes. No se revirtió ningún producto.' } });
                state.batch!.status = 'reverted'; state.batch!.can_revert = false; state.batch!.reverted_at = '2026-11-12T13:00:00Z';
                return route.fulfill({ json: { data: present() } });
            }
            if (request.method() === 'GET' && state.batch) return route.fulfill({ json: { data: present(Number(url.searchParams.get('page') || 1)) } });
            return route.fulfill({ status: 403, json: { message: 'Unplanned import request blocked by fixture.' } });
        });
        await use(state);
        expect(businessState.writes).toBe(0);
    },
});

async function openImporter(page: import('@playwright/test').Page) {
    await page.goto('/'); await page.getByRole('button', { name: 'Catálogo', exact: true }).tap();
    await page.getByRole('button', { name: 'Importar CSV', exact: true }).tap();
    await expect(page.getByRole('heading', { name: 'Importar catálogo desde CSV', exact: true })).toBeVisible();
}
async function preview(page: import('@playwright/test').Page) {
    await page.getByLabel('Archivo CSV', { exact: true }).setInputFiles({ name: 'catalogo.csv', mimeType: 'text/csv', buffer: Buffer.from('name,sku,sale_price\nUno,CSV-1,125.50\nDos,CSV-2,125.50\n') });
    await page.getByRole('button', { name: 'Revisar archivo', exact: true }).tap();
    await expect(page.getByRole('heading', { name: 'Revisión del lote', exact: true })).toBeVisible();
}

test('CSV preview stays a dry run across rotation and confirm remains touch-accessible', async ({ page, importState }) => {
    await openImporter(page); await preview(page);
    expect(importState.created).toBe(0); expect(importState.confirms).toBe(0);
    await page.setViewportSize({ width: 768, height: 1024 }); await page.setViewportSize({ width: 1024, height: 768 });
    await expect(page.locator('.import-row')).toHaveCount(2);
    const confirm = page.getByRole('button', { name: 'Importar 2 productos', exact: true });
    await expect(confirm).toBeInViewport({ ratio: 1 });
    expect(await confirm.evaluate(el => el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(48);
    await confirm.tap();
    await expect(page.getByRole('status').filter({ hasText: 'Importación completada:' })).toBeVisible();
    expect(importState.created).toBe(2);
});

test('row errors block import and both CSV downloads work', async ({ page, importState }) => {
    importState.invalid = true;
    await openImporter(page); await preview(page);
    await expect(page.getByRole('button', { name: 'Importar 2 productos', exact: true })).toBeDisabled();
    await expect(page.locator('.import-row-errors')).toContainText('La referencia está inactiva.');
    const errors = page.waitForEvent('download'); await page.getByRole('button', { name: 'Descargar errores CSV', exact: true }).tap();
    expect((await errors).suggestedFilename()).toMatch(/^errores-.*\.csv$/);
    const template = page.waitForEvent('download'); await page.getByRole('button', { name: 'Descargar plantilla', exact: true }).tap();
    expect((await template).suggestedFilename()).toBe('plantilla-catalogo-bshop.csv');
    expect(importState.confirms).toBe(0); expect(importState.created).toBe(0);
});

test('an interrupted response retries the same import ID without duplicate products', async ({ page, importState }) => {
    importState.dropFirst = true;
    await openImporter(page); await preview(page);
    await page.getByRole('button', { name: 'Importar 2 productos', exact: true }).tap();
    await expect(page.getByRole('alert').filter({ hasText: 'No hay conexión.' })).toBeVisible();
    expect(importState.created).toBe(2);
    await page.getByRole('button', { name: 'Importar 2 productos', exact: true }).tap();
    await expect(page.getByRole('status').filter({ hasText: 'Importación completada:' })).toBeVisible();
    expect(importState.confirms).toBe(2); expect(importState.created).toBe(2);
    expect(new Set(importState.ids).size).toBe(1);
});

test('history restores preview, physical rows paginate and references can be revalidated', async ({ page, importState }) => {
    importState.rows = 30; importState.invalid = true;
    await openImporter(page); await preview(page);
    await page.getByRole('button', { name: '← Volver al catálogo', exact: true }).tap();
    await page.getByRole('button', { name: 'Importar CSV', exact: true }).tap();
    await page.getByRole('button', { name: 'Abrir catalogo.csv', exact: true }).tap();
    await page.locator('.import-review').getByRole('button', { name: 'Siguiente', exact: true }).tap();
    await expect(page.locator('.import-row')).toHaveCount(5);
    await expect(page.locator('.import-row-number').first()).toHaveText('Fila 27');
    importState.invalid = false;
    await page.getByRole('button', { name: 'Revalidar lote', exact: true }).tap();
    await expect(page.getByRole('button', { name: 'Importar 30 productos', exact: true })).toBeEnabled();
    expect(importState.previews).toBe(1); expect(importState.created).toBe(0);
});

test('successful reversal keeps archived records and dialog actions above the keyboard', async ({ page, importState }) => {
    await openImporter(page); await preview(page);
    await page.getByRole('button', { name: 'Importar 2 productos', exact: true }).tap();
    await page.getByRole('button', { name: 'Revertir importación', exact: true }).tap();
    await page.setViewportSize({ width: 1024, height: 380 });
    const dialog = page.getByRole('dialog', { name: 'Revertir importación', exact: true });
    const submit = dialog.getByRole('button', { name: 'Archivar productos del lote', exact: true });
    await expect(submit).toBeInViewport({ ratio: 1 }); await submit.tap();
    await expect(page.getByRole('status').filter({ hasText: 'Importación revertida.' })).toBeVisible();
    expect(importState.batch?.status).toBe('reverted'); expect(importState.created).toBe(2); expect(importState.reverts).toBe(1);
});

test('used products block reversal with an accessible reason and no state change', async ({ page, importState }) => {
    importState.used = true;
    await openImporter(page); await preview(page);
    await page.getByRole('button', { name: 'Importar 2 productos', exact: true }).tap();
    await page.getByRole('button', { name: 'Revertir importación', exact: true }).tap();
    await page.setViewportSize({ width: 1024, height: 380 });
    const dialog = page.getByRole('dialog', { name: 'Revertir importación', exact: true });
    await dialog.getByRole('button', { name: 'Archivar productos del lote', exact: true }).tap();
    await expect(dialog.getByRole('alert')).toContainText('No se revirtió ningún producto.');
    await expect(dialog.getByRole('alert')).toBeInViewport({ ratio: 1 });
    expect(importState.batch?.status).toBe('committed'); expect(importState.created).toBe(2);
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).tap();
});
