import { test as base, expect } from './tablet.fixture';

type State={products:any[];creates:any[];uploads:string[];failPhoto:boolean;lostCreate:boolean};
const test=base.extend<{catalog:State}>({catalog:async({page,businessState},use)=>{
    const state:State={products:[],creates:[],uploads:[],failPhoto:true,lostCreate:false};
    await page.route('**/api/v1/business/products**',async route=>{
        const req=route.request();const url=new URL(req.url());const match=url.pathname.match(/\/products\/(\d+)(\/images)?$/);
        if(match?.[2]&&req.method()==='POST'){state.uploads.push(req.postData()||'');if(state.failPhoto){state.failPhoto=false;return route.fulfill({status:500,json:{message:'Error de fotografía'}});}return route.fulfill({status:201,json:{data:{status:'verified'}}});}
        if(match?.[2]&&req.method()==='GET')return route.fulfill({json:{data:{images:[],version:1,pending_uploads:0,quota:{max_bytes:2147483648,ready_bytes:0,pending_bytes:0,retained_bytes:0}}}});
        if(match&&req.method()==='GET'){const product=state.products.find(p=>p.id===Number(match[1]));return route.fulfill({json:{data:{product,variants:{data:[],current_page:1,last_page:1,total:0}}}});}
        if(req.method()==='GET')return route.fulfill({json:{data:state.products,meta:{current_page:1,last_page:1,total:state.products.length}}});
        if(req.method()==='POST'){const body=req.postDataJSON();state.creates.push(body);let product=state.products.find(p=>p.key===body.request_key);if(!product){const variants=body.variants||body.clothing_variants;product={id:2,key:body.request_key,name:body.name,description:null,product_type:'physical',unit_code:'unit',track_stock:true,status:'active',version:1,category:null,brand:null,primary_sku:variants[0].sku,variants_count:variants.length,active_variants_count:variants.length,min_sale_price:'25.0000',max_sale_price:'25.0000',images:[]};state.products.push(product);}if(state.lostCreate){state.lostCreate=false;return route.abort('failed');}return route.fulfill({status:201,json:{data:product}});}
        return route.fulfill({status:403});
    });
    await use(state);expect(businessState.writes).toBe(0);
}});

test('photos are selected first and a failed upload retries without duplicating the product or initial stock',async({page,catalog})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Seleccionar fotos').setInputFiles([{name:'verde.png',mimeType:'image/png',buffer:Buffer.from('foto-verde')},{name:'negro.png',mimeType:'image/png',buffer:Buffer.from('foto-negro')}]);
    await expect(dialog.getByAltText('Foto seleccionada 1')).toBeVisible();await expect(dialog.getByAltText('Foto seleccionada 2')).toBeVisible();await dialog.getByLabel('Principal').nth(1).check();
    await dialog.getByLabel('Nombre del producto').fill('Blusa QA');await dialog.getByLabel('SKU',{exact:true}).fill('BLUSA-QA');await dialog.getByLabel('Precio de venta',{exact:true}).fill('25');
    await dialog.getByLabel('Registrar entrada inicial por variante').check();await dialog.getByLabel(/BLUSA-QA · Variante general/).fill('5');
    await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Nombre del producto')).toHaveValue('Blusa QA');
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog.getByRole('alert')).toContainText('El producto se guardó.');
    expect(catalog.creates).toHaveLength(1);expect(catalog.creates[0].initial_stock.items[0]).toMatchObject({sku:'BLUSA-QA',quantity:'5'});expect(catalog.uploads[0]).toContain('negro.png');
    await dialog.getByRole('button',{name:'Reintentar fotos'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.products).toHaveLength(1);expect(catalog.creates).toHaveLength(1);expect(catalog.uploads).toHaveLength(3);expect(catalog.uploads[0]).toContain('negro.png');expect(catalog.uploads[1]).toContain('negro.png');expect(catalog.uploads[2]).toContain('verde.png');
    const key=(body:string)=>body.match(/name="request_key"\r?\n\r?\n([^\r\n]+)/)?.[1];expect(key(catalog.uploads[0])).toBeTruthy();expect(key(catalog.uploads[1])).toBe(key(catalog.uploads[0]));expect(key(catalog.uploads[2])).not.toBe(key(catalog.uploads[0]));
});

test('an uncertain product save is retried after reload with the original key and stock payload',async({page,catalog})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();let dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Nombre del producto').fill('Prenda recuperada');await dialog.getByLabel('SKU',{exact:true}).fill('REC-01');await dialog.getByLabel('Precio de venta',{exact:true}).fill('25');
    await dialog.getByLabel('Registrar entrada inicial por variante').check();await dialog.getByLabel(/REC-01 · Variante general/).fill('3');catalog.lostCreate=true;
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog.getByRole('alert')).toContainText('No hay conexión');
    await page.reload();if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();dialog=page.getByRole('dialog',{name:'Nuevo producto'});await expect(dialog).toContainText('Hay un guardado pendiente');
    await dialog.getByRole('button',{name:'Reintentar guardado'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.products).toHaveLength(1);expect(catalog.creates).toHaveLength(2);expect(catalog.creates[1]).toEqual(catalog.creates[0]);
});

test('after a photo failure, reloading keeps a reminder pointing to the saved product gallery',async({page,catalog})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Seleccionar fotos').setInputFiles({name:'foto.png',mimeType:'image/png',buffer:Buffer.from('foto')});
    await dialog.getByLabel('Nombre del producto').fill('Producto con foto pendiente');await dialog.getByLabel('SKU',{exact:true}).fill('FOTO-QA');await dialog.getByLabel('Precio de venta',{exact:true}).fill('25');
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog.getByRole('alert')).toContainText('El producto se guardó.');await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();
    await page.reload();if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    const reminder=page.getByRole('status').filter({hasText:'Producto con foto pendiente'});await expect(reminder).toBeVisible();await reminder.getByRole('button',{name:'Revisar fotos'}).click();
    await expect(page.getByRole('dialog',{name:'Fotos de Producto con foto pendiente'})).toBeVisible();expect(catalog.products).toHaveLength(1);expect(catalog.creates).toHaveLength(1);
});

test('initial quantities are entered separately for color and size combinations',async({page,catalog})=>{
    await page.route('**/api/v1/business/catalog/options',route=>route.fulfill({json:{data:{categories:[],brands:[],attributes:[{id:1,name:'Color',is_active:true,values:[{id:11,attribute_id:1,value:'Verde',is_active:true}]},{id:2,name:'Talla',is_active:true,values:[{id:21,attribute_id:2,value:'S',is_active:true},{id:22,attribute_id:2,value:'M',is_active:true}]}]}}}));
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});await dialog.getByLabel('Nombre del producto').fill('Blusa');
    const first=dialog.locator('.variant-draft').nth(0);await first.getByLabel('SKU',{exact:true}).fill('VERDE-S');await first.getByLabel('Precio de venta',{exact:true}).fill('25');await first.getByRole('combobox',{name:'Color',exact:true}).selectOption('11');await first.getByRole('combobox',{name:'Talla',exact:true}).selectOption('21');
    await dialog.getByRole('button',{name:'+ Añadir otra variante'}).click();const second=dialog.locator('.variant-draft').nth(1);await second.getByLabel('SKU',{exact:true}).fill('VERDE-M');await second.getByLabel('Precio de venta',{exact:true}).fill('25');await second.getByRole('combobox',{name:'Color',exact:true}).selectOption('11');await second.getByRole('combobox',{name:'Talla',exact:true}).selectOption('22');
    await dialog.getByLabel('Registrar entrada inicial por variante').check();await dialog.getByLabel(/VERDE-S ·/).fill('5');await dialog.getByLabel(/VERDE-M ·/).fill('3');
    await expect(dialog).toContainText('2 variantes con cantidad indicada');await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates).toHaveLength(1);expect(catalog.creates[0].initial_stock.items.map((item:any)=>({sku:item.sku,quantity:item.quantity}))).toEqual([{sku:'VERDE-S',quantity:'5'},{sku:'VERDE-M',quantity:'3'}]);
});

test('clothing can be created from photos using preset colors, sizes and individual quantities',async({page,catalog},testInfo)=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    const png=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=64;canvas.height=64;const context=canvas.getContext('2d')!;context.fillStyle='#30935a';context.fillRect(0,0,64,64);return canvas.toDataURL('image/png').split(',')[1];});
    await dialog.getByLabel('Seleccionar fotos').setInputFiles({name:'blusa.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});await expect(dialog.getByLabel('Fotos de referencia')).toBeVisible();await expect.poll(()=>dialog.getByAltText('Referencia 1').evaluate((image:HTMLImageElement)=>image.naturalWidth)).toBeGreaterThan(0);
    await dialog.getByLabel('Nombre del producto').fill('Blusa');await dialog.getByLabel('Esta prenda tiene colores y tallas').check();await dialog.getByLabel('Precio de venta de la prenda').fill('25');
    await dialog.getByLabel('Registrar entrada inicial por color y talla').check();const colors=dialog.getByRole('group',{name:'Colores disponibles'});await colors.getByRole('button',{name:'Verde',exact:true}).click();await colors.getByRole('button',{name:'Negro',exact:true}).click();
    const green=dialog.getByRole('group',{name:'Tallas de Verde'});await green.getByRole('button',{name:'S',exact:true}).click();await green.getByRole('button',{name:'M',exact:true}).click();
    const black=dialog.getByRole('group',{name:'Tallas de Negro'});await black.getByRole('button',{name:'S',exact:true}).click();await black.getByRole('button',{name:'M',exact:true}).click();
    await dialog.getByLabel('Existencia inicial de Verde / S').fill('5');await dialog.getByLabel('Existencia inicial de Verde / M').fill('3');await dialog.getByLabel('Existencia inicial de Negro / S').fill('2');
    await colors.getByRole('button',{name:'Negro',exact:true}).click();await colors.getByRole('button',{name:'Negro',exact:true}).click();await expect(dialog.getByLabel('Existencia inicial de Negro / S')).toHaveValue('2');
    await page.setViewportSize({width:390,height:700});await dialog.getByLabel('Existencia inicial de Negro / S').scrollIntoViewIfNeeded();await expect(dialog.getByLabel('Fotos de referencia')).toBeInViewport();await page.screenshot({path:testInfo.outputPath('clothing-editor.png')});
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    const body=catalog.creates[0];expect(body.clothing_variants.map((v:any)=>`${v.color}/${v.size}`)).toEqual(['Verde/S','Verde/M','Negro/S','Negro/M']);
    expect(body.initial_stock.items.map((v:any)=>v.quantity)).toEqual(['5','3','2']);expect(body.variants).toBeUndefined();
});

test('custom colors and sizes can be added from the clothing form',async({page,catalog})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Nombre del producto').fill('Prenda especial');await dialog.getByLabel('Esta prenda tiene colores y tallas').check();await dialog.getByLabel('Precio de venta de la prenda').fill('25');
    await dialog.getByLabel('Otro color').fill('Lavanda');await dialog.getByRole('button',{name:'Añadir color',exact:true}).click();
    await dialog.getByLabel('Otra talla para Lavanda').fill('2XL especial');await dialog.getByRole('button',{name:'Añadir talla para Lavanda'}).click();
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates[0].clothing_variants).toMatchObject([{color:'Lavanda',size:'2XL especial'}]);
});
