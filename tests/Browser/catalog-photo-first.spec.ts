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
    await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();
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
    await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();
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
    await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();
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
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();await dialog.getByLabel('Nombre del producto').fill('Blusa');
    const first=dialog.locator('.variant-draft').nth(0);await first.getByLabel('SKU',{exact:true}).fill('VERDE-S');await first.getByLabel('Precio de venta',{exact:true}).fill('25');await first.getByRole('combobox',{name:'Color',exact:true}).selectOption('11');await first.getByRole('combobox',{name:'Talla',exact:true}).selectOption('21');
    await dialog.getByRole('button',{name:'+ Añadir otra variante'}).click();const second=dialog.locator('.variant-draft').nth(1);await second.getByLabel('SKU',{exact:true}).fill('VERDE-M');await second.getByLabel('Precio de venta',{exact:true}).fill('25');await second.getByRole('combobox',{name:'Color',exact:true}).selectOption('11');await second.getByRole('combobox',{name:'Talla',exact:true}).selectOption('22');
    await dialog.getByLabel('Registrar entrada inicial por variante').check();await dialog.getByLabel(/VERDE-S ·/).fill('5');await dialog.getByLabel(/VERDE-M ·/).fill('3');
    await expect(dialog).toContainText('2 variantes con cantidad indicada');await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates).toHaveLength(1);expect(catalog.creates[0].initial_stock.items.map((item:any)=>({sku:item.sku,quantity:item.quantity}))).toEqual([{sku:'VERDE-S',quantity:'5'},{sku:'VERDE-M',quantity:'3'}]);
});

test('clothing can be created from photos using preset colors, sizes and individual quantities',async({page,catalog},testInfo)=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();
    const png=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=64;canvas.height=64;const context=canvas.getContext('2d')!;context.fillStyle='#30935a';context.fillRect(0,0,64,64);return canvas.toDataURL('image/png').split(',')[1];});
    await dialog.getByLabel('Seleccionar fotos').setInputFiles({name:'blusa.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});await expect(dialog.getByLabel('Fotos de referencia')).toBeVisible();await expect.poll(()=>dialog.getByAltText('Referencia 1').evaluate((image:HTMLImageElement)=>image.naturalWidth)).toBeGreaterThan(0);
    await dialog.getByLabel('Nombre del producto').fill('Blusa');await dialog.getByLabel('Esta prenda tiene colores y tallas').check();await dialog.getByLabel('Precio de venta de la prenda').fill('25');
    await dialog.getByLabel('Registrar entrada inicial por color y talla').check();const colors=dialog.getByRole('group',{name:'Colores disponibles'});await colors.getByRole('button',{name:'Verde',exact:true}).click();await colors.getByRole('button',{name:'Negro',exact:true}).click();
    const green=dialog.getByRole('group',{name:'Tallas de Verde'});await green.getByRole('button',{name:'S',exact:true}).click();await green.getByRole('button',{name:'M',exact:true}).click();
    const black=dialog.getByRole('group',{name:'Tallas de Negro'});await black.getByRole('button',{name:'S',exact:true}).click();await black.getByRole('button',{name:'M',exact:true}).click();
    await dialog.getByLabel('Precio para Verde / M (opcional)').fill('35');
    await dialog.getByLabel('Existencia inicial de Verde / S').fill('5');await dialog.getByLabel('Existencia inicial de Verde / M').fill('3');await dialog.getByLabel('Existencia inicial de Negro / S').fill('2');
    await colors.getByRole('button',{name:'Negro',exact:true}).click();await colors.getByRole('button',{name:'Negro',exact:true}).click();await expect(dialog.getByLabel('Existencia inicial de Negro / S')).toHaveValue('2');
    await page.setViewportSize({width:390,height:700});await dialog.getByLabel('Existencia inicial de Negro / S').scrollIntoViewIfNeeded();await expect(dialog.getByLabel('Fotos de referencia')).toBeInViewport();await page.screenshot({path:testInfo.outputPath('clothing-editor.png')});
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    const body=catalog.creates[0];expect(body.clothing_variants.map((v:any)=>`${v.color}/${v.size}`)).toEqual(['Verde/S','Verde/M','Negro/S','Negro/M']);
    expect(body.clothing_variants.map((v:any)=>v.sale_price)).toEqual(['25','35','25','25']);
    expect(body.initial_stock.items.map((v:any)=>v.quantity)).toEqual(['5','3','2']);expect(body.variants).toBeUndefined();
});

test('custom colors and sizes can be added from the clothing form',async({page,catalog})=>{
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto'}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});await dialog.getByRole('button',{name:'Modo avanzado',exact:true}).click();
    await dialog.getByLabel('Nombre del producto').fill('Prenda especial');await dialog.getByLabel('Esta prenda tiene colores y tallas').check();await dialog.getByLabel('Precio de venta de la prenda').fill('25');
    await dialog.getByLabel('Otro color').fill('Lavanda');await dialog.getByRole('button',{name:'Añadir color',exact:true}).click();
    await dialog.getByLabel('Otra talla para Lavanda').fill('2XL especial');await dialog.getByRole('button',{name:'Añadir talla para Lavanda'}).click();
    await dialog.getByRole('button',{name:'Guardar producto'}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates[0].clothing_variants).toMatchObject([{color:'Lavanda',size:'2XL especial'}]);
});

test('quick one-by-one mode captures photo, name, cost price, sale price, quantity, color, size and optional description',async({page,catalog})=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Seleccionar foto del producto').setInputFiles({name:'tshirt.png',mimeType:'image/png',buffer:Buffer.from('foto-tshirt')});
    await dialog.getByLabel('Nombre',{exact:true}).fill('T-shirt Alo');
    await dialog.getByLabel('Precio de compra (opcional)',{exact:true}).fill('15');
    await dialog.getByLabel('Precio de venta',{exact:true}).fill('30');
    await dialog.getByLabel('Cantidad',{exact:true}).fill('3');
    await dialog.getByLabel('Color',{exact:true}).fill('Negro');
    await dialog.getByLabel('Size',{exact:true}).fill('M');
    await dialog.getByRole('button',{name:'Guardar producto',exact:true}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates).toHaveLength(1);
    expect(catalog.creates[0]).toMatchObject({name:'T-shirt Alo',description:null,clothing_variants:[{color:'Negro',size:'M',cost_price:'15',sale_price:'30'}]});
    expect(catalog.creates[0].initial_stock.items[0].quantity).toBe('3');
    expect(catalog.uploads).toHaveLength(1);
});

test('batch mode up to 10 photos creates independent products with cost and sale prices under each photo',async({page,catalog})=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Subir hasta 10 fotos',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByLabel('Seleccionar hasta 20 fotos').setInputFiles([
        {name:'uno.png',mimeType:'image/png',buffer:Buffer.from('foto-1')},
        {name:'dos.png',mimeType:'image/png',buffer:Buffer.from('foto-2')},
    ]);
    await dialog.getByLabel('Nombre (foto 1)').fill('Conjunto Gris');
    await dialog.getByLabel('Precio de compra (foto 1)').fill('18');
    await dialog.getByLabel('Precio de venta (foto 1)').fill('35');
    await dialog.getByLabel('Cantidad (foto 1)').fill('2');
    await dialog.getByLabel('Color (foto 1)').fill('Gris');
    await dialog.getByLabel('Size (foto 1)').fill('S');
    await dialog.getByLabel('Nombre (foto 2)').fill('Vestido Negro');
    await dialog.getByLabel('Precio de compra (foto 2)').fill('22');
    await dialog.getByLabel('Precio de venta (foto 2)').fill('45');
    await dialog.getByLabel('Cantidad (foto 2)').fill('4');
    await dialog.getByLabel('Color (foto 2)').fill('Negro');
    await dialog.getByLabel('Size (foto 2)').fill('L');
    await dialog.getByLabel('Descripción (foto 2, opcional)').fill('Tela elástica');
    await dialog.getByRole('button',{name:'Guardar productos',exact:true}).click();await expect(dialog).not.toBeVisible();
    expect(catalog.creates).toHaveLength(2);
    expect(catalog.creates[0]).toMatchObject({name:'Conjunto Gris',description:null,clothing_variants:[{color:'Gris',size:'S',cost_price:'18',sale_price:'35'}]});
    expect(catalog.creates[1]).toMatchObject({name:'Vestido Negro',description:'Tela elástica',clothing_variants:[{color:'Negro',size:'L',cost_price:'22',sale_price:'45'}]});
    expect(catalog.uploads).toHaveLength(2);
});

test('category-aware forms adapt fields for Calzado, Ropa, Electronicos and Joyeria/Lentes with optional cost',async({page,catalog})=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Nuevo producto',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});

    // 1. Ropa: exact sizes XS to 2XL
    await dialog.getByRole('combobox',{name:'Categoría',exact:true}).selectOption('Ropa');
    const ropaSizes=dialog.getByRole('group',{name:'Tallas disponibles'});
    await expect(ropaSizes).toContainText('XS');
    await expect(ropaSizes).toContainText('2XL');
    await expect(ropaSizes).not.toContainText('XXS');

    // 2. Calzado: EU 35-42 and Regular 6-10, never XS/S
    await dialog.getByRole('combobox',{name:'Categoría',exact:true}).selectOption('Calzado');
    await expect(dialog.getByRole('combobox',{name:'Sistema de tallas',exact:true})).toBeVisible();
    await expect(dialog.getByRole('group',{name:'Tallas disponibles'})).toContainText('35');
    await expect(dialog.getByRole('group',{name:'Tallas disponibles'})).toContainText('42');
    await expect(dialog.getByRole('group',{name:'Tallas disponibles'})).not.toContainText('XS');
    await dialog.getByRole('combobox',{name:'Sistema de tallas',exact:true}).selectOption('us');
    await expect(dialog.getByRole('group',{name:'Tallas disponibles'})).toContainText('6');
    await expect(dialog.getByRole('group',{name:'Tallas disponibles'})).toContainText('10');

    // 3. Electrónicos: Color visible, Size hidden
    await dialog.getByRole('combobox',{name:'Categoría',exact:true}).selectOption('Electrónicos');
    await expect(dialog.getByLabel('Color',{exact:true})).toBeVisible();
    await expect(dialog.getByLabel('Size',{exact:true})).toHaveCount(0);

    // 4. Joyería / Lentes: neither Color nor Size, optional cost_price allowed empty
    await dialog.getByRole('combobox',{name:'Categoría',exact:true}).selectOption('Joyería');
    await expect(dialog.getByLabel('Color',{exact:true})).toHaveCount(0);
    await expect(dialog.getByLabel('Size',{exact:true})).toHaveCount(0);
    await dialog.getByLabel('Nombre',{exact:true}).fill('Pulsera de balines dorada');
    await dialog.getByLabel('Precio de venta',{exact:true}).fill('18');
    await dialog.getByLabel('Cantidad',{exact:true}).fill('4');
    await dialog.getByRole('button',{name:'Guardar producto',exact:true}).click();
    await expect(dialog).not.toBeVisible();

    expect(catalog.creates).toHaveLength(1);
    expect(catalog.creates[0]).toMatchObject({
        name:'Pulsera de balines dorada',
        category_name:'Joyería',
        clothing_variants:[{color:null,size:null,cost_price:null,sale_price:'18'}],
    });
});

test('batch mode selects category once at the top for all photos and supports 2 and 4 column views',async({page,catalog},testInfo)=>{
    catalog.failPhoto=false;await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Subir hasta 10 fotos',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});

    // Choose category once at the top
    await dialog.getByRole('combobox',{name:'Categoría del lote',exact:true}).selectOption('Electrónicos');
    await dialog.getByLabel('Seleccionar hasta 20 fotos').setInputFiles([
        {name:'a1.png',mimeType:'image/png',buffer:Buffer.from('a1')},
        {name:'a2.png',mimeType:'image/png',buffer:Buffer.from('a2')},
        {name:'a3.png',mimeType:'image/png',buffer:Buffer.from('a3')},
        {name:'a4.png',mimeType:'image/png',buffer:Buffer.from('a4')},
    ]);

    // No per-card category selector
    await expect(dialog.getByRole('combobox',{name:/Categoría \(foto/})).toHaveCount(0);
    // All 4 cards reflect Electrónicos (Color visible, Size hidden)
    await expect(dialog.getByLabel('Color (foto 1)')).toBeVisible();
    await expect(dialog.getByLabel('Color (foto 4)')).toBeVisible();
    await expect(dialog.getByLabel('Size (foto 1)')).toHaveCount(0);

    // Switch top-level category to Calzado + Regulares (6-10)
    await dialog.getByRole('combobox',{name:'Categoría del lote',exact:true}).selectOption('Calzado');
    await dialog.getByRole('combobox',{name:'Sistema de tallas',exact:true}).selectOption('us');
    await expect(dialog.getByRole('group',{name:'Tallas foto 1'})).toContainText('6');
    await expect(dialog.getByRole('group',{name:'Tallas foto 4'})).toContainText('10');

    // Toggle 2 and 4 products per row
    const grid=dialog.locator('.batch-photo-grid');
    await expect(grid).toHaveClass(/cols-2/);
    if((page.viewportSize()?.width||0)>=1024){
        const cards=grid.locator('.quick-photo-card');
        const box0=await cards.nth(0).boundingBox();
        const box1=await cards.nth(1).boundingBox();
        expect(box0&&box1&&Math.abs(box0.y-box1.y)).toBeLessThan(10);
        await page.screenshot({path:testInfo.outputPath('batch-2-cols.png')});
    }
    await dialog.getByRole('button',{name:'Ver 4',exact:true}).click();
    await expect(grid).toHaveClass(/cols-4/);

    if((page.viewportSize()?.width||0)>=1024){
        const cards=grid.locator('.quick-photo-card');
        const box0=await cards.nth(0).boundingBox();
        const box3=await cards.nth(3).boundingBox();
        expect(box0&&box3&&Math.abs(box0.y-box3.y)).toBeLessThan(10);
        await page.screenshot({path:testInfo.outputPath('batch-4-cols.png')});
    }
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
});

test('batch same-product mode creates 1 product with 3 variant photos and sliding updates price per photo',async({page,catalog},testInfo)=>{
    catalog.failPhoto=false;
    const svgImg=(color:string)=>`data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="160" height="160" fill="${color}"/></svg>`)}`;
    let storedVariants:any[]=[];
    let storedImages:any[]=[];
    await page.route('**/api/v1/business/products**',async route=>{
        const req=route.request();const url=new URL(req.url());const match=url.pathname.match(/\/products\/(\d+)(\/images)?$/);
        if(match?.[2]&&req.method()==='POST'){
            const body=req.postData()||'';
            catalog.uploads.push(body);
            const vidMatch=body.match(/name="variant_id"\r?\n\r?\n(\d+)/);
            const variantId=vidMatch?Number(vidMatch[1]):null;
            const variant=storedVariants.find(v=>v.id===variantId);
            const idx=storedImages.length;
            const colors=['#d32f2f','#222222','#ef6c00'];
            storedImages.push({
                id:idx+1,variant_id:variantId,
                variant_sale_price:variant?.sale_price??null,
                variant_label:variant?.attribute_values?.map((a:any)=>`${a.attribute_name}: ${a.value}`).join(' · ')||null,
                alt_text:null,is_primary:idx===0,sort_order:idx,
                renditions:{thumb:{url:svgImg(colors[idx]||'#7050ca'),width:80,height:80},card:{url:svgImg(colors[idx]||'#7050ca'),width:160,height:160},detail:{url:svgImg(colors[idx]||'#7050ca'),width:320,height:320}},
            });
            if(catalog.products[0])catalog.products[0].images=[...storedImages];
            return route.fulfill({status:201,json:{data:{status:'verified'}}});
        }
        if(match&&!match[2]&&req.method()==='GET'){
            const product=catalog.products.find(p=>p.id===Number(match[1]));
            return route.fulfill({json:{data:{product,variants:{data:storedVariants,current_page:1,last_page:1,total:storedVariants.length}}}});
        }
        if(req.method()==='GET')return route.fulfill({json:{data:catalog.products,meta:{current_page:1,last_page:1,total:catalog.products.length}}});
        if(req.method()==='POST'){
            const body=req.postDataJSON();
            catalog.creates.push(body);
            const rows=body.clothing_variants||body.variants||[];
            storedVariants=rows.map((r:any,i:number)=>({
                id:100+i,product_id:55,sku:r.sku,barcode:null,sale_price:r.sale_price,cost_price:r.cost_price??null,description:r.description??null,is_active:true,version:1,
                attribute_values:r.color?[{id:i+1,attribute_id:1,attribute_name:'Color',value:r.color}]:[],
            }));
            const prices=rows.map((r:any)=>Number(r.sale_price)).sort((a:number,b:number)=>a-b);
            const product={id:55,key:body.request_key,name:body.name,description:body.description??null,product_type:'physical',unit_code:'unit',track_stock:true,status:'active',version:1,category:{id:9,name:body.category_name||'Electrónicos'},brand:null,primary_sku:rows[0]?.sku??null,variants_count:rows.length,active_variants_count:rows.length,min_sale_price:String(prices[0]??'0'),max_sale_price:String(prices[prices.length-1]??'0'),images:storedImages};
            catalog.products.push(product);
            return route.fulfill({status:201,json:{data:product}});
        }
        return route.continue();
    });

    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'+ Subir hasta 10 fotos',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Nuevo producto'});
    await dialog.getByRole('combobox',{name:'Categoría del lote',exact:true}).selectOption('Electrónicos');
    await dialog.getByRole('button',{name:'Mismo producto (variantes)',exact:true}).click();
    await dialog.getByLabel('Nombre del producto',{exact:true}).fill('Audifonos');
    await dialog.getByLabel('Seleccionar hasta 20 fotos').setInputFiles([
        {name:'rojo.png',mimeType:'image/png',buffer:Buffer.from('rojo')},
        {name:'negro.png',mimeType:'image/png',buffer:Buffer.from('negro')},
        {name:'naranja.png',mimeType:'image/png',buffer:Buffer.from('naranja')},
    ]);
    await dialog.getByLabel('Precio de venta (foto 1)').fill('43');await dialog.getByLabel('Color (foto 1)').fill('Rojo');
    await dialog.getByLabel('Precio de venta (foto 2)').fill('34');await dialog.getByLabel('Color (foto 2)').fill('Negro');
    await dialog.getByLabel('Precio de venta (foto 3)').fill('32');await dialog.getByLabel('Color (foto 3)').fill('Naranja');
    await dialog.getByRole('button',{name:'Guardar productos',exact:true}).click();
    await expect(dialog).not.toBeVisible();

    // Created 1 single product with 3 variants and stayed on catalog grid (NOT detail view)
    expect(catalog.creates).toHaveLength(1);
    expect(catalog.creates[0].clothing_variants).toHaveLength(3);
    expect(catalog.uploads).toHaveLength(3);
    await expect(page.getByRole('button',{name:'← Volver a productos',exact:true})).toHaveCount(0);

    // Card shows slide controls and updates price & color per image
    const card=page.locator('.product-card').first();
    await expect(card).toContainText('43.00');
    await expect(card).toContainText('Color: Rojo');
    await expect(card).toContainText('1 / 3');
    await card.getByRole('button',{name:'Foto siguiente de Audifonos',exact:true}).click();
    await expect(card).toContainText('34.00');
    await expect(card).toContainText('Color: Negro');
    await expect(card).toContainText('2 / 3');
    await card.getByRole('button',{name:'Foto siguiente de Audifonos',exact:true}).click();
    await expect(card).toContainText('32.00');
    await expect(card).toContainText('Color: Naranja');
    await expect(card).toContainText('3 / 3');
    if((page.viewportSize()?.width||0)>=1024){
        await page.screenshot({path:testInfo.outputPath('catalog-card-carousel.png')});
    }
});

test('detail view filters photos by selected variant and delete requires double confirmation when sales exist',async({page,businessState})=>{
    const svgImg=(color:string)=>`data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="160" height="160" fill="${color}"/></svg>`)}`;
    let deleted=false;
    const product={
        id:88,name:'Bolso Demo',description:null,product_type:'physical',unit_code:'unit',track_stock:true,status:'active',version:1,
        category:{id:1,name:'Carteras'},brand:null,primary_sku:'BOL-R',variants_count:2,active_variants_count:2,min_sale_price:'30.0000',max_sale_price:'35.0000',
        images:[
            {id:1,variant_id:201,variant_sale_price:'30.0000',variant_label:'Color: Rojo',alt_text:'Rojo 1',is_primary:true,sort_order:0,renditions:{thumb:{url:svgImg('#c62828'),width:80,height:80},card:{url:svgImg('#c62828'),width:160,height:160},detail:{url:svgImg('#c62828'),width:320,height:320}}},
            {id:2,variant_id:201,variant_sale_price:'30.0000',variant_label:'Color: Rojo',alt_text:'Rojo 2',is_primary:false,sort_order:1,renditions:{thumb:{url:svgImg('#e53935'),width:80,height:80},card:{url:svgImg('#e53935'),width:160,height:160},detail:{url:svgImg('#e53935'),width:320,height:320}}},
            {id:3,variant_id:202,variant_sale_price:'35.0000',variant_label:'Color: Azul',alt_text:'Azul 1',is_primary:false,sort_order:2,renditions:{thumb:{url:svgImg('#1565c0'),width:80,height:80},card:{url:svgImg('#1565c0'),width:160,height:160},detail:{url:svgImg('#1565c0'),width:320,height:320}}},
        ],
    };
    const variants=[
        {id:201,product_id:88,sku:'BOL-R',barcode:null,sale_price:'30.0000',cost_price:'15.0000',description:null,is_active:true,version:1,attribute_values:[{id:1,attribute_id:1,attribute_name:'Color',value:'Rojo'}]},
        {id:202,product_id:88,sku:'BOL-A',barcode:null,sale_price:'35.0000',cost_price:'16.0000',description:null,is_active:true,version:1,attribute_values:[{id:2,attribute_id:1,attribute_name:'Color',value:'Azul'}]},
    ];
    await page.route('**/api/v1/business/products**',async route=>{
        const req=route.request();const url=new URL(req.url());
        if(req.method()==='DELETE'&&url.pathname.endsWith('/products/88')){
            const body=req.postDataJSON()||{};
            if(!body.confirm_with_sales){
                return route.fulfill({status:409,json:{message:'Este producto tiene 3 registros de venta o pedidos asociados.',data:{requires_double_confirmation:true,sales_records_count:3}}});
            }
            deleted=true;
            return route.fulfill({status:200,json:{data:{id:88,deleted:true,sales_records_count:3}}});
        }
        if(req.method()==='GET'&&url.pathname.endsWith('/products/88')){
            return route.fulfill({json:{data:{product,variants:{data:variants,current_page:1,last_page:1,total:2}}}});
        }
        if(req.method()==='GET'){
            return route.fulfill({json:{data:deleted?[]:[product],meta:{current_page:1,last_page:1,total:deleted?0:1}}});
        }
        return route.continue();
    });

    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Catálogo',exact:true}).click();
    await page.getByRole('button',{name:'Ver Bolso Demo',exact:true}).click();

    // Variant filter shows 2 photos for Rojo and 1 photo for Azul
    const filterGroup=page.getByRole('group',{name:'Filtrar fotos por variante'});
    await filterGroup.getByRole('button',{name:'Color: Rojo (2)',exact:true}).click();
    await expect(page.locator('.detail-gallery .slide-counter')).toHaveText('1 / 2');
    await filterGroup.getByRole('button',{name:'Color: Azul (1)',exact:true}).click();
    await expect(page.locator('.detail-main-img')).toHaveAttribute('alt','Azul 1');

    // Double confirmation delete flow when sales exist
    await page.getByRole('button',{name:'Eliminar',exact:true}).click();
    const dlg=page.getByRole('dialog',{name:'Eliminar producto'});
    await dlg.getByRole('button',{name:'Sí, eliminar',exact:true}).click();
    const dlg2=page.getByRole('dialog',{name:'Confirmar eliminación con ventas'});
    await expect(dlg2).toContainText('3 registros de venta o pedidos');
    await dlg2.getByRole('button',{name:'Confirmar eliminación definitiva',exact:true}).click();
    await expect(dlg2).not.toBeVisible();
    expect(deleted).toBe(true);
    expect(businessState.writes).toBe(0);
});
