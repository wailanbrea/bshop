import { test, expect, stores, roles, members } from './tablet.fixture';
import type { Page } from '@playwright/test';

async function openApp(page: Page) {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Vista general', exact: true })).toBeVisible();
}
async function noOverflow(page: Page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
}

test('tablet pages have visible navigation, readable controls and 44px touch targets', async ({ page, businessState }) => {
    await openApp(page);
    for (const name of ['Vista general', 'Catálogo', 'Sucursales', 'Almacenes', 'Equipo', 'Configuración']) {
        const button = page.getByRole('button', { name, exact: true });
        await expect(button).toBeInViewport();
        await button.tap();
        await noOverflow(page);
        await expect(page.getByRole('combobox', { name: 'SUCURSAL ACTIVA', exact: true })).toBeInViewport({ ratio: 1 });
        const undersized = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('button,input:not([type=checkbox]),select')].filter(el => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0 && r.right > 0 && r.left < innerWidth && r.top < innerHeight && r.bottom > 0 && (r.width < 44 || r.height < 44);
        }).map(el => el.textContent?.trim()));
        expect(undersized).toEqual([]);
        expect(await page.locator('.branch-picker select').evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
    }
    expect(businessState.writes).toBe(0);
});

test('branch draft survives rotation and reduced keyboard viewport with reachable actions', async ({ page, businessState }) => {
    await openApp(page);
    await page.getByRole('button', { name: 'Sucursales', exact: true }).tap();
    const trigger = page.getByRole('button', { name: '+ Nueva sucursal', exact: true });
    await trigger.focus(); await trigger.tap();
    const dialog = page.getByRole('dialog', { name: 'Nueva ubicación: sucursal', exact: true });
    await expect(dialog).toBeVisible();
    expect(await dialog.evaluate(el => el instanceof HTMLDialogElement && el.open)).toBeTruthy();
    await expect(dialog.getByRole('heading')).toBeFocused();
    await dialog.getByLabel('Nombre', { exact: true }).fill('Borrador de tablet');
    await dialog.getByLabel('Código', { exact: true }).fill('TABLET-QA');
    await page.setViewportSize({ width: 1024, height: 768 });
    await page.setViewportSize({ width: 768, height: 1024 });
    await expect(dialog.getByLabel('Nombre', { exact: true })).toHaveValue('Borrador de tablet');
    await expect(dialog.getByLabel('Código', { exact: true })).toHaveValue('TABLET-QA');
    await page.setViewportSize({ width: 1024, height: 380 });
    await dialog.getByLabel('Teléfono', { exact: true }).fill('8090000000');
    await expect(dialog.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeInViewport({ ratio: 1 });
    await expect(dialog.getByRole('button', { name: 'Cancelar', exact: true })).toBeInViewport({ ratio: 1 });
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden');
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).tap();
    await expect(dialog).not.toBeVisible();
    await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('');
    await expect(trigger).toBeFocused();
    expect(businessState.writes).toBe(0);
});

test('drawer traps focus, isolates the page and closes after touch navigation', async ({ page, businessState }) => {
    await page.setViewportSize({ width: 800, height: 1024 });
    await openApp(page);
    await page.getByRole('button', { name: 'Abrir menú', exact: true }).tap();
    const drawer = page.getByRole('dialog', { name: 'Menú de navegación', exact: true });
    await expect(drawer).toBeVisible();
    await expect(page.locator('.main-shell')).toHaveAttribute('inert', '');
    await drawer.getByRole('button', { name: 'Cerrar menú', exact: true }).focus();
    await page.keyboard.press('Shift+Tab');
    await expect(drawer.getByRole('button', { name: 'Cerrar sesión', exact: true })).toBeFocused();
    await drawer.getByRole('button', { name: 'Configuración', exact: true }).tap();
    await expect(drawer).not.toBeVisible();
    await expect(page.getByRole('heading', { name: 'Información del negocio', exact: true })).toBeVisible();
    await expect(page.locator('.main-shell')).not.toHaveAttribute('inert', '');
    await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe('');
    expect(businessState.writes).toBe(0);
});

test('long team forms scroll internally and preserve touch choices with the keyboard open', async ({ page, businessState }) => {
    const many = Array.from({ length: 60 }, (_, index) => ({ ...stores[0], id: index + 1, name: `Sucursal ${index + 1}` }));
    await page.route('**/api/v1/business/team', route => route.fulfill({ json: { data: { members, roles, stores: many, invitations: [] } } }));
    await openApp(page);
    await page.getByRole('button', { name: 'Equipo', exact: true }).tap();
    await page.getByRole('button', { name: '+ Invitar persona', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Invitar al equipo', exact: true });
    await dialog.getByLabel('Correo de la persona', { exact: true }).fill('qa@example.test');
    await page.setViewportSize({ width: 1024, height: 380 });
    const last = dialog.getByRole('checkbox', { name: 'Sucursal 60', exact: true });
    await last.scrollIntoViewIfNeeded(); await last.tap();
    await expect(last).toBeChecked();
    await expect(dialog.getByRole('button', { name: 'Generar invitación', exact: true })).toBeInViewport({ ratio: 1 });
    await expect(dialog.getByRole('button', { name: 'Generar invitación', exact: true })).toBeEnabled();
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).tap();
    expect(businessState.writes).toBe(0);
});

test('validation keeps the draft, exposes the error and double submission sends one request', async ({ page, businessState }) => {
    businessState.failCreate = true; businessState.delay = 250;
    await openApp(page);
    await page.getByRole('button', { name: 'Sucursales', exact: true }).tap();
    await page.getByRole('button', { name: '+ Nueva sucursal', exact: true }).tap();
    const dialog = page.getByRole('dialog', { name: 'Nueva ubicación: sucursal', exact: true });
    await dialog.getByLabel('Nombre', { exact: true }).fill('Sucursal QA');
    await dialog.getByLabel('Código', { exact: true }).fill('INVALID?');
    expect(await dialog.getByLabel('Código', { exact: true }).evaluate((input: HTMLInputElement) => input.checkValidity())).toBeFalsy();
    await dialog.getByLabel('Código', { exact: true }).fill('QA-NORTE_2');
    expect(await dialog.getByLabel('Código', { exact: true }).evaluate((input: HTMLInputElement) => input.checkValidity())).toBeTruthy();
    await dialog.getByLabel('Código', { exact: true }).fill('TABLET');
    await page.setViewportSize({ width: 1024, height: 380 });
    await dialog.getByRole('button', { name: 'Guardar cambios', exact: true }).evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
    await expect(dialog.getByRole('alert')).toHaveText('El código ya está en uso.');
    await expect(dialog.getByRole('alert')).toBeInViewport({ ratio: 1 });
    expect(businessState.writes).toBe(1);
    await expect(dialog.getByLabel('Nombre', { exact: true })).toHaveValue('Sucursal QA');
    businessState.failCreate = false;
    await dialog.getByRole('button', { name: 'Guardar cambios', exact: true }).tap();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('status')).toHaveText('Los cambios se guardaron correctamente.');
    expect(businessState.writes).toBe(2);
});

test('active branch remains reachable while long content is scrolled', async ({ page, businessState }) => {
    const many = Array.from({ length: 30 }, (_, index) => ({ ...members[0], id: index + 1, name: `Usuario ${index + 1}`, email: `user${index + 1}@example.test` }));
    await page.route('**/api/v1/business/team', route => route.fulfill({ json: { data: { members: many, roles, stores, invitations: [] } } }));
    await openApp(page);
    await page.getByRole('button', { name: 'Equipo', exact: true }).tap();
    await expect(page.locator('.team-record')).toHaveCount(30);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    const picker = page.getByRole('combobox', { name: 'SUCURSAL ACTIVA', exact: true });
    await expect(picker).toBeInViewport({ ratio: 1 });
    await picker.selectOption('2');
    await expect(picker).toHaveValue('2');
    await noOverflow(page);
    expect(businessState.writes).toBe(0);
});

test('login and invitation fields remain readable and zoom is not disabled', async ({ page, businessState }) => {
    await page.route('**/api/v1/session', route => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.goto('/login');
    await expect(page.getByLabel('Correo electrónico', { exact: true })).toBeVisible();
    expect(await page.getByLabel('Correo electrónico', { exact: true }).evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
    const viewport = await page.locator('meta[name=viewport]').getAttribute('content');
    expect(viewport).not.toMatch(/user-scalable\s*=\s*no|maximum-scale\s*=\s*1/);
    await noOverflow(page);
    await page.goto(`/join/${'x'.repeat(64)}`);
    await expect(page.getByLabel('Tu nombre', { exact: true })).toBeVisible();
    await page.getByLabel('Tu nombre', { exact: true }).fill('Borrador invitado');
    await page.setViewportSize({ width: 1024, height: 768 });
    await expect(page.getByLabel('Tu nombre', { exact: true })).toHaveValue('Borrador invitado');
    await noOverflow(page);
    expect(businessState.writes).toBe(0);
});

test('mobile fallback keeps the drawer and dialog actions usable', async ({ page, businessState }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openApp(page);
    await page.getByRole('button', { name: 'Abrir menú', exact: true }).tap();
    await page.getByRole('dialog', { name: 'Menú de navegación', exact: true }).getByRole('button', { name: 'Sucursales', exact: true }).tap();
    await page.getByRole('button', { name: '+ Nueva sucursal', exact: true }).tap();
    await page.setViewportSize({ width: 390, height: 340 });
    const dialog = page.getByRole('dialog', { name: 'Nueva ubicación: sucursal', exact: true });
    await expect(dialog.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeInViewport({ ratio: 1 });
    await expect(dialog.getByRole('button', { name: 'Cancelar', exact: true })).toBeInViewport({ ratio: 1 });
    await noOverflow(page);
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).tap();
    expect(businessState.writes).toBe(0);
});
