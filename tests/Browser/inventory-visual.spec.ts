import { test as base, expect } from './tablet.fixture';

const test = base.extend<{ visualInventory:{failImage:boolean} }>({ visualInventory:async({page,businessState},use)=>{
    const state={failImage:false};
    await page.route('**/qa-thumb.svg',route=>state.failImage?route.fulfill({status:404}):route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="green"/></svg>'}));
    await page.route('**/api/v1/business/stores/*/warehouses/*/stock*',route=>route.fulfill({json:{data:[{variant_id:1,warehouse_id:1,sku:'BLUSA-VERDE-S',product_name:'Blusa',product_id:1,unit_code:'unit',active:true,attributes:[{name:'Color',value:'Verde'},{name:'Talla',value:'S'}],on_hand:'5.0000',reserved:'1.0000',available:'4.0000',unit_cost:'15.0000',sale_price:'30.0000',invested_total:'75.0000',sale_total:'150.0000',version:1,image_url:'/qa-thumb.svg'}],meta:{current_page:1,last_page:1,total:1,valuation:{currency:'USD',invested_cost:'75.0000',sale_value:'150.0000',estimated_profit:'75.0000',units_on_hand:'5.0000',units_available:'4.0000'}}}}));
    await page.route('**/api/v1/business/stores/*/inventory/destinations',route=>route.fulfill({json:{data:[]}}));
    await use(state);expect(businessState.writes).toBe(0);
}});

test('inventory displays variant photo and identity again when adjusting',async({page,visualInventory})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
    await page.getByRole('button',{name:'Inventario',exact:true}).click();
    const valuation=page.getByRole('region',{name:'Valor del inventario',exact:true});await expect(valuation).toContainText('Capital invertido (costo)');await expect(valuation).toContainText('75.00');await expect(valuation).toContainText('150.00');
    const row=page.locator('.inventory-record');await expect(row).toContainText('Color: Verde');await expect(row).toContainText('Talla: S');await expect(row).toContainText('Invertido:');
    await expect(row.getByRole('img',{name:'Foto de Blusa'})).toBeVisible();await expect(row).toContainText('Físico');await expect(row).toContainText('Reservado');await expect(row).toContainText('Disponible');
    await row.getByRole('button',{name:'Ajustar BLUSA-VERDE-S'}).click();const dialog=page.getByRole('dialog',{name:'Registrar movimiento de inventario'});
    await expect(dialog.getByRole('img',{name:'Foto de Blusa'})).toBeVisible();await expect(dialog).toContainText('Color: Verde');await expect(dialog).toContainText('Talla: S');await expect(dialog).toContainText('Almacén principal');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('a failed thumbnail shows a clear placeholder in the list and adjustment',async({page,visualInventory})=>{
    visualInventory.failImage=true;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
    await page.getByRole('button',{name:'Inventario',exact:true}).click();const row=page.locator('.inventory-record');await expect(row.getByRole('img',{name:'Sin fotografía'})).toBeVisible();
    await row.getByRole('button',{name:'Ajustar BLUSA-VERDE-S'}).click();await expect(page.getByRole('dialog').getByRole('img',{name:'Sin fotografía'})).toBeVisible();
});
