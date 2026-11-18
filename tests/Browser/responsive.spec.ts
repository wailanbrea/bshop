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

test('main configuration screens remain usable at narrow, tablet and desktop widths', async ({ page, businessState }) => {
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: width < 768 ? 700 : 900 });
        await page.goto('/');
        for (const name of ['Catálogo', 'Inventario', 'Sucursales', 'Almacenes', 'Equipo', 'Configuración']) {
            if (width < 768) await page.getByRole('button', { name: 'Abrir menú', exact: true }).click();
            await page.getByRole('button', { name, exact: true }).click();
            const issues = await page.evaluate(() => {
                const overflow = document.documentElement.scrollWidth > innerWidth;
                const small = [...document.querySelectorAll<HTMLElement>('main button,main input:not([type=checkbox]):not([type=radio]):not([type=hidden]),main select')].filter(el => {
                    const rect = el.getBoundingClientRect();
                    const style = getComputedStyle(el);
                    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && (rect.width < 44 || rect.height < 44);
                }).map(el => ({ text: el.getAttribute('aria-label') || el.textContent?.trim() || el.getAttribute('placeholder') || el.tagName, width: Math.round(el.getBoundingClientRect().width), height: Math.round(el.getBoundingClientRect().height) }));
                return { overflow, small };
            });
            expect(issues, `${width}px · ${name}: ${JSON.stringify(issues)}`).toEqual({ overflow: false, small: [] });
        }
    }
    expect(businessState.writes).toBe(0);
});

test('sign-in controls and brand remain usable across phone, tablet and desktop widths', async ({ page, businessState }) => {
    await page.route('**/api/v1/session', route => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: width < 768 ? 700 : 900 });
        await page.goto('/login');
        await expect(page.getByRole('heading', { name: 'Qué bueno verte.', exact: true })).toBeVisible();
        const issues = await page.evaluate(() => ({
            overflow: document.documentElement.scrollWidth > innerWidth,
            small: [...document.querySelectorAll<HTMLElement>('main a,main button,main input:not([type=hidden])')].filter(el => {
                const rect = el.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 && getComputedStyle(el).visibility !== 'hidden' && (rect.width < 44 || rect.height < 44);
            }).map(el => ({ name: el.getAttribute('aria-label') || el.textContent?.trim() || el.getAttribute('placeholder'), width: Math.round(el.getBoundingClientRect().width), height: Math.round(el.getBoundingClientRect().height) })),
        }));
        expect(issues, `${width}px: ${JSON.stringify(issues)}`).toEqual({ overflow: false, small: [] });
    }
    expect(businessState.writes).toBe(0);
});
