import { test as base, expect, business, stores } from './tablet.fixture';
import type { CatalogOptions, CatalogProduct, CatalogVariant } from '../../resources/js/catalog';
import { permissions } from './tablet.fixture';

type CatalogState = { products: CatalogProduct[]; variants: Record<number, CatalogVariant[]>; options: CatalogOptions; lastBody: any; slowRefresh: boolean; pending: number; detailRequests: number };
const test = base.extend<{ catalogState: CatalogState }>({
    catalogState: async ({ page, businessState }, use) => {
        const options: CatalogOptions = { categories: [{ id: 1, name: 'Ropa', parent_id: null, is_active: true }], brands: [{ id: 1, name: 'Marca QA', is_active: true }], attributes: [
            { id: 1, name: 'Talla', is_active: true, values: [{ id: 1, attribute_id: 1, value: 'M', is_active: true }, { id: 2, attribute_id: 1, value: 'L', is_active: true }] },
            { id: 2, name: 'Color', is_active: true, values: [{ id: 3, attribute_id: 2, value: 'Negro', is_active: true }] },
        ] };
        const product: CatalogProduct = { id: 1, name: 'Camiseta demo', description: 'Producto de pruebas', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active', version: 0, category: { id: 1, name: 'Ropa' }, brand: null, primary_sku: 'CAM-M', variants_count: 1, active_variants_count: 1, min_sale_price: '1250.0000', max_sale_price: '1250.0000' };
        const state: CatalogState = { options, products: [product], variants: { 1: [{ id: 1, product_id: 1, sku: 'CAM-M', barcode: null, sale_price: '1250.0000', cost_price: '700.0000', is_active: true, version: 0, attribute_values: [{ id: 1, attribute_id: 1, attribute_name: 'Talla', value: 'M' }] }] }, lastBody: null, slowRefresh: false, pending: 0, detailRequests: 0 };
        const normalize = (value: string) => { const [integer, fraction = ''] = value.split('.'); return `${integer}.${fraction.padEnd(4, '0')}`; };
        await page.route('**/api/v1/business/products**', async route => {
            const url = new URL(route.request().url()); const method = route.request().method();
            const parts = url.pathname.split('/'); const id = Number(parts[5]);
            if (method === 'GET' && id) state.detailRequests++;
            if (method === 'GET' && state.slowRefresh) { state.pending++; await new Promise(resolve => setTimeout(resolve, 600)); state.pending--; }
            if (method === 'GET' && !id) {
                const q = (url.searchParams.get('q') || '').toLowerCase();
                const status = url.searchParams.get('status') || 'active';
                const list = state.products.filter(p => (status === 'all' || p.status === status) && (!q || p.name.toLowerCase().includes(q) || p.primary_sku?.toLowerCase().includes(q)));
                return route.fulfill({ json: { data: list, meta: { current_page: 1, last_page: 1, total: list.length } } });
            }
            if (method === 'GET') return route.fulfill({ json: { data: { product: state.products.find(p => p.id === id), variants: { data: state.variants[id], current_page: 1, last_page: 1, total: state.variants[id]?.length || 0 } } } });
            state.lastBody = route.request().postDataJSON();
            if (method === 'PATCH' && url.pathname.includes('/variants/')) return route.fulfill({ status: 409, json: { message: 'La variante cambió.' } });
            if (method === 'PATCH' && id) {
                const product = state.products.find(p => p.id === id)!;
                Object.assign(product, state.lastBody, { version: product.version + 1 });
                return route.fulfill({ json: { data: product } });
            }
            if (method === 'POST' && !id) {
                const body = state.lastBody; const createdId = state.products.length + 1;
                const price = normalize(body.variants[0].sale_price);
                const created: CatalogProduct = { ...product, id: createdId, name: body.name, description: body.description, product_type: body.product_type, track_stock: body.track_stock, status: body.status, primary_sku: body.variants[0].sku, min_sale_price: price, max_sale_price: price, variants_count: body.variants.length, active_variants_count: body.variants.length };
                state.products.push(created);
                state.variants[createdId] = body.variants.map((variant: any, index: number) => ({ ...variant, id: 100 + index, product_id: createdId, sale_price: normalize(variant.sale_price), cost_price: variant.cost_price ? normalize(variant.cost_price) : null, version: 0, attribute_values: options.attributes.flatMap(a => a.values.filter(v => variant.attribute_value_ids.includes(v.id)).map(v => ({ ...v, attribute_name: a.name }))) }));
                return route.fulfill({ status: 201, json: { data: created } });
            }
            return route.fulfill({ status: 403, json: { message: 'Unexpected catalog write in UI fixture.' } });
        });
        await page.route('**/api/v1/business/catalog/**', async route => {
            const parts = new URL(route.request().url()).pathname.split('/');
            if (route.request().method() === 'GET') return route.fulfill({ json: { data: state.options } });
            const body = route.request().postDataJSON(); state.lastBody = body;
            if (parts[5] === 'attributes') {
                const created = { id: state.options.attributes.length + 1, ...body, values: [] }; state.options.attributes.push(created);
                return route.fulfill({ status: 201, json: { data: created } });
            }
            if (parts[5] === 'values') {
                const value = { id: 100, ...body }; state.options.attributes.find(a => a.id === body.attribute_id)!.values.push(value);
                return route.fulfill({ status: 201, json: { data: value } });
            }
            return route.fulfill({ status: 403, json: { message: 'Unexpected lookup write in UI fixture.' } });
        });
        await use(state);
        expect(businessState.writes).toBe(0);
    },
});

async function catalog(page: import('@playwright/test').Page) {
    await page.goto('/');
    await page.getByRole('button', { name: 'Catálogo', exact: true }).tap();
    await expect(page.getByRole('button', { name: 'Ver Camiseta demo', exact: true })).toBeVisible();
}

test('USD prices use US$ without changing exact stored decimal amounts', async ({ page, catalogState }) => {
    const usd = { ...business, default_currency: 'USD' };
    await page.route('**/api/v1/session', route => route.fulfill({ json: { data: { user: { id: 1, name: 'Propietaria QA', email: 'qa@example.test' }, business: usd, roles: ['Propietario'], permissions } } }));
    await page.route('**/api/v1/business/workspace', route => route.fulfill({ json: { data: { business: usd, stores, permissions } } }));
    await catalog(page);
    await expect(page.locator('.product-price')).toContainText('US$ 1,250.00');
    expect(catalogState.products[0].min_sale_price).toBe('1250.0000');
});

test('catalog cards search by SKU and show exact prices in a tablet detail', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByLabel('Buscar producto', { exact: true }).fill('NOT-FOUND');
    await expect(page.getByRole('heading', { name: 'No encontramos coincidencias.', exact: true })).toBeVisible();
    await page.getByLabel('Buscar producto', { exact: true }).fill('CAM-M');
    await page.getByRole('button', { name: 'Ver Camiseta demo', exact: true }).tap();
    await expect(page.getByRole('heading', { name: 'Camiseta demo', exact: true })).toBeVisible();
    await expect(page.locator('.catalog-variant-record')).toContainText('DOP 1,250.00');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
});

test('new product submits decimal strings and keeps successful confirmation', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByRole('button', { name: '+ Nuevo producto', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Nuevo producto', exact: true });
    await dialog.getByLabel('Nombre del producto', { exact: true }).fill('Perfume QA');
    await dialog.getByLabel('SKU', { exact: true }).fill('PERF-QA');
    await dialog.getByLabel('Precio de venta', { exact: true }).fill('1999,50');
    await expect(dialog.getByLabel('Precio de venta', { exact: true })).toHaveValue('1999.50');
    await dialog.getByRole('button', { name: 'Guardar producto', exact: true }).tap();
    await expect(page.getByRole('heading', { name: 'Perfume QA', exact: true })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Producto guardado correctamente.' })).toBeVisible();
    expect(catalogState.lastBody.variants[0].sale_price).toBe('1999.50');
    expect(typeof catalogState.lastBody.variants[0].sale_price).toBe('string');
});

test('multiple variant drafts survive rotation with reachable save controls', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByRole('button', { name: '+ Nuevo producto', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Nuevo producto', exact: true });
    await dialog.getByLabel('Nombre del producto', { exact: true }).fill('Camisa con variantes');
    const first = dialog.locator('.variant-draft').nth(0);
    await first.getByLabel('SKU', { exact: true }).fill('SIZE-M'); await first.getByLabel('Precio de venta', { exact: true }).fill('500');
    await first.getByRole('combobox', { name: 'Talla', exact: true }).selectOption('1'); await first.getByRole('combobox', { name: 'Color', exact: true }).selectOption('3');
    await dialog.getByRole('button', { name: '+ Añadir otra variante', exact: true }).tap();
    const second = dialog.locator('.variant-draft').nth(1);
    await second.getByLabel('SKU', { exact: true }).fill('SIZE-L'); await second.getByLabel('Precio de venta', { exact: true }).fill('500');
    await second.getByRole('combobox', { name: 'Talla', exact: true }).selectOption('2'); await second.getByRole('combobox', { name: 'Color', exact: true }).selectOption('3');
    await page.setViewportSize({ width: 768, height: 1024 }); await page.setViewportSize({ width: 1024, height: 380 });
    await expect(first.getByLabel('SKU', { exact: true })).toHaveValue('SIZE-M');
    await expect(second.getByLabel('SKU', { exact: true })).toHaveValue('SIZE-L');
    await expect(dialog.getByRole('button', { name: 'Guardar producto', exact: true })).toBeInViewport({ ratio: 1 });
    await dialog.getByRole('button', { name: 'Guardar producto', exact: true }).tap();
    await expect(page.locator('.catalog-variant-record')).toHaveCount(2);
    expect(catalogState.lastBody.variants[0].attribute_value_ids).toEqual([1, 3]);
    expect(catalogState.lastBody.variants[1].attribute_value_ids).toEqual([2, 3]);
});

test('stale variant response preserves typed changes instead of silently overwriting', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByRole('button', { name: 'Ver Camiseta demo', exact: true }).tap();
    await page.getByRole('button', { name: 'Editar CAM-M', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Editar variante', exact: true });
    await dialog.getByLabel('Precio de venta', { exact: true }).fill('1300.0001');
    await dialog.getByRole('button', { name: 'Guardar variante', exact: true }).tap();
    await expect(dialog.getByRole('alert')).toContainText('Los datos cambiaron.');
    await expect(dialog.getByLabel('Precio de venta', { exact: true })).toHaveValue('1300.0001');
    expect(catalogState.lastBody.version).toBe(0);
    expect(catalogState.lastBody.attribute_value_ids).toBeUndefined();
});

test('catalog organization creates an attribute and reusable value from touch dialogs', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByRole('button', { name: 'Organizar catálogo', exact: true }).tap();
    await page.getByRole('button', { name: 'Atributos', exact: true }).tap();
    await page.getByRole('button', { name: '+ Nuevo atributo', exact: true }).tap();
    let dialog = page.getByRole('dialog', { name: 'Crear atributo', exact: true });
    await dialog.getByLabel('Nombre', { exact: true }).fill('Material');
    await dialog.getByRole('button', { name: 'Guardar opción', exact: true }).tap();
    await page.getByRole('button', { name: 'Valores de Material', exact: true }).tap();
    await page.getByRole('button', { name: '+ Nuevo valor', exact: true }).tap();
    dialog = page.getByRole('dialog', { name: 'Crear valor', exact: true });
    await dialog.getByLabel('Valor del atributo', { exact: true }).fill('Algodón');
    await dialog.getByRole('button', { name: 'Guardar opción', exact: true }).tap();
    await expect(page.locator('.record-main strong').filter({ hasText: 'Algodón' })).toBeVisible();
    expect(catalogState.lastBody.attribute_id).toBe(3);
});

test('seller catalog hides management controls and cost presentation', async ({ page, catalogState }) => {
    const permissions = ['stores.view', 'warehouses.view', 'catalog.view'];
    await page.route('**/api/v1/session', route => route.fulfill({ json: { data: { user: { id: 2, name: 'Vendedor QA', email: 'seller@example.test' }, business, roles: ['Vendedor'], permissions } } }));
    await page.route('**/api/v1/business/workspace', route => route.fulfill({ json: { data: { business, stores, permissions } } }));
    await catalog(page);
    await expect(page.getByRole('button', { name: '+ Nuevo producto', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Organizar catálogo', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Ver Camiseta demo', exact: true }).tap();
    await expect(page.locator('.variant-price')).not.toContainText('Costo:');
    await expect(page.getByRole('button', { name: 'Editar CAM-M', exact: true })).toHaveCount(0);
});

test('returning to the list during a slow save refresh does not reopen the detail', async ({ page, catalogState }) => {
    await catalog(page);
    await page.getByRole('button', { name: 'Ver Camiseta demo', exact: true }).tap();
    await page.getByRole('button', { name: 'Editar producto', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Editar producto', exact: true });
    await dialog.getByLabel('Nombre del producto', { exact: true }).fill('Camiseta editada');
    catalogState.slowRefresh = true;
    const previousRequests = catalogState.detailRequests;
    await dialog.getByRole('button', { name: 'Guardar producto', exact: true }).tap();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('button', { name: '← Volver a productos', exact: true }).tap();
    await expect.poll(() => catalogState.detailRequests).toBeGreaterThan(previousRequests);
    await expect.poll(() => catalogState.pending).toBe(0);
    await expect(page.getByRole('button', { name: 'Ver Camiseta editada', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '← Volver a productos', exact: true })).toHaveCount(0);
});
