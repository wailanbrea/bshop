import { test, expect } from './tablet.fixture';

async function openInventory(page: import('@playwright/test').Page) {
    await page.goto('/');
    if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', {name:'Abrir menú',exact:true}).click();
    await page.getByRole('button', {name:'Inventario',exact:true}).click();
    await expect(page.getByRole('button',{name:'Revisar inventario',exact:true})).toBeEnabled();
}
const issue = {code:'physical_ledger',message:'El saldo físico no coincide con los movimientos.'};
const rows = [
    {variant_id:1,sku:'REVIEW-1',product_name:'Producto a revisar',physical:'10.0000',reserved:'2.0000',ledger_physical:'9.0000',ledger_reserved:'2.0000',active_reserved:'2.0000',differences:[issue]},
    {variant_id:2,sku:'REVIEW-2',product_name:'Producto consistente',physical:'5.0000',reserved:'0.0000',ledger_physical:'5.0000',ledger_reserved:'0.0000',active_reserved:'0.0000',differences:[]},
];

test('inventory review shows differences preserves filters on rotation and never writes',async({page,businessState})=>{
    const writes: string[] = []; page.on('request',r=>{if(r.method()!=='GET')writes.push(r.url());});
    await page.route('**/warehouses/*/reconciliation*',async route=>{
        const only=new URL(route.request().url()).searchParams.get('only_issues')!=='0';
        return route.fulfill({json:{data:only?rows.slice(0,1):rows,summary:{checked:2,consistent:1,with_differences:1,differences:1,movements:3},generated_at:'2026-11-13T12:00:00Z',meta:{current_page:1,last_page:1,total:only?1:2}}});
    });
    await openInventory(page); await page.getByRole('button',{name:'Revisar inventario',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Revisión de inventario',exact:true});
    await expect(dialog).toContainText(issue.message);await expect(dialog).toContainText('2 variantes revisadas');
    await expect(dialog.getByRole('checkbox',{name:'Mostrar solo diferencias',exact:true})).toBeChecked();
    await dialog.getByRole('checkbox',{name:'Mostrar solo diferencias',exact:true}).uncheck();
    await expect(dialog).toContainText('Producto consistente');
    await page.setViewportSize({width:1024,height:380});
    await expect(dialog.getByRole('checkbox')).not.toBeChecked();
    await expect(dialog.getByRole('button',{name:'Cerrar revisión',exact:true})).toBeInViewport({ratio:0.99});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
    await dialog.getByRole('button',{name:'Cerrar revisión',exact:true}).click();
    await expect(dialog).not.toBeVisible();await expect(page.getByRole('button',{name:'Revisar inventario',exact:true})).toBeFocused();
    expect(writes).toHaveLength(0);expect(businessState.writes).toBe(0);
});

test('inventory review retries errors and reports a consistent warehouse',async({page,businessState})=>{
    let unavailable=true;
    await page.route('**/warehouses/*/reconciliation*',route=>unavailable
        ?route.fulfill({status:503,json:{message:'La revisión no está disponible temporalmente.'}})
        :route.fulfill({json:{data:[],summary:{checked:2,consistent:2,with_differences:0,differences:0,movements:3},generated_at:'2026-11-13T12:00:00Z',meta:{current_page:1,last_page:1,total:0}}}));
    await openInventory(page);await page.getByRole('button',{name:'Revisar inventario',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Revisión de inventario',exact:true});
    await expect(dialog.getByRole('alert')).toContainText('no está disponible temporalmente');
    unavailable=false;await dialog.getByRole('button',{name:'Actualizar revisión',exact:true}).click();
    await expect(dialog).toContainText('No se detectaron diferencias en este almacén.');
    await expect(dialog).toContainText('2 sin diferencias');expect(businessState.writes).toBe(0);
});
