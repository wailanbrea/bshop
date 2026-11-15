import { test, expect } from './tablet.fixture';

test('reservation policy retries loading preserves hours on rotation and handles a stale save', async ({ page, businessState }) => {
    let failLoad = true; let stored: number | null = null; let stale = true;
    const writes: unknown[] = [];
    await page.route('**/api/v1/business/reservations/settings', async route => {
        if (route.request().method() === 'GET') return route.fulfill({ status: failLoad ? 503 : 200, json: failLoad ? { message: 'Plazo no disponible.' } : { data: { hours: stored } } });
        const payload = route.request().postDataJSON(); writes.push(payload);
        if (stale) { stale = false; return route.fulfill({ status: 409, json: { message: 'El plazo fue modificado. Actualiza antes de guardar.' } }); }
        stored = payload.hours; return route.fulfill({ json: { data: { hours: stored } } });
    });
    await page.goto('/');
    if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', {name:'Abrir menú',exact:true}).click();
    await page.getByRole('button', {name:'Configuración',exact:true}).click();
    const section = page.getByRole('region', {name:'Plazo de reservas',exact:true});
    await expect(section.getByRole('alert')).toContainText('Plazo no disponible.');
    failLoad = false;
    await section.getByRole('button', {name:'Reintentar carga del plazo'}).click();
    await section.getByRole('checkbox').check();
    const hours = section.getByRole('spinbutton', {name:'Plazo en horas'});
    await hours.fill('48');
    await page.setViewportSize({width:1024,height:380});
    await expect(hours).toHaveValue('48');
    await section.getByRole('button', {name:'Guardar plazo de reservas'}).click();
    await expect(section.getByRole('alert')).toContainText('Actualiza antes de volver a intentarlo.');
    await expect(hours).toHaveValue('48');
    await section.getByRole('button', {name:'Actualizar plazo'}).click();
    await expect(section.getByRole('checkbox')).not.toBeChecked();
    await section.getByRole('checkbox').check(); await hours.fill('48');
    await section.getByRole('button', {name:'Guardar plazo de reservas'}).click();
    await expect(section.getByRole('status')).toContainText('Plazo de reservas guardado.');
    expect(writes.at(-1)).toEqual({hours:48,previous_hours:null});
    await section.getByRole('checkbox').uncheck();
    await section.getByRole('button', {name:'Guardar plazo de reservas'}).click();
    await expect.poll(() => writes.length).toBe(3);
    expect(writes.at(-1)).toEqual({hours:null,previous_hours:48});
    expect(businessState.writes).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
});
