import { test as base, expect } from './tablet.fixture';
import type { StockRow } from '../../resources/js/inventory';

const test = base.extend<{ inventory: { row: StockRow; requests: any[]; uncertain: boolean; conflict: boolean } }>({
    inventory: async ({ page, businessState }, use) => {
        const state = { row: { variant_id: 1, warehouse_id: 1, sku: 'CAM-M', barcode: null, product_name: 'Camiseta básica', product_id: 1, unit_code: 'unit', active: true, attributes: [], on_hand: '10.0000', reserved: '2.0000', available: '8.0000', version: 1 }, requests: [] as any[], uncertain: false, conflict: false };
        await page.route('**/api/v1/business/stores/*/warehouses/*/stock*', route => route.fulfill({ json: { data: [state.row], meta: { current_page: 1, last_page: 1, total: 1 } } }));
        await page.route('**/api/v1/business/stores/*/warehouses/*/kardex*', route => route.fulfill({ json: { data: [{ id: 1, variant_id: 1, sku: 'CAM-M', product_name: 'Camiseta básica', movement_type: 'receipt', on_hand_delta: '10.0000', reserved_delta: '0.0000', on_hand_after: '10.0000', reserved_after: '0.0000', note: 'Compra inicial documentada', occurred_at: '2026-11-13T12:00:00Z', actor_name: 'Propietaria QA' }], meta: { current_page: 1, last_page: 1, total: 1 } } }));
        await page.route('**/api/v1/business/stores/*/inventory/destinations', route => route.fulfill({ json: { data: [{ id: 1, name: 'Principal', store_name: 'Sucursal principal' }, { id: 2, name: 'Norte', store_name: 'Sucursal norte' }] } }));
        await page.route('**/api/v1/business/stores/*/inventory/movements', async route => {
            state.requests.push(route.request().postDataJSON()); await new Promise(r => setTimeout(r, 300));
            if (state.uncertain) return route.abort('failed');
            if (state.conflict) return route.fulfill({ status: 409, json: { message: 'Las existencias cambiaron.' } });
            state.row.on_hand = '12.0000'; state.row.available = '10.0000'; state.row.version++;
            return route.fulfill({ status: 201, json: { data: { replay: false } } });
        });
        await use(state); expect(businessState.writes).toBe(0);
    },
});
async function inventory(page: import('@playwright/test').Page) {
    await page.goto('/'); await page.getByRole('button', { name: 'Inventario', exact: true }).tap();
    await expect(page.getByText('Camiseta básica', { exact: true })).toBeVisible();
}
test('inventory separates physical, reserved and available and opens a real ledger view', async ({ page, inventory: state }) => {
    await inventory(page); const record = page.locator('.inventory-record');
    await expect(record).toContainText('10'); await expect(record).toContainText('2'); await expect(record).toContainText('8');
    await page.getByRole('button', { name: 'Movimientos de CAM-M', exact: true }).tap();
    await expect(page.getByRole('region', { name: 'Kardex de inventario', exact: true })).toContainText('Compra inicial documentada');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
});
test('movement form survives rotation and keeps actions visible above a small keyboard viewport', async ({ page, inventory: state }) => {
    await inventory(page); await page.getByRole('button', { name: 'Ajustar CAM-M', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Registrar movimiento de inventario', exact: true });
    await dialog.getByLabel('Cantidad', { exact: true }).fill('2'); await dialog.getByLabel('Motivo', { exact: true }).fill('Reposición comprobada');
    await page.setViewportSize({ width: 1024, height: 380 });
    await expect(dialog.getByLabel('Cantidad', { exact: true })).toHaveValue('2');
    await expect(dialog.getByRole('button', { name: 'Registrar movimiento', exact: true })).toBeInViewport({ ratio: 1 });
    await dialog.getByRole('button', { name: 'Registrar movimiento', exact: true }).tap();
    await expect(dialog).not.toBeVisible(); await expect(page.getByRole('status')).toContainText('Movimiento registrado.');
    expect(state.requests).toHaveLength(1); expect(state.requests[0].quantity).toBe('2');
});
test('uncertain network response resends the identical UUID and immutable payload', async ({ page, inventory: state }) => {
    await inventory(page); await page.getByRole('button', { name: 'Ajustar CAM-M', exact: true }).tap(); const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Cantidad', { exact: true }).fill('2'); await dialog.getByLabel('Motivo', { exact: true }).fill('Entrada comprobada');
    state.uncertain = true; await dialog.getByRole('button', { name: 'Registrar movimiento', exact: true }).tap();
    await expect(dialog.getByRole('status')).toContainText('no se pudo confirmar'); await expect(dialog.getByLabel('Cantidad', { exact: true })).toBeDisabled();
    state.uncertain = false; await dialog.getByRole('button', { name: 'Reintentar operación', exact: true }).tap();
    await expect(dialog).not.toBeVisible(); expect(state.requests).toHaveLength(2); expect(state.requests[1]).toEqual(state.requests[0]);
});
test('conflicting balance preserves the users count and reason', async ({ page, inventory: state }) => {
    await inventory(page); await page.getByRole('button', { name: 'Ajustar CAM-M', exact: true }).tap(); const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Tipo de movimiento', { exact: true }).selectOption('count');
    await dialog.getByLabel('Cantidad física contada', { exact: true }).fill('8'); await dialog.getByLabel('Motivo', { exact: true }).fill('Conteo de cierre');
    state.conflict = true; await dialog.getByRole('button', { name: 'Registrar movimiento', exact: true }).tap();
    await expect(dialog.getByRole('alert')).toContainText('Los datos cambiaron.');
    await expect(dialog.getByLabel('Cantidad física contada', { exact: true })).toHaveValue('8'); await expect(dialog.getByLabel('Motivo', { exact: true })).toHaveValue('Conteo de cierre');
});
