import { test as base, expect, business, permissions, stores } from './tablet.fixture';
import type { SalesCustomer } from '../../resources/js/sales';

type State = { customer:SalesCustomer; writes:any[]; lost:boolean; conflict:boolean; readonly:boolean };
const test = base.extend<{customers:State}>({
    customers:async ({page,businessState},use) => {
        const state:State = {customer:{id:1,full_name:'María QA',phone:'+1 202-555-0101',email:'qa@example.test',address:'Dirección QA',notes:'Notas originales',status:'active',lock_version:0},writes:[],lost:false,conflict:false,readonly:false};
        const replies = new Map<string,SalesCustomer>();
        const access = () => [...permissions.filter(p => !state.readonly || p !== 'customers.manage'),'customers.view',...(!state.readonly ? ['customers.manage','sales.view'] : [])];
        await page.route('**/api/v1/session',route=>route.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business,roles:['QA'],permissions:access()}}}));
        await page.route('**/api/v1/business/workspace',route=>route.fulfill({json:{data:{business,stores,permissions:access()}}}));
        await page.route('**/api/v1/business/stores/**',async route => {
            const url=new URL(route.request().url()); const path=url.pathname.replace(/^\/api\/v1\/business\/stores\/\d+/,''); const method=route.request().method();
            if(path==='/customers' && method==='GET') {
                const status=url.searchParams.get('status') || 'active';
                const visible=(status==='all' || state.customer.status===status) && state.customer.full_name.toLowerCase().includes((url.searchParams.get('q') || '').toLowerCase());
                return route.fulfill({json:{data:visible ? [state.customer] : [],meta:{current_page:1,last_page:1,total:visible ? 1 : 0}}});
            }
            if(path==='/customers/1' && method==='GET') return route.fulfill({json:{data:state.customer}});
            if(path==='/customers/1/summary') return route.fulfill({json:{data:{customer:state.customer,pending_total:'9.0000',purchase_count:1,last_purchase_at:null,open_baskets:[]}}});
            if(path.startsWith('/customers/requests/')) {
                const key=path.split('/').pop()!;
                return replies.has(key) ? route.fulfill({json:{data:{customer:state.customer,replay:true}}}) : route.fulfill({status:404,json:{message:'Sin confirmación.'}});
            }
            if((path==='/customers/1' || path==='/customers/1/status') && method==='PATCH') {
                const payload=route.request().postDataJSON(); state.writes.push({path,payload});
                if(state.conflict || payload.version!==state.customer.lock_version) return route.fulfill({status:409,json:{message:'Otro usuario cambió la ficha.'}});
                if(!replies.has(payload.request_key)) {
                    if(path.endsWith('/status')) state.customer.status=payload.status;
                    else for(const field of ['full_name','phone','email','address','notes'] as const) (state.customer as any)[field]=payload[field];
                    state.customer.lock_version!++; replies.set(payload.request_key,structuredClone(state.customer));
                }
                if(state.lost) { state.lost=false; return route.abort('failed'); }
                return route.fulfill({json:{data:{customer:state.customer,replay:false}}});
            }
            return route.fallback();
        });
        await use(state); expect(businessState.writes).toBe(0);
    }
});
async function openCustomers(page:import('@playwright/test').Page) {
    await page.goto('/');
    if((page.viewportSize()?.width || 0)<768) await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
    await page.getByRole('button',{name:'Clientes',exact:true}).click();
    await page.getByRole('button',{name:/María QA/}).click();
}

test('staff authorizes variants and creates a customer-bound order link across rotation',async({page,customers})=>{
    const access=[...permissions,'customers.view','customers.manage','sales.view','sales.create'];
    await page.route('**/api/v1/session',route=>route.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business,roles:['QA'],permissions:access}}}));
    await page.route('**/api/v1/business/workspace',route=>route.fulfill({json:{data:{business,stores,permissions:access}}}));
    const bodies:any[]=[];
    await page.route('**/api/v1/business/stores/*/order-request-links*',async route=>{
        if(route.request().method()==='GET')return route.fulfill({json:{data:[]}});
        bodies.push(route.request().postDataJSON());return route.fulfill({status:201,json:{data:{link:{id:5,status:'active',expires_at:'2026-11-21T12:00:00Z'},path:'/s/'+'c'.repeat(64),replay:false}}});
    });
    await page.route('**/api/v1/business/stores/*/sales/catalog?*',route=>route.fulfill({json:{data:[{id:1,name:'Bolso autorizado',image:{renditions:{card:{url:'/media/bolso.webp'}}},variants:[{id:7,sku:'REQUEST-QA',price:'25.0000',attributes:[],available:'10.0000'}]}],meta:{current_page:1,last_page:1,total:1}}}));
    await openCustomers(page);await page.getByRole('button',{name:'Enlace para nuevo pedido',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Enlace para nuevo pedido',exact:true});
    await expect(dialog.getByAltText('Foto de Bolso autorizado')).toHaveAttribute('src','/media/bolso.webp');
    await dialog.getByRole('button',{name:/REQUEST-QA/}).click();
    await dialog.getByLabel('Envío acordado').fill('5');
    await page.setViewportSize({width:1024,height:380});
    await expect(dialog).toContainText('1 variantes autorizadas');
    await dialog.getByRole('button',{name:'Generar enlace de pedido',exact:true}).click();
    await expect(dialog.getByLabel('Enlace de pedido para el cliente')).toHaveValue(new RegExp('/s/c{64}$'));
    expect(bodies).toHaveLength(1);expect(bodies[0].variant_ids).toEqual([7]);expect(bodies[0].customer_id).toBe(1);expect(bodies[0].shipping).toBe('5');
    expect(customers.writes).toHaveLength(0);
});

test('customer view menu opens the generated customer link without confirming a purchase',async({page,customers})=>{
    const access=[...permissions,'customers.view','sales.view','sales.create'];
    await page.route('**/api/v1/session',route=>route.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business,roles:['QA'],permissions:access}}}));
    await page.route('**/api/v1/business/workspace',route=>route.fulfill({json:{data:{business,stores,permissions:access}}}));
    const posts:any[]=[];
    await page.route('**/api/v1/business/stores/*/order-request-links*',route=>{
        if(route.request().method()==='GET')return route.fulfill({json:{data:[]}});
        posts.push(route.request().postDataJSON());
        return route.fulfill({status:201,json:{data:{link:{id:5,status:'active',expires_at:'2026-11-21T12:00:00Z'},path:'/s/'+'c'.repeat(64)}}});
    });
    await page.route('**/api/v1/business/stores/*/sales/catalog?*',route=>route.fulfill({json:{data:[{id:1,name:'Bolso',variants:[{id:7,sku:'REQUEST-QA',price:'25.0000',attributes:[],available:'10.0000'}]}]}}));
    await page.goto('/app/vista-cliente');
    await expect(page.getByRole('heading',{name:'Vista del cliente'}).first()).toBeVisible();
    await page.getByRole('button',{name:/María QA/}).click();
    await expect(page.getByText(/se crea un apartado en el sistema/)).toBeVisible();
    await page.getByRole('button',{name:'Enlace para nuevo pedido',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Enlace para nuevo pedido'});
    await dialog.getByRole('button',{name:/REQUEST-QA/}).click();
    await dialog.getByRole('button',{name:'Generar enlace de pedido'}).click();
    const link=dialog.getByRole('link',{name:'Abrir vista del cliente'});
    await expect(link).toHaveAttribute('href',new RegExp('/s/c{64}$'));
    await expect(link).toHaveAttribute('target','_blank');
    expect(posts).toHaveLength(1);
    expect(posts[0].customer_id).toBe(1);
    expect(posts[0].variant_ids).toEqual([7]);
    expect(customers.writes).toHaveLength(0);
});

test('customer profile edits survive rotation and archive/reactivate preserves balances',async ({page,customers}) => {
    await openCustomers(page);
    await page.getByRole('button',{name:'Editar cliente',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Editar cliente',exact:true});
    await dialog.getByLabel('Nombre completo',{exact:true}).fill('María Actualizada');
    await dialog.getByLabel('Notas del cliente',{exact:true}).fill('Notas editadas sin perder borrador');
    await page.setViewportSize({width:1024,height:380});
    await expect(dialog.getByLabel('Nombre completo',{exact:true})).toHaveValue('María Actualizada');
    await dialog.getByRole('button',{name:'Guardar ficha',exact:true}).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('María Actualizada');
    await page.getByRole('button',{name:'Archivar cliente',exact:true}).click();
    const archive=page.getByRole('dialog',{name:'Archivar cliente',exact:true});
    await expect(archive).toContainText('Sus deudas, pedidos y documentos se conservan');
    await archive.getByRole('button',{name:'Confirmar archivo',exact:true}).click();
    await expect(archive).not.toBeVisible();
    await page.getByRole('combobox',{name:'Estado de clientes'}).selectOption('archived');
    await expect(page.locator('.sales-customer')).toHaveCount(1);
    await page.getByRole('button',{name:'Reactivar cliente',exact:true}).click();
    await page.getByRole('button',{name:'Confirmar reactivación',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Reactivar cliente',exact:true})).not.toBeVisible();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('Cliente reactivado.');
    await page.getByRole('combobox',{name:'Estado de clientes'}).selectOption('active');
    await expect(page.locator('.sales-customer')).toHaveCount(1);
    expect(customers.writes).toHaveLength(3);
    expect(customers.customer.status).toBe('active');
    await expect(page.locator('.customer-summary')).toContainText('9.00');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('customer conflict retains draft and explicit refresh loads the current version',async ({page,customers}) => {
    await openCustomers(page);
    await page.getByRole('button',{name:'Editar cliente',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Editar cliente',exact:true});
    await dialog.getByLabel('Nombre completo',{exact:true}).fill('Mi borrador');
    customers.conflict=true; customers.customer.full_name='Cambio de otro usuario'; customers.customer.lock_version=1;
    await dialog.getByRole('button',{name:'Guardar ficha',exact:true}).click();
    await expect(dialog.getByRole('alert')).toContainText('Actualiza');
    await expect(dialog.getByLabel('Nombre completo',{exact:true})).toHaveValue('Mi borrador');
    await dialog.getByRole('button',{name:'Descartar cambios y actualizar',exact:true}).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('Cambio de otro usuario');
    await page.getByRole('button',{name:'Editar cliente',exact:true}).click();
    await expect(dialog.getByLabel('Nombre completo',{exact:true})).toHaveValue('Cambio de otro usuario');
    expect(customers.writes).toHaveLength(1);
});

test('lost archive response recovers on reload without submitting another change',async ({page,customers}) => {
    await openCustomers(page);
    customers.lost=true;
    await page.getByRole('button',{name:'Archivar cliente',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Archivar cliente',exact:true});
    await dialog.getByRole('button',{name:'Confirmar archivo',exact:true}).click();
    await expect(dialog.getByRole('button',{name:'Reintentar cambios',exact:true})).toBeVisible();
    await page.reload();
    // Client navigation is intentionally local; return to the module to recover its journal.
    if((page.viewportSize()?.width || 0)<768) await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
    await page.getByRole('button',{name:'Clientes',exact:true}).click();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('Operación recuperada. Se muestra la ficha actual.');
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('Archivado');
    expect(customers.writes).toHaveLength(1);
    expect(customers.customer.lock_version).toBe(1);
});

test('customer reader sees archived profiles without mutation controls',async ({page,customers}) => {
    customers.readonly=true;
    await openCustomers(page);
    await expect(page.getByRole('button',{name:'Editar cliente',exact:true})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Archivar cliente',exact:true})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'+ Nuevo cliente',exact:true})).toHaveCount(0);
    customers.customer.status='archived';
    await page.getByRole('combobox',{name:'Estado de clientes'}).selectOption('archived');
    await page.locator('.sales-customer').click();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('Archivado');
    expect(customers.writes).toHaveLength(0);
});

test('failed customer search retries the requested page and keeps the selected profile',async ({page,customers}) => {
    await openCustomers(page);
    const directory=page.getByRole('region',{name:'Seleccionar cliente'});
    let fail=true;
    const requests:number[]=[];
    await page.route('**/api/v1/business/stores/*/customers?*',async route => {
        const url=new URL(route.request().url());
        requests.push(Number(url.searchParams.get('page')));
        if(fail) return route.fulfill({status:503,json:{message:'Consulta temporalmente no disponible.'}});
        return route.fulfill({json:{data:[customers.customer],meta:{current_page:2,last_page:2,total:16}}});
    });
    // Initial page is successful; the next page fails, so retry must request page two.
    await page.route('**/api/v1/business/stores/*/customers?*',async route => {
        const url=new URL(route.request().url());
        if(url.searchParams.get('page')!=='1') return route.fallback();
        return route.fulfill({json:{data:[customers.customer],meta:{current_page:1,last_page:2,total:16}}});
    });
    await directory.getByLabel('Estado de clientes').selectOption('all');
    await directory.getByRole('button',{name:'Siguiente',exact:true}).click();
    await expect(directory.getByRole('alert')).toContainText('Consulta temporalmente no disponible.');
    await expect(directory.locator('.sales-customer')).toBeDisabled();
    await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('María QA');
    fail=false;
    await directory.getByRole('button',{name:'Reintentar búsqueda de clientes',exact:true}).click();
    await expect(directory.getByRole('alert')).toHaveCount(0);
    await expect(directory.locator('.catalog-pagination')).toContainText('2/2');
    await expect(directory.locator('.sales-customer')).toBeEnabled();
    expect(requests).toEqual([2,2]);
    expect(customers.writes).toHaveLength(0);
});

test('customer search disables previous results until the new query resolves',async ({page,customers}) => {
    await openCustomers(page);
    const directory=page.getByRole('region',{name:'Seleccionar cliente'});
    let release!:()=>void;
    const gate=new Promise<void>(resolve=>{release=resolve;});
    await page.route('**/api/v1/business/stores/*/customers?*',async route => {
        const url=new URL(route.request().url());
        if(url.searchParams.get('q')!=='Nadie') return route.fallback();
        await gate;
        return route.fulfill({json:{data:[],meta:{current_page:1,last_page:1,total:0}}});
    });
    try {
        await directory.getByRole('searchbox',{name:'Buscar cliente'}).fill('Nadie');
        await expect(directory.locator('.sales-customer')).toBeDisabled();
        await expect(directory.getByRole('status')).toContainText('Buscando clientes');
        await page.setViewportSize({width:768,height:1024});
        await expect(directory.getByRole('searchbox',{name:'Buscar cliente'})).toHaveValue('Nadie');
        release();
        await expect(directory.locator('.sales-customer')).toHaveCount(0);
        await expect(directory).toContainText('No hay clientes con esa búsqueda.');
        await expect(page.getByRole('region',{name:'Ficha del cliente'})).toContainText('María QA');
        expect(customers.writes).toHaveLength(0);
    } finally {release();}
});
