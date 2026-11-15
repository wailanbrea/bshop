import { test as base, expect } from './tablet.fixture';
import type { ProductImage } from '../../resources/js/catalog';

const pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7ZsAAAAASUVORK5CYII=';
const photo = (id: number): ProductImage => ({ id, variant_id: null, alt_text: `Foto ${id}`, is_primary: id === 1, sort_order: id - 1, renditions: { thumb: { url: pixel, width: 1, height: 1 }, card: { url: pixel, width: 1, height: 1 }, detail: { url: pixel, width: 1, height: 1 } } });
const test = base.extend<{ media: { images: ProductImage[]; version: number; uploads: number; keys: string[]; fail: boolean } }>({
    media: async ({ page, businessState }, use) => {
        const state = { images: [photo(1), photo(2)], version: 2, uploads: 0, keys: [] as string[], fail: false };
        const product = () => ({ id: 1, name: 'Producto fotos', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active', version: state.version, category: null, brand: null, primary_sku: 'PHOTO', variants_count: 1, active_variants_count: 1, min_sale_price: '25.0000', max_sale_price: '25.0000', images: state.images });
        const gallery = () => ({ images: state.images, version: state.version, pending_uploads: 0, quota: { max_bytes: 2147483648, ready_bytes: 500, pending_bytes: 0, retained_bytes: 0 } });
        await page.route('**/api/v1/business/products**', async route => {
            const path = new URL(route.request().url()).pathname; const method = route.request().method();
            if (path.includes('/uploads/')) return route.fulfill({ json: { data: { status: 'failed', retryable: true } } });
            if (path.endsWith('/images') && method === 'GET') return route.fulfill({ json: { data: gallery() } });
            if (path.endsWith('/images') && method === 'PATCH') {
                const body = route.request().postDataJSON();
                if (body.version !== state.version) return route.fulfill({ status: 409, json: { message: 'La galería cambió.' } });
                state.images = body.images.map((image: any, index: number) => ({ ...state.images.find(p => p.id === image.id)!, ...image, is_primary: image.id === body.primary_id, sort_order: index })); state.version++;
                return route.fulfill({ json: { data: gallery() } });
            }
            if (path.endsWith('/images') && method === 'POST') {
                state.uploads++; const multipart = route.request().postData()!;
                state.keys.push(multipart.match(/name="request_key"\r\n\r\n([^\r]+)/)![1]);
                await new Promise(resolve => setTimeout(resolve, 400));
                if (state.fail) return route.fulfill({ status: 503, json: { message: 'Proveedor no disponible. Reintenta la misma carga.' } });
                state.images.push(photo(state.images.length + 1)); state.version++;
                return route.fulfill({ status: 201, json: { data: { status: 'verified' } } });
            }
            if (method === 'DELETE') { state.images = state.images.filter(p => p.id !== Number(path.split('/').pop())); state.images[0].is_primary = true; state.version++; return route.fulfill({ json: { data: gallery() } }); }
            if (path.endsWith('/products')) return route.fulfill({ json: { data: [product()], meta: { current_page: 1, last_page: 1, total: 1 } } });
            return route.fulfill({ json: { data: { product: product(), variants: { data: [{ id: 1, sku: 'PHOTO', product_id: 1, sale_price: '25.0000', is_active: true, attribute_values: [] }], current_page: 1, last_page: 1, total: 1 } } } });
        });
        await use(state); expect(businessState.writes).toBe(0);
    },
});
async function open(page: import('@playwright/test').Page) {
    await page.goto('/'); await page.getByRole('button', { name: 'Catálogo', exact: true }).tap();
    await page.getByRole('button', { name: 'Ver Producto fotos', exact: true }).tap();
    await page.getByRole('button', { name: 'Administrar fotos', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Fotos de Producto fotos', exact: true });
    await expect(dialog.getByLabel('Descripción foto 1', { exact: true })).toBeVisible(); return dialog;
}
test('gallery edits survive rotation and keyboard-size viewport with a reachable footer', async ({ page, media }) => {
    const dialog = await open(page);
    await dialog.getByLabel('Descripción foto 1', { exact: true }).fill('Borrador táctil');
    await page.setViewportSize({ width: 1024, height: 520 });
    await expect(dialog.getByLabel('Descripción foto 1', { exact: true })).toHaveValue('Borrador táctil');
    await dialog.getByRole('button', { name: 'Guardar galería', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('Galería guardada.');
    expect(media.images[0].alt_text).toBe('Borrador táctil');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
    await dialog.getByRole('button', { name: 'Cerrar', exact: true }).tap();
    await expect(page.getByRole('button', { name: 'Administrar fotos', exact: true })).toBeFocused();
});
test('primary image and ordering can be changed with touch buttons', async ({ page, media }) => {
    const dialog = await open(page);
    await dialog.getByLabel('Principal 2', { exact: true }).check();
    await dialog.getByRole('button', { name: 'Subir foto 2', exact: true }).tap();
    await dialog.getByRole('button', { name: 'Guardar galería', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('Galería guardada.');
    expect(media.images[0].id).toBe(2); expect(media.images[0].is_primary).toBeTruthy();
});
test('failed upload keeps its request key and selected file for a safe retry', async ({ page, media }) => {
    const dialog = await open(page); media.fail = true;
    await dialog.getByLabel('Seleccionar archivo', { exact: true }).setInputFiles({ name: 'camera.png', mimeType: 'image/png', buffer: Buffer.from(pixel.split(',')[1], 'base64') });
    await dialog.getByLabel('Descripción de la nueva foto', { exact: true }).fill('Desde cámara');
    await dialog.getByRole('button', { name: 'Publicar foto', exact: true }).tap();
    await expect(dialog.getByRole('alert')).toContainText('Proveedor no disponible.');
    await expect(dialog.getByRole('img', { name: 'Vista previa del archivo seleccionado', exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Consultar carga', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('puedes reintentar');
    media.fail = false; await dialog.getByRole('button', { name: 'Publicar foto', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('Foto publicada correctamente.');
    expect(media.uploads).toBe(2); expect(media.keys[0]).toBe(media.keys[1]);
});
test('photo removal requires explicit confirmation and keeps the gallery accessible', async ({ page, media }) => {
    const dialog = await open(page);
    await dialog.getByRole('button', { name: 'Retirar foto 1', exact: true }).tap(); expect(media.images).toHaveLength(2);
    await dialog.getByRole('button', { name: 'Conservar foto', exact: true }).tap(); expect(media.images).toHaveLength(2);
    await dialog.getByRole('button', { name: 'Retirar foto 1', exact: true }).tap();
    await dialog.getByRole('button', { name: 'Confirmar retiro', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('Foto retirada.'); expect(media.images).toHaveLength(1);
});
test('stale gallery conflict preserves draft until the user explicitly reloads', async ({ page, media }) => {
    const dialog = await open(page); media.version++;
    await dialog.getByLabel('Descripción foto 1', { exact: true }).fill('Conservar borrador');
    await dialog.getByRole('button', { name: 'Guardar galería', exact: true }).tap();
    await expect(dialog.getByRole('alert')).toContainText('Los datos cambiaron.');
    await expect(dialog.getByLabel('Descripción foto 1', { exact: true })).toHaveValue('Conservar borrador');
    await dialog.getByRole('button', { name: 'Recargar galería', exact: true }).tap();
    await expect(dialog.getByLabel('Descripción foto 1', { exact: true })).toHaveValue('Foto 1');
});
