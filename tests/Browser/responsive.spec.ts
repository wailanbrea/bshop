import { test, expect } from './tablet.fixture';

test('reference theme and navigation work with mouse or touch on every target viewport', async ({ page, businessState }) => {
    await page.goto('/');
    for (const name of ['Catálogo', 'Inventario', 'Sucursales', 'Almacenes', 'Configuración']) {
        if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: 'Abrir menú', exact: true }).click();
        await page.getByRole('button', { name, exact: true }).click();
        await expect(page.getByRole('combobox', { name: 'SUCURSAL ACTIVA', exact: true })).toBeInViewport();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
    }
    const color = await page.locator('.sidebar').evaluate(el => getComputedStyle(el).backgroundImage);
    expect(color).toContain('16, 17, 36');
    if ((page.viewportSize()?.width || 0) >= 1200) await expect(page.getByText('KeylaShop', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeVisible();
    expect(businessState.writes).toBe(0);
});
