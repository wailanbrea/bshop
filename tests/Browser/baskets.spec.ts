import { test as base, expect, business, permissions, stores } from './tablet.fixture';
import type { SavedOrder } from '../../resources/js/sales';

const access=[...permissions,'sales.view','sales.create','sales.cancel','customers.view','customers.manage','payments.record','invoices.view','payments.reverse','returns.receive','returns.adjust','refunds.record','credits.apply','credits.reverse','returns.reverse','refunds.reverse','adjustments.reverse'];
const test=base.extend<{board:{orders:SavedOrder[];writes:any[];lost:boolean;conflict:boolean;whatsapp:boolean;sends:number}}>({
    board:async({page,businessState},use)=>{
        const customers=[{id:1,full_name:'María Demo',phone:'+1 202-555-0101',email:null,address:null,notes:'Ficticio',lock_version:0},{id:2,full_name:'Ana Demo',phone:'+1 202-555-0103',email:null,address:null,notes:null,lock_version:0}];
        const make=(id:number):SavedOrder=>({id,store_id:1,warehouse_id:1,customer_id:id,customer_snapshot:customers[id-1],customer_live:customers[id-1],order_kind:'reservation',basket_number:String(id),closed_at:null,shipped_at:null,status:'reserved',currency:'USD',lock_version:0,subtotal:id===1?'50.0000':'25.0000',discount_total:'0.0000',shipping_total:'0.0000',tax_total:'0.0000',grand_total:id===1?'50.0000':'25.0000',paid_total:id===1?'20.0000':'0.0000',pending_total:id===1?'30.0000':'25.0000',estimated_delivery:null,items:[{id,variant_id:1,product_name_snapshot:'Bolso Clásico',sku_snapshot:'BOL-QA',quantity:id===1?'2.0000':'1.0000',unit_price:'25.0000',line_total:id===1?'50.0000':'25.0000',image_url_snapshot:null,variant_snapshot:[]}],invoice:null,reservations:[]});
        const state={orders:[make(1),make(2)],writes:[] as any[],lost:false,conflict:false,whatsapp:false,sends:0};const replies=new Map<string,SavedOrder>();
        const raw=(s:string)=>{const[a,b='']=String(s).split('.');return BigInt(a)*10000n+BigInt(b.padEnd(4,'0'));};const fmt=(v:bigint)=>`${v/10000n}.${String(v%10000n).padStart(4,'0')}`;
        const quote=(body:any,order:SavedOrder)=>{const lines=body.lines.map((l:any)=>({...l,unit_price:l.variant_id===1?'25.0000':'15.0000',line_total:fmt(raw(l.quantity)*BigInt(l.variant_id===1?25:15))}));const sub=lines.reduce((s:bigint,l:any)=>s+raw(l.line_total),0n);const grand=sub+raw(body.shipping)-raw(body.discount);return {lines,subtotal:fmt(sub),grand_total:fmt(grand),shipping_total:fmt(raw(body.shipping)),discount_total:fmt(raw(body.discount)),tax_total:'0.0000',paid_total:order.paid_total,pending_total:fmt(grand-raw(order.paid_total)),currency:'USD',quote_hash:'c'.repeat(64)};};
        await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'Propietaria QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['Propietario'],permissions:access}}}));
        await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:access}}}));
        await page.route('**/api/v1/business/integrations/whatsapp/status',r=>r.fulfill({json:{data:{enabled:state.whatsapp,ready:state.whatsapp,reason:state.whatsapp?'Configurada':'WhatsApp está desactivado; es opcional.'}}}));
        await page.route('**/api/v1/business/integrations/whatsapp',r=>r.fulfill({json:{data:{enabled:false,ready:false,reason:'WhatsApp está desactivado; es opcional.',phone_number_id:'',template:'',language:'es',graph_version:'v23.0',body_fields:['customer_name','invoice_number','total'],has_access_token:false,has_app_secret:false,has_verify_token:false,webhook_url:'https://example.test/webhooks/whatsapp'}}}));
        await page.route('**/api/v1/business/stores/**',async route=>{
            const url=new URL(route.request().url()),path=url.pathname.replace(/^\/api\/v1\/business\/stores\/\d+/,''),method=route.request().method();
            if(path==='/baskets'&&method==='GET'){let orders=state.orders;const f=url.searchParams.get('filter')||'open',q=(url.searchParams.get('q')||'').toLowerCase();if(f==='open')orders=orders.filter(o=>!o.closed_at&&o.status!=='cancelled');if(f==='without_deposit')orders=orders.filter(o=>o.paid_total==='0.0000');if(f==='invoice_sent')orders=orders.filter(o=>['sent','delivered','read'].includes(o.whatsapp_delivery?.status||''));if(f==='paid')orders=orders.filter(o=>o.pending_total==='0.0000');if(f==='shipped')orders=orders.filter(o=>o.shipped_at);orders=orders.filter(o=>!q||o.customer_snapshot.full_name.toLowerCase().includes(q)||o.basket_number?.includes(q));return route.fulfill({json:{data:orders.map(o=>({id:o.id,number:o.basket_number,customer_name:o.customer_live?.full_name||o.customer_snapshot.full_name,phone:o.customer_snapshot.phone,items_count:o.items.length,total:o.grand_total,deposit:o.paid_total,pending:o.pending_total,currency:'USD',status:o.status,closed:!!o.closed_at,paid:o.pending_total==='0.0000',invoice_generated:!!o.invoice,whatsapp_status:o.whatsapp_delivery?.status,shipped:!!o.shipped_at,version:o.lock_version})),meta:{current_page:1,last_page:1,total:orders.length}}});}
            if(/^\/sales\/orders\/\d+$/.test(path))return route.fulfill({json:{data:state.orders.find(o=>o.id===Number(path.split('/').pop()))}});
            if(path.includes('/baskets/requests/'))return replies.has(path.split('/').pop()!)?route.fulfill({json:{data:{order:replies.get(path.split('/').pop()!),replay:true}}}):route.fulfill({status:404,json:{message:'Sin confirmar'}});
            const id=Number(path.split('/')[path.startsWith('/sales/orders/')?3:2]);const order=state.orders.find(o=>o.id===id);
            if(path.endsWith('/preview'))return route.fulfill({json:{data:quote(route.request().postDataJSON(),order!)}});
            if(/^\/baskets\/\d+$/.test(path)&&method==='PATCH'){const body=route.request().postDataJSON();state.writes.push(body);if(state.conflict)return route.fulfill({status:409,json:{message:'Otro usuario cambió el apartado.'}});const money=quote(body,order!);Object.assign(order!,money,{lock_version:order!.lock_version+1,items:money.lines.map((l:any,i:number)=>({id:l.id||10+i,variant_id:l.variant_id,product_name_snapshot:l.variant_id===1?'Bolso Clásico':'Aretes Dorados',sku_snapshot:l.variant_id===1?'BOL-QA':'ARE-QA',quantity:l.quantity,unit_price:l.unit_price,line_total:l.line_total,variant_snapshot:[],image_url_snapshot:null}))});replies.set(body.request_key,order!);if(state.lost){state.lost=false;return route.abort('failed');}return route.fulfill({json:{data:{order,replay:false}}});}
            if(path.endsWith('/number')){const body=route.request().postDataJSON();order!.basket_number=body.number;order!.lock_version++;return route.fulfill({json:{data:{order}}});}
            if(path.endsWith('/invoice')&&path.includes('/sales/orders/')){order!.invoice={id:100+id,invoice_number:`FAC-${id}`,currency:'USD',status:'partially_paid'};order!.closed_at='2026-11-13T12:00:00Z';order!.status='confirmed';order!.lock_version++;return route.fulfill({json:{data:{order,document:{status:'ready'}}}});}
            if(/\/invoices\/\d+\/whatsapp$/.test(path)){state.sends++;const o=state.orders.find(o=>o.invoice?.id===Number(path.split('/')[2]))!;o.whatsapp_delivery={id:'delivery-qa',status:'accepted',error_code:null};return route.fulfill({json:{data:o.whatsapp_delivery}});}
            if(path==='/sales/catalog')return route.fulfill({json:{data:[{id:2,name:'Aretes Dorados',track_stock:true,unit_code:'unit',category_id:1,image:null,variants:[{id:2,sku:'ARE-QA',price:'15.0000',available:'4.0000',attributes:[]}]}],meta:{current_page:1,last_page:1,total:1}}});
            if(path==='/customers')return route.fulfill({json:{data:customers,meta:{current_page:1,last_page:1,total:2}}});
            if(path.endsWith('/summary'))return route.fulfill({json:{data:{pending_total:'0.0000',purchase_count:0,open_baskets:[]}}});
            return route.fallback();
        });
        await use(state);expect(businessState.writes).toBe(0);
    }
});
async function board(page:import('@playwright/test').Page){await page.goto('/app/apartados');await expect(page.getByRole('heading',{name:'Tablero de Apartados',exact:true})).toBeVisible();await expect(page.locator('.basket-card')).toHaveCount(2);}

test('editing a saved basket selects clothing color before its available size',async({page,board:state})=>{
    await page.route('**/api/v1/business/stores/*/sales/catalog**',route=>route.fulfill({json:{data:[{id:9,name:'Blusa',track_stock:true,unit_code:'unit',category_id:1,image:null,variants:[
        {id:21,sku:'BLUSA-VS',price:'15.0000',available:'5.0000',attributes:[{name:'Color',value:'Verde'},{name:'Talla',value:'S'}]},
        {id:22,sku:'BLUSA-NM',price:'15.0000',available:'0.0000',attributes:[{name:'Color',value:'Negro'},{name:'Talla',value:'M'}]},
    ]}],meta:{current_page:1,last_page:1,total:1}}}));
    await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo'}).click();await page.getByLabel('Buscar artículo para agregar').fill('Blusa');
    const results=page.getByRole('region',{name:'Resultados de artículos'});await results.getByRole('button',{name:'Elegir color Negro (agotado)'}).click();await expect(results.getByRole('button',{name:'Elegir talla S (agotada)'})).toBeDisabled();await expect(results.getByRole('button',{name:'Elegir talla M (agotada)'})).toBeDisabled();
    await results.getByRole('button',{name:'Elegir color Verde'}).click();await expect(results.getByRole('button',{name:'Elegir talla M (agotada)'})).toBeDisabled();await results.getByRole('button',{name:'Elegir talla S'}).click();await results.getByRole('button',{name:'Agregar Blusa'}).click();
    await expect(page.getByRole('region',{name:'Detalle del apartado'})).toContainText('BLUSA-VS');expect(state.writes).toHaveLength(0);
});

for(const kind of ['adjustment','credit'] as const){
    const buttonName=kind==='adjustment'?'Descargar comprobante de ajuste':'Descargar comprobante de crédito #1';
    async function scenario(page:import('@playwright/test').Page,state:{orders:SavedOrder[]},existing=false){
        if(kind==='adjustment'){
            const finance=await financialRoutes(page,state);finance.adjusted=existing;await openFinancial(page);if(!existing)await approveFinancial(page);return finance;
        }
        const credit=await creditRoutes(page,state);credit.applied=existing;await openCreditOrder(page);
        if(!existing){const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true}).click();await dialog.getByLabel('Motivo de la aplicación').fill('Aplicar saldo antes de descargar comprobante');await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog).toContainText('Saldo a favor aplicado.');}
        return credit;
    }
    async function documents(page:import('@playwright/test').Page){
        const mock={failure:null as null|'prepare'|'download',calls:[] as string[]};const path=`/api/v1/business/stores/1/sales/orders/1/documents/${kind}/1`;
        await page.route(`**${path}`,route=>{const method=route.request().method();mock.calls.push(method+' '+new URL(route.request().url()).pathname);if(mock.failure===(method==='POST'?'prepare':'download'))return route.fulfill({status:503,json:{message:'Comprobante temporalmente no disponible.'}});return method==='POST'?route.fulfill({json:{data:{status:'ready',url:path,filename:kind==='adjustment'?'AJU-00000001.pdf':'CRE-00000001.pdf'}}}):route.fulfill({contentType:'application/pdf',body:'%PDF-1.4\nPrivate financial fixture'});});return mock;
    }
    test(`${kind} private PDF downloads repeatedly without repeating its financial operation`,async({page,board:state})=>{
        const document=await documents(page);const operation=await scenario(page,state);const button=page.getByRole('button',{name:buttonName,exact:true});await expect(button).toBeEnabled();const box=await button.boundingBox();expect(box!.height).toBeGreaterThanOrEqual(44);expect(box!.width).toBeGreaterThanOrEqual(44);
        for(let i=0;i<2;i++){const downloaded=page.waitForEvent('download');await button.click();expect((await downloaded).suggestedFilename()).toBe(kind==='adjustment'?'AJU-00000001.pdf':'CRE-00000001.pdf');await expect(button).toBeEnabled();}
        expect(document.calls.map(c=>c.split(' ')[0])).toEqual(['POST','GET','POST','GET']);expect(operation.posts).toHaveLength(1);expect(state.writes).toHaveLength(0);
    });
    test(`${kind} PDF preparation and download failures retry the same document without repeating money`,async({page,board:state})=>{
        const document=await documents(page);const operation=await scenario(page,state);const button=page.getByRole('button',{name:buttonName,exact:true});const dialog=page.getByRole('dialog',{name:kind==='adjustment'?'Conciliación de devolución':'Aplicar saldo a favor',exact:true});const downloads:unknown[]=[];page.on('download',d=>downloads.push(d));
        document.failure='prepare';await button.click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(button).toBeEnabled();expect(downloads).toHaveLength(0);
        document.failure='download';await button.click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(button).toBeEnabled();expect(downloads).toHaveLength(0);
        document.failure=null;const downloaded=page.waitForEvent('download');await button.click();expect((await downloaded).suggestedFilename()).toContain('.pdf');await expect(dialog.getByRole('alert')).toHaveCount(0);
        expect(new Set(document.calls.map(c=>c.split(' ')[1])).size).toBe(1);expect(document.calls.map(c=>c.split(' ')[0])).toEqual(['POST','POST','GET','POST','GET']);expect(operation.posts).toHaveLength(1);expect(state.writes).toHaveLength(0);
    });
    test(`${kind} history hides PDF action without invoice permission`,async({page,board:state})=>{
        const document=await documents(page);const readonly=access.filter(p=>p!=='invoices.view');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));
        const operation=await scenario(page,state,true);await expect(page.getByRole('button',{name:buttonName,exact:true})).toHaveCount(0);expect(document.calls).toHaveLength(0);expect(operation.posts).toHaveLength(0);
    });
}

async function creditRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const order=state.orders[0];Object.assign(order,{invoice:{id:101,invoice_number:'FAC-1-101',currency:'USD',status:'partially_paid'},closed_at:'2026-11-13T12:00:00Z',status:'confirmed',gross_received:'20.0000'});
    const mock={posts:[] as any[],lost:false,conflict:false,applied:false,reversed:false};const replies=new Set<string>();let reason='';
    await page.route(/\/sales\/orders\/1\/credits(?:\/.*)?$/,async route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/requests/'))return replies.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,credit_application_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:{order_id:1,version:order.lock_version,review_hash:(order.lock_version===0?'a':'b').repeat(64),currency:'USD',invoice_number:'FAC-1-101',application_allowed:true,return_pending:false,balance:{pending_total:order.pending_total,paid_total:order.paid_total},sources:mock.applied&&!mock.reversed?[]:[{payment_id:77,origin_order_id:99,origin_number:99,method:'cash',available:'10.0000',pools:[{order_id:99,available:'10.0000'}]}],history:mock.applied?[{id:1,payment_id:77,origin_order_id:99,amount:'10.0000',reason,actor_name:'QA',reversal:mock.reversed?{id:1,reason:'Corrección de crédito aplicado por error',actor_name:'QA',created_at:'2026-11-15 12:00:00'}:null}]:[]}}});
        const body=route.request().postDataJSON();mock.posts.push(body);
        if(!replies.has(body.request_key)){
            if(mock.conflict||body.version!==order.lock_version){if(mock.conflict){mock.conflict=false;order.lock_version++;}return route.fulfill({status:409,json:{message:'El saldo del crédito cambió. Actualiza la revisión.'}});}
            order.lock_version++;order.paid_total='30.0000';order.pending_total='20.0000';order.credit_received_total='10.0000';mock.applied=true;reason=body.reason;replies.add(body.request_key);
        }
        if(mock.lost){mock.lost=false;return route.abort('failed');}
        return route.fulfill({json:{data:{order,credit_application_id:1,replay:false}}});
    });return mock;
}
async function openCreditOrder(page:import('@playwright/test').Page){await openReturnOrder(page);await page.getByRole('button',{name:'Saldo a favor del cliente',exact:true}).click();await expect(page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true})).toBeVisible();}

async function creditReversalRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const credit=await creditRoutes(page,state);credit.applied=true;const order=state.orders[0];Object.assign(order,{paid_total:'30.0000',pending_total:'20.0000',credit_received_total:'10.0000',lock_version:1});
    const mock={posts:[] as any[],reads:0,lost:false,conflict:false,blocked:false};const completed=new Set<string>();
    await page.route(/\/credits\/1\/reversal(?:\/requests\/[a-f0-9-]+)?$/,async route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/requests/'))return completed.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,credit_application_id:1,reversal_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET'){mock.reads++;return route.fulfill({json:{data:{application_id:1,version:order.lock_version,review_hash:(order.lock_version===1?'a':'b').repeat(64),currency:'USD',amount:'10.0000',payment_id:77,invoice_number:'FAC-1-101',pending_after:'30.0000',allowed:!mock.blocked&&!credit.reversed,balance:{pending_total:order.pending_total,paid_total:order.paid_total}}}});}
        const body=route.request().postDataJSON();mock.posts.push(body);
        if(mock.conflict){mock.conflict=false;order.lock_version++;return route.fulfill({status:409,json:{message:'El pedido cambió. Actualiza la revisión.'}});}
        if(!completed.has(body.request_key)){credit.reversed=true;Object.assign(order,{paid_total:'20.0000',pending_total:'30.0000',credit_received_total:'0.0000',lock_version:order.lock_version+1});completed.add(body.request_key);}
        if(mock.lost){mock.lost=false;return route.abort('failed');}return route.fulfill({json:{data:{order,credit_application_id:1,reversal_id:1,replay:false}}});
    });return mock;
}
async function openCreditReversal(page:import('@playwright/test').Page){await openCreditOrder(page);await page.getByRole('button',{name:'Revertir aplicación de crédito #1',exact:true}).click();await expect(page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true})).toBeVisible();}

test('credit reversal keeps its reason on rotation and restores debt without new cash or stock',async({page,board:state})=>{
    const reversal=await creditReversalRoutes(page,state);await openCreditReversal(page);const dialog=page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true});await dialog.getByLabel('Motivo de la reversión de crédito').fill('Corrección de crédito aplicado por error');await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Motivo de la reversión de crédito')).toHaveValue('Corrección de crédito aplicado por error');await dialog.getByRole('button',{name:'Confirmar reversión de crédito',exact:true}).click();await expect(dialog).toContainText('Aplicación revertida. Se restauró el crédito de origen');await dialog.getByRole('button',{name:'Volver al historial de crédito',exact:true}).click();await expect(page.getByRole('region',{name:'Aplicaciones de crédito anteriores',exact:true})).toContainText('Aplicación revertida');await expect(page.getByRole('button',{name:'Revertir aplicación de crédito #1',exact:true})).toHaveCount(0);expect(reversal.posts).toHaveLength(1);expect(state.orders[0].gross_received).toBe('20.0000');expect(state.orders[0].pending_total).toBe('30.0000');expect(state.writes).toHaveLength(0);
});
test('lost credit reversal recovers by GET on returning without repeating compensation',async({page,board:state})=>{
    const reversal=await creditReversalRoutes(page,state);reversal.lost=true;await openCreditReversal(page);const dialog=page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true});await dialog.getByLabel('Motivo de la reversión de crédito').fill('Corrección de crédito aplicado por error');await dialog.getByRole('button',{name:'Confirmar reversión de crédito',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar reversión de crédito',exact:true})).toBeEnabled();await page.reload();await openReturnOrder(page);await expect(page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true})).toContainText('Reversión de crédito recuperada sin repetir movimientos.');expect(reversal.posts).toHaveLength(1);
});
test('stale credit reversal review retains the reason and refreshes before retrying',async({page,board:state})=>{
    const reversal=await creditReversalRoutes(page,state);reversal.conflict=true;await openCreditReversal(page);const dialog=page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true});await dialog.getByLabel('Motivo de la reversión de crédito').fill('Corrección de crédito aplicado por error');await dialog.getByRole('button',{name:'Confirmar reversión de crédito',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la reversión de crédito')).toHaveValue('Corrección de crédito aplicado por error');await dialog.getByRole('button',{name:'Actualizar revisión de reversión',exact:true}).click();await dialog.getByRole('button',{name:'Confirmar reversión de crédito',exact:true}).click();await expect(dialog).toContainText('Aplicación revertida.');expect(reversal.posts).toHaveLength(2);expect(reversal.posts[1].version).toBe(2);expect(reversal.posts[1].review_hash).toBe('b'.repeat(64));
});
test('credit reversal is absent without its administrative permission',async({page,board:state})=>{
    const reversal=await creditReversalRoutes(page,state);const readonly=access.filter(p=>p!=='credits.reverse');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));await openCreditOrder(page);await expect(page.getByRole('region',{name:'Aplicaciones de crédito anteriores',exact:true})).toContainText('US$ 10.00');await expect(page.getByRole('button',{name:'Revertir aplicación de crédito #1',exact:true})).toHaveCount(0);expect(reversal.reads).toBe(0);expect(reversal.posts).toHaveLength(0);
});
test('consumed credit review blocks its compensation without posting money',async({page,board:state})=>{
    const reversal=await creditReversalRoutes(page,state);reversal.blocked=true;await openCreditReversal(page);const dialog=page.getByRole('dialog',{name:'Revertir aplicación de crédito',exact:true});await dialog.getByLabel('Motivo de la reversión de crédito').fill('Corrección de crédito aplicado por error');await expect(dialog).toContainText('requiere conciliación adicional.');await expect(dialog.getByRole('button',{name:'Confirmar reversión de crédito',exact:true})).toBeDisabled();expect(reversal.posts).toHaveLength(0);
});

test('credit selection and amount survive rotation and reduce debt without new cash',async({page,board:state})=>{
    const credit=await creditRoutes(page,state);await openCreditOrder(page);const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true}).click();await dialog.getByLabel('Motivo de la aplicación').fill('Aplicar saldo del cliente al pedido');await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Importe de crédito a aplicar')).toHaveValue('10.0000');await expect(dialog.getByLabel('Motivo de la aplicación')).toHaveValue('Aplicar saldo del cliente al pedido');
    await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog).toContainText('Saldo a favor aplicado. No se registró un nuevo cobro.');await expect(dialog.getByRole('region',{name:'Aplicaciones de crédito anteriores'})).toContainText('US$ 10.00');
    expect(credit.posts).toHaveLength(1);expect(state.orders[0].paid_total).toBe('30.0000');expect(state.orders[0].gross_received).toBe('20.0000');expect(state.orders[0].pending_total).toBe('20.0000');expect(state.writes).toHaveLength(0);
});

test('lost credit application recovers by GET on returning to the order without duplicate debit',async({page,board:state})=>{
    const credit=await creditRoutes(page,state);credit.lost=true;await openCreditOrder(page);const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true}).click();await dialog.getByLabel('Motivo de la aplicación').fill('Aplicación de saldo pendiente de respuesta');await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar aplicación de crédito',exact:true})).toBeEnabled();
    await page.reload();await page.getByRole('button',{name:'Todos',exact:true}).click();await expect(page.locator('.basket-card')).toHaveCount(2);await page.locator('.basket-card').first().click();await expect(page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true})).toContainText('Aplicación de crédito recuperada sin repetir movimientos.');expect(credit.posts).toHaveLength(1);
});

test('stale credit review preserves reason and uses refreshed version and hash',async({page,board:state})=>{
    const credit=await creditRoutes(page,state);credit.conflict=true;await openCreditOrder(page);const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true}).click();await dialog.getByLabel('Motivo de la aplicación').fill('Saldo revisado con datos actuales');await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la aplicación')).toHaveValue('Saldo revisado con datos actuales');await dialog.getByRole('button',{name:'Actualizar revisión de crédito',exact:true}).click();await expect(dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true})).toBeEnabled();await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog).toContainText('Saldo a favor aplicado. No se registró un nuevo cobro.');expect(credit.posts).toHaveLength(2);expect(credit.posts[1].version).toBe(1);expect(credit.posts[1].review_hash).toBe('b'.repeat(64));
});

test('sales viewer can inspect released credit without application controls',async({page,board:state})=>{
    const credit=await creditRoutes(page,state);const readonly=access.filter(p=>p!=='credits.apply');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));
    await openCreditOrder(page);const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await expect(dialog).toContainText('US$ 10.00');await expect(dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true})).toHaveCount(0);expect(credit.posts).toHaveLength(0);
});

async function financialRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]},prepared=false){
    const order=state.orders[0];Object.assign(order,{invoice:{id:101,invoice_number:'FAC-1-101',currency:'USD',status:'paid'},closed_at:'2026-11-13T12:00:00Z',status:'fulfilled',paid_total:'50.0000',pending_total:'0.0000',lock_version:1,return_pending:true});
    const mock={posts:[] as any[],adjusted:prepared,refunded:prepared,lostRefund:false,conflict:false,correctedRefund:false,compensated:false,adjustmentId:1};const replies=new Map<string,string>();if(prepared)Object.assign(order,{return_pending:false,net_total:'25.0000',original_total:'50.0000',return_credit_total:'25.0000',gross_received:'50.0000',paid_total:'25.0000',pending_total:'0.0000',credit_available:'0.0000',refunded_total:'25.0000'});
    await page.route(/\/sales\/orders\/1\/returns(?:\/.*)?$/,async route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/financial/requests/')){const kind=replies.get(path.split('/').pop()!);return kind?route.fulfill({json:{data:{order,adjustment_id:1,refund_id:kind==='refund'?1:undefined,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});}
        if(path.endsWith('/financial')){
            const effectiveRefund=mock.refunded&&!mock.correctedRefund;
            const original={id:1,amount:'25.0000',reason:'Ajuste original revisado',actor_name:'QA',created_at:'2026-11-15 12:00:00',reversal:mock.compensated?{id:1,reason:'Compensar ajuste económico registrado por error',actor_name:'QA',created_at:'2026-11-15 13:00:00'}:null};
            const history=mock.compensated?(mock.adjusted?[{...original,id:mock.adjustmentId,reason:'Ajuste económico corregido',reversal:null},original]:[original]):mock.adjusted?[original]:[];
            const data=mock.adjusted?{state:'adjusted',version:order.lock_version,currency:'USD',invoice_number:'FAC-1-101',adjustment:{id:mock.adjustmentId,amount:'25.0000',credit_released:'25.0000',reason:'Ajuste revisado',actor_name:'QA'},balance:{net_total:'25.0000',pending_total:'0.0000',credit_available:effectiveRefund?'0.0000':'25.0000',refunded_total:effectiveRefund?'25.0000':'0.0000'},refund_sources:[{payment_id:1,released:'25.0000',refunded:effectiveRefund?'25.0000':'0.0000',available_credit:effectiveRefund?'0.0000':'25.0000',max_refund:effectiveRefund?'0.0000':'25.0000',method:'cash'}],refunds:mock.refunded?[{id:1,amount:'25.0000',method:'cash',external_reference:null,actor_name:'QA',reversal:mock.correctedRefund?{id:1,reason:'Registro erróneo sin entrega de dinero',actor_name:'QA',created_at:'2026-11-15 14:00:00'}:null}]:[]}:{state:'received',proposal:{version:order.lock_version,currency:'USD',invoice_number:'FAC-1-101',lines:[{name:'Bolso Clásico',quantity:'1.0000',credit_amount:'25.0000'}],shipping_credit:'0.0000',shipping_available:'0.0000',merchandise_credit:'25.0000',amount:'25.0000',net_after:'25.0000',credit_released:'25.0000',debt_reduction:'0.0000',review_hash:(order.lock_version===1?'a':'b').repeat(64)}};
            return route.fulfill({json:{data:{...data,history}}});
        }
        if(route.request().method()==='GET')return route.fulfill({json:{data:{order_id:1,version:order.lock_version,invoice:{id:101,number:'FAC-1-101'},warehouse_name:'Almacén QA',review_hash:'a'.repeat(64),return_pending:!mock.adjusted,lines:[{invoice_item_id:1,name:'Bolso Clásico',sku:'BOL-QA',delivered_quantity:'2.0000',received_quantity:'1.0000',available_quantity:'1.0000',track_stock:true}],history:[{id:1,received_at:'2026-11-14 12:00:00',actor_name:'QA',reason:'Devolución recibida',status:mock.adjusted?'adjusted':'received',items:[{name:'Bolso Clásico',quantity:'1.0000',restock:true}]}]}}});
        const body=route.request().postDataJSON();const kind=path.endsWith('/adjust')?'adjust':'refund';mock.posts.push({kind,body});
        if(mock.conflict){mock.conflict=false;order.lock_version++;return route.fulfill({status:409,json:{message:'Los abonos cambiaron. Revisa la propuesta.'}});}
        if(!replies.has(body.request_key)){order.lock_version++;if(kind==='adjust'){mock.adjusted=true;if(mock.compensated)mock.adjustmentId=2;Object.assign(order,{return_pending:false,net_total:'25.0000',original_total:'50.0000',return_credit_total:'25.0000',paid_total:'25.0000',credit_available:'25.0000',refunded_total:'0.0000'});}else{mock.refunded=true;order.refunded_total='25.0000';order.credit_available='0.0000';}replies.set(body.request_key,kind);}
        if(kind==='refund'&&mock.lostRefund){mock.lostRefund=false;return route.abort('failed');}
        return route.fulfill({json:{data:{order,adjustment_id:1,refund_id:kind==='refund'?1:undefined,replay:false}}});
    });return mock;
}
async function openFinancial(page:import('@playwright/test').Page){await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();await page.getByRole('button',{name:'Conciliar recepción #1',exact:true}).click();await expect(page.getByRole('dialog',{name:'Conciliación de devolución',exact:true})).toBeVisible();}
async function approveFinancial(page:import('@playwright/test').Page){const dialog=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await dialog.getByLabel('Motivo del ajuste').fill('Crédito autorizado tras revisar la devolución');await dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true}).click();await expect(dialog).toContainText('Ajuste #1');}

async function adjustmentUndoRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const finance=await financialRoutes(page,state);finance.adjusted=true;const order=state.orders[0];Object.assign(order,{return_pending:false,net_total:'25.0000',original_total:'50.0000',return_credit_total:'25.0000',gross_received:'50.0000',paid_total:'25.0000',pending_total:'0.0000',credit_available:'25.0000',refunded_total:'0.0000'});
    const mock={posts:[] as any[],lost:false,conflict:false,blocked:false,undos:0,finance};const completed=new Set<string>();
    await page.route(/\/returns\/1\/adjustments\/1\/reversal(?:\/requests\/[a-f0-9-]+)?$/,route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/requests/'))return completed.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,adjustment_id:1,reversal_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:{version:order.lock_version,review_hash:(order.lock_version===1?'a':'b').repeat(64),allowed:!mock.blocked&&!finance.compensated,invoice_number:'FAC-1-101',currency:'USD',amount:'25.0000',credit_to_reallocate:'25.0000',net_after:'50.0000',pending_after:'0.0000'}}});
        const body=route.request().postDataJSON();mock.posts.push(body);if(mock.conflict){mock.conflict=false;order.lock_version++;return route.fulfill({status:409,json:{message:'Los saldos cambiaron. Actualiza la revisión.'}});}
        if(!completed.has(body.request_key)){finance.compensated=true;finance.adjusted=false;mock.undos++;Object.assign(order,{net_total:'50.0000',paid_total:'50.0000',credit_available:'0.0000',return_credit_total:'0.0000',return_pending:true,lock_version:order.lock_version+1});completed.add(body.request_key);}
        if(mock.lost){mock.lost=false;return route.abort('failed');}return route.fulfill({json:{data:{order,adjustment_id:1,reversal_id:1,replay:false}}});
    });return mock;
}
async function openAdjustmentUndo(page:import('@playwright/test').Page){await openFinancial(page);await page.getByRole('button',{name:'Compensar ajuste económico #1',exact:true}).click();await expect(page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true})).toBeVisible();}
test('economic adjustment compensation preserves reason on rotation and can reconcile a new revision with original PDF available',async({page,board:state})=>{
    const undo=await adjustmentUndoRoutes(page,state);const documentPath='/api/v1/business/stores/1/sales/orders/1/documents/adjustment/1';await page.route(`**${documentPath}`,route=>route.request().method()==='POST'?route.fulfill({json:{data:{status:'ready',url:documentPath,filename:'AJU-00000001.pdf'}}}):route.fulfill({contentType:'application/pdf',body:'%PDF-original-adjustment'}));
    await openAdjustmentUndo(page);const dialog=page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true});await dialog.getByLabel('Motivo de la compensación del ajuste').fill('Compensar ajuste económico registrado por error');await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Motivo de la compensación del ajuste')).toHaveValue('Compensar ajuste económico registrado por error');await dialog.getByRole('button',{name:'Confirmar compensación de ajuste',exact:true}).click();await expect(dialog).toContainText('Ajuste compensado. La recepción vuelve a estar pendiente');await dialog.getByRole('button',{name:'Volver a conciliación',exact:true}).click();const parent=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await expect(parent.getByRole('region',{name:'Historial de ajustes económicos',exact:true})).toContainText('Compensado');
    const downloaded=page.waitForEvent('download');await parent.getByRole('button',{name:'Descargar ajuste histórico #1',exact:true}).click();expect((await downloaded).suggestedFilename()).toBe('AJU-00000001.pdf');await parent.getByLabel('Motivo del ajuste').fill('Ajuste económico corregido');await parent.getByRole('button',{name:'Confirmar ajuste económico',exact:true}).click();const history=parent.getByRole('region',{name:'Historial de ajustes económicos',exact:true});await expect(history).toContainText('Ajuste #2');await expect(history).toContainText('Vigente');expect(undo.posts).toHaveLength(1);expect(undo.finance.posts).toHaveLength(1);expect(state.orders[0].gross_received).toBe('50.0000');expect(state.writes).toHaveLength(0);
});
test('lost economic adjustment compensation recovers by GET without cancelling another revision',async({page,board:state})=>{
    const undo=await adjustmentUndoRoutes(page,state);undo.lost=true;await openAdjustmentUndo(page);const dialog=page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true});await dialog.getByLabel('Motivo de la compensación del ajuste').fill('Compensar ajuste económico registrado por error');await dialog.getByRole('button',{name:'Confirmar compensación de ajuste',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar compensación de ajuste',exact:true})).toBeEnabled();await page.reload();await openReturnOrder(page);await expect(page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true})).toContainText('Compensación de ajuste recuperada sin repetir movimientos.');expect(undo.posts).toHaveLength(1);expect(undo.undos).toBe(1);
});
test('economic adjustment compensation refreshes stale balances without losing its reason',async({page,board:state})=>{
    const undo=await adjustmentUndoRoutes(page,state);undo.conflict=true;await openAdjustmentUndo(page);const dialog=page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true});await dialog.getByLabel('Motivo de la compensación del ajuste').fill('Compensar ajuste económico registrado por error');await dialog.getByRole('button',{name:'Confirmar compensación de ajuste',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la compensación del ajuste')).toHaveValue('Compensar ajuste económico registrado por error');await dialog.getByRole('button',{name:'Actualizar revisión del ajuste',exact:true}).click();await dialog.getByRole('button',{name:'Confirmar compensación de ajuste',exact:true}).click();await expect(dialog).toContainText('Ajuste compensado.');expect(undo.posts).toHaveLength(2);expect(undo.posts[1].review_hash).toBe('b'.repeat(64));expect(undo.undos).toBe(1);
});
test('economic adjustment compensation is absent without its administrative permission',async({page,board:state})=>{
    const undo=await adjustmentUndoRoutes(page,state);const readonly=access.filter(p=>p!=='adjustments.reverse');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));await openFinancial(page);await expect(page.getByRole('button',{name:'Compensar ajuste económico #1',exact:true})).toHaveCount(0);await expect(page.getByRole('region',{name:'Historial de ajustes económicos',exact:true})).toContainText('Vigente');expect(undo.posts).toHaveLength(0);
});
test('economic adjustment compensation cannot confirm a later adjustment or consumed credit',async({page,board:state})=>{
    const undo=await adjustmentUndoRoutes(page,state);undo.blocked=true;await openAdjustmentUndo(page);const dialog=page.getByRole('dialog',{name:'Compensar ajuste económico',exact:true});await dialog.getByLabel('Motivo de la compensación del ajuste').fill('Compensar ajuste económico registrado por error');await expect(dialog.getByRole('button',{name:'Confirmar compensación de ajuste',exact:true})).toBeDisabled();await expect(dialog).toContainText('Compensa primero los ajustes posteriores');expect(undo.posts).toHaveLength(0);
});

async function refundCorrectionRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const finance=await financialRoutes(page,state,true);const order=state.orders[0];const mock={posts:[] as any[],lost:false,conflict:false,blocked:false,corrections:0};const completed=new Set<string>();
    await page.route(/\/returns\/1\/refunds\/1\/reversal(?:\/requests\/[a-f0-9-]+)?$/,route=>{
        const path=new URL(route.request().url()).pathname;if(path.includes('/requests/'))return completed.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,refund_id:1,reversal_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:{version:order.lock_version,review_hash:(order.lock_version===1?'a':'b').repeat(64),allowed:!mock.blocked&&!finance.correctedRefund,invoice_number:'FAC-1-101',currency:'USD',amount:'25.0000',credit_after:'25.0000'}}});
        const body=route.request().postDataJSON();mock.posts.push(body);if(mock.conflict){mock.conflict=false;order.lock_version++;return route.fulfill({status:409,json:{message:'Los saldos cambiaron. Actualiza la revisión.'}});}
        if(!completed.has(body.request_key)){finance.correctedRefund=true;mock.corrections++;Object.assign(order,{credit_available:'25.0000',refunded_total:'0.0000',lock_version:order.lock_version+1});completed.add(body.request_key);}if(mock.lost){mock.lost=false;return route.abort('failed');}return route.fulfill({json:{data:{order,refund_id:1,reversal_id:1,replay:false}}});
    });return mock;
}
async function openRefundCorrection(page:import('@playwright/test').Page){await openFinancial(page);await page.getByRole('button',{name:'Corregir reembolso erróneo #1',exact:true}).click();await expect(page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true})).toBeVisible();}
test('refund correction requires no-money attestation and retains it with the reason after rotation',async({page,board:state})=>{
    const correction=await refundCorrectionRoutes(page,state);await openRefundCorrection(page);const dialog=page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true});await dialog.getByLabel('Motivo de la corrección de reembolso').fill('Registro erróneo sin entrega de dinero');await expect(dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true})).toBeDisabled();await dialog.getByRole('checkbox').check();await page.setViewportSize({width:1024,height:380});await expect(dialog.getByRole('checkbox')).toBeChecked();await expect(dialog.getByLabel('Motivo de la corrección de reembolso')).toHaveValue('Registro erróneo sin entrega de dinero');await dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true}).click();await expect(dialog).toContainText('Reembolso erróneo compensado.');await dialog.getByRole('button',{name:'Volver a conciliación',exact:true}).click();await expect(page.getByRole('region',{name:'Reembolsos registrados',exact:true})).toContainText('Registro erróneo compensado');expect(correction.posts).toHaveLength(1);expect(correction.posts[0].confirmed_no_money).toBe(true);expect(state.orders[0].gross_received).toBe('50.0000');expect(state.orders[0].paid_total).toBe('25.0000');expect(state.orders[0].credit_available).toBe('25.0000');
});
test('lost refund correction recovers on returning without recording new cash',async({page,board:state})=>{
    const correction=await refundCorrectionRoutes(page,state);correction.lost=true;await openRefundCorrection(page);const dialog=page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true});await dialog.getByLabel('Motivo de la corrección de reembolso').fill('Registro erróneo sin entrega de dinero');await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar corrección de reembolso',exact:true})).toBeEnabled();await page.reload();await openReturnOrder(page);await expect(page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true})).toContainText('Corrección de reembolso recuperada sin repetir movimientos.');expect(correction.posts).toHaveLength(1);expect(correction.corrections).toBe(1);
});
test('stale refund correction review preserves confirmation and requires refreshed balances',async({page,board:state})=>{
    const correction=await refundCorrectionRoutes(page,state);correction.conflict=true;await openRefundCorrection(page);const dialog=page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true});await dialog.getByLabel('Motivo de la corrección de reembolso').fill('Registro erróneo sin entrega de dinero');await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByRole('checkbox')).toBeChecked();await dialog.getByRole('button',{name:'Actualizar revisión de corrección',exact:true}).click();await dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true}).click();await expect(dialog).toContainText('Reembolso erróneo compensado.');expect(correction.posts).toHaveLength(2);expect(correction.posts[1].review_hash).toBe('b'.repeat(64));expect(correction.corrections).toBe(1);
});
test('refund correction is hidden without its independent administrative permission',async({page,board:state})=>{
    const correction=await refundCorrectionRoutes(page,state);const readonly=access.filter(p=>p!=='refunds.reverse');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));await openFinancial(page);await expect(page.getByRole('region',{name:'Reembolsos registrados',exact:true})).toContainText('US$ 25.00');await expect(page.getByRole('button',{name:'Corregir reembolso erróneo #1',exact:true})).toHaveCount(0);expect(correction.posts).toHaveLength(0);
});
test('refund correction cannot confirm an ineligible or already corrected record',async({page,board:state})=>{
    const correction=await refundCorrectionRoutes(page,state);correction.blocked=true;await openRefundCorrection(page);const dialog=page.getByRole('dialog',{name:'Corregir reembolso erróneo',exact:true});await dialog.getByLabel('Motivo de la corrección de reembolso').fill('Registro erróneo sin entrega de dinero');await dialog.getByRole('checkbox').check();await expect(dialog.getByRole('button',{name:'Confirmar corrección de reembolso',exact:true})).toBeDisabled();expect(correction.posts).toHaveLength(0);
});

test('economic return review survives rotation and manual refund preserves original gross without a stock write',async({page,board:state})=>{
    const finance=await financialRoutes(page,state);await openFinancial(page);const dialog=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await dialog.getByLabel('Motivo del ajuste').fill('Crédito autorizado tras revisar la devolución');await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Motivo del ajuste')).toHaveValue('Crédito autorizado tras revisar la devolución');await dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true}).click();await expect(dialog).toContainText('Ajuste #1');
    await dialog.getByRole('button',{name:'Preparar reembolso del pago #1',exact:true}).click();await dialog.getByLabel('Motivo del reembolso').fill('Efectivo devuelto al cliente y registrado');await dialog.getByRole('button',{name:'Registrar reembolso realizado',exact:true}).click();await expect(dialog.getByRole('region',{name:'Reembolsos registrados'})).toContainText('US$ 25.00');
    expect(finance.posts.map(p=>p.kind)).toEqual(['adjust','refund']);expect(state.orders[0].grand_total).toBe('50.0000');expect(state.orders[0].net_total).toBe('25.0000');expect(state.orders[0].credit_available).toBe('0.0000');expect(state.writes).toHaveLength(0);
});

test('lost refund is recovered by GET when the financial receipt is reopened',async({page,board:state})=>{
    const finance=await financialRoutes(page,state);finance.lostRefund=true;await openFinancial(page);await approveFinancial(page);const dialog=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await dialog.getByRole('button',{name:'Preparar reembolso del pago #1',exact:true}).click();await dialog.getByLabel('Motivo del reembolso').fill('Reembolso realizado en efectivo');await dialog.getByRole('button',{name:'Registrar reembolso realizado',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar operación financiera',exact:true})).toBeEnabled();
    await page.reload();await openFinancial(page);await expect(page.getByRole('dialog',{name:'Conciliación de devolución',exact:true})).toContainText('Operación financiera recuperada sin repetir movimientos.');expect(finance.posts.filter(p=>p.kind==='refund')).toHaveLength(1);
});

test('changed settlement retains its reason and confirms a refreshed financial hash',async({page,board:state})=>{
    const finance=await financialRoutes(page,state);finance.conflict=true;await openFinancial(page);const dialog=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await dialog.getByLabel('Motivo del ajuste').fill('Ajuste revisado con datos actuales');await dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo del ajuste')).toHaveValue('Ajuste revisado con datos actuales');await dialog.getByRole('button',{name:'Actualizar propuesta económica',exact:true}).click();await expect(dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true})).toBeEnabled();await dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true}).click();await expect(dialog).toContainText('Ajuste #1');expect(finance.posts).toHaveLength(2);expect(finance.posts[1].body.review_hash).toBe('b'.repeat(64));
});

test('sales viewer can inspect financial proposal without adjustment or refund permission',async({page,board:state})=>{
    const finance=await financialRoutes(page,state);const readonly=access.filter(p=>!['returns.adjust','refunds.record'].includes(p));await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));await openFinancial(page);const dialog=page.getByRole('dialog',{name:'Conciliación de devolución',exact:true});await expect(dialog).toContainText('US$ 25.00');await expect(dialog.getByRole('button',{name:'Confirmar ajuste económico',exact:true})).toHaveCount(0);expect(finance.posts).toHaveLength(0);
});

async function returnRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]},prepared=false){
    const order=state.orders[0];order.invoice={id:101,invoice_number:'FAC-1-101',currency:'USD',status:'partially_paid'};order.closed_at='2026-11-13T12:00:00Z';order.status='fulfilled';
    const mock={posts:[] as any[],lost:false,conflict:false,received:prepared,restocked:prepared?1:0,reversed:false};const replies=new Set<string>();let history:any[]=prepared?[{id:1,received_at:'2026-11-15 12:00:00',actor_name:'QA',reason:'Recepción de ejemplo para corregir',status:'received',items:[{name:'Bolso Clásico',quantity:'1.0000',restock:true}]}]:[];if(prepared){order.return_pending=true;order.lock_version=1;}
    await page.route(/\/sales\/orders\/1\/returns(?:\/.*)?$/,async route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/requests/'))return replies.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,sales_return_id:1,economic_status:'pending_adjustment',replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:{order_id:1,version:order.lock_version,invoice:{id:101,number:'FAC-1-101'},warehouse_name:'Almacén original QA',review_hash:(order.lock_version===0?'a':'b').repeat(64),return_pending:mock.received&&!mock.reversed,lines:[{invoice_item_id:1,variant_id:1,name:'Bolso Clásico',sku:'BOL-QA',invoiced_quantity:'2.0000',delivered_quantity:'2.0000',received_quantity:mock.received&&!mock.reversed?'1.0000':'0.0000',available_quantity:mock.received&&!mock.reversed?'1.0000':'2.0000',track_stock:true}],history:history.map(entry=>mock.reversed?{...entry,status:'void',reversal:{id:1,reason:'Recepción física registrada por error',actor_name:'QA',created_at:'2026-11-15 13:00:00'}}:entry)}}});
        const body=route.request().postDataJSON();mock.posts.push(body);
        if(mock.conflict||body.version!==order.lock_version){if(mock.conflict){mock.conflict=false;order.lock_version++;}return route.fulfill({status:409,json:{message:'Las entregas cambiaron. Actualiza la revisión.'}});}
        if(!replies.has(body.request_key)){mock.received=true;if(body.lines[0].restock)mock.restocked++;order.lock_version++;order.return_pending=true;history=[{id:1,received_at:'2026-11-14 12:00:00',actor_name:'QA',reason:body.reason,status:'received',items:[{name:'Bolso Clásico',quantity:'1.0000',restock:body.lines[0].restock}]}];replies.add(body.request_key);}
        if(mock.lost){mock.lost=false;return route.abort('failed');}
        return route.fulfill({status:201,json:{data:{order,sales_return_id:1,economic_status:'pending_adjustment',replay:false}}});
    });return mock;
}
async function openReturnOrder(page:import('@playwright/test').Page){await page.goto('/app/apartados');await page.getByRole('button',{name:'Todos',exact:true}).click();await expect(page.locator('.basket-card')).toHaveCount(2);await page.locator('.basket-card').first().click();}

async function receiptUndoRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const receipt=await returnRoutes(page,state,true);const order=state.orders[0];const mock={posts:[] as any[],lost:false,conflict:false,blocked:false,undos:0};const completed=new Set<string>();
    await page.route(/\/returns\/1\/reversal(?:\/requests\/[a-f0-9-]+)?$/,route=>{
        const path=new URL(route.request().url()).pathname;if(path.includes('/requests/'))return completed.has(path.split('/').pop()!)?route.fulfill({json:{data:{order,sales_return_id:1,reversal_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:{version:order.lock_version,review_hash:(order.lock_version===1?'a':'b').repeat(64),allowed:!mock.blocked&&!receipt.reversed,invoice_number:'FAC-1-101',warehouse_name:'Almacén original QA',lines:[{return_item_id:1,name:'Bolso Clásico',quantity:'1.0000',restock:true}]}}});
        const body=route.request().postDataJSON();mock.posts.push(body);if(mock.conflict){mock.conflict=false;order.lock_version++;return route.fulfill({status:409,json:{message:'El stock cambió. Actualiza la revisión.'}});}
        if(!completed.has(body.request_key)){receipt.reversed=true;receipt.restocked--;mock.undos++;order.return_pending=false;order.lock_version++;completed.add(body.request_key);}if(mock.lost){mock.lost=false;return route.abort('failed');}return route.fulfill({json:{data:{order,sales_return_id:1,reversal_id:1,replay:false}}});
    });return mock;
}
async function openReceiptUndo(page:import('@playwright/test').Page){await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();await page.getByRole('button',{name:'Anular recepción física #1',exact:true}).click();await expect(page.getByRole('dialog',{name:'Anular recepción física',exact:true})).toBeVisible();}
test('physical receipt compensation keeps reason after rotation and restores return capacity without changing money',async({page,board:state})=>{
    const undo=await receiptUndoRoutes(page,state);await openReceiptUndo(page);const dialog=page.getByRole('dialog',{name:'Anular recepción física',exact:true});await dialog.getByLabel('Motivo de la anulación física').fill('Recepción física registrada por error');await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Motivo de la anulación física')).toHaveValue('Recepción física registrada por error');await dialog.getByRole('button',{name:'Confirmar anulación de recepción',exact:true}).click();await expect(dialog).toContainText('Recepción anulada. Su cupo se restauró');await dialog.getByRole('button',{name:'Volver al historial de recepciones',exact:true}).click();await expect(page.getByRole('region',{name:'Recepciones anteriores',exact:true})).toContainText('Recepción anulada por compensación');await expect(page.getByRole('button',{name:'Conciliar recepción #1',exact:true})).toHaveCount(0);expect(undo.undos).toBe(1);expect(undo.posts).toHaveLength(1);expect(state.orders[0].paid_total).toBe('20.0000');expect(state.orders[0].grand_total).toBe('50.0000');
});
test('lost physical receipt compensation recovers by GET without repeating inverse stock',async({page,board:state})=>{
    const undo=await receiptUndoRoutes(page,state);undo.lost=true;await openReceiptUndo(page);const dialog=page.getByRole('dialog',{name:'Anular recepción física',exact:true});await dialog.getByLabel('Motivo de la anulación física').fill('Recepción física registrada por error');await dialog.getByRole('button',{name:'Confirmar anulación de recepción',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar anulación física',exact:true})).toBeEnabled();await page.reload();await openReturnOrder(page);await expect(page.getByRole('dialog',{name:'Anular recepción física',exact:true})).toContainText('Anulación física recuperada sin repetir movimientos.');expect(undo.posts).toHaveLength(1);expect(undo.undos).toBe(1);
});
test('physical receipt compensation refreshes a stale stock review while preserving reason',async({page,board:state})=>{
    const undo=await receiptUndoRoutes(page,state);undo.conflict=true;await openReceiptUndo(page);const dialog=page.getByRole('dialog',{name:'Anular recepción física',exact:true});await dialog.getByLabel('Motivo de la anulación física').fill('Recepción física registrada por error');await dialog.getByRole('button',{name:'Confirmar anulación de recepción',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la anulación física')).toHaveValue('Recepción física registrada por error');await dialog.getByRole('button',{name:'Actualizar revisión de anulación',exact:true}).click();await dialog.getByRole('button',{name:'Confirmar anulación de recepción',exact:true}).click();await expect(dialog).toContainText('Recepción anulada.');expect(undo.posts).toHaveLength(2);expect(undo.posts[1].review_hash).toBe('b'.repeat(64));expect(undo.undos).toBe(1);
});
test('physical receipt compensation is hidden without returns reverse permission',async({page,board:state})=>{
    const undo=await receiptUndoRoutes(page,state);const readonly=access.filter(p=>p!=='returns.reverse');await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();await expect(page.getByRole('region',{name:'Recepciones anteriores',exact:true})).toContainText('Recepción #1');await expect(page.getByRole('button',{name:'Anular recepción física #1',exact:true})).toHaveCount(0);expect(undo.posts).toHaveLength(0);
});
test('physical receipt compensation is blocked for unavailable stock or economic history',async({page,board:state})=>{
    const undo=await receiptUndoRoutes(page,state);undo.blocked=true;await openReceiptUndo(page);const dialog=page.getByRole('dialog',{name:'Anular recepción física',exact:true});await dialog.getByLabel('Motivo de la anulación física').fill('Recepción física registrada por error');await expect(dialog.getByRole('button',{name:'Confirmar anulación de recepción',exact:true})).toBeDisabled();await expect(dialog).toContainText('requiere revisión.');expect(undo.posts).toHaveLength(0);
});

test('return receipt keeps quantity and disposition on rotation and holds collection without changing money',async({page,board:state})=>{
    const receipt=await returnRoutes(page,state);await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true});
    await dialog.getByRole('textbox',{name:'Cantidad a recibir de BOL-QA',exact:true}).fill('1');await dialog.getByLabel('Destino de BOL-QA').selectOption('false');await dialog.getByLabel('Motivo de la recepción').fill('Mercancía no apta para ingreso a existencias');
    await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Destino de BOL-QA')).toHaveValue('false');await expect(dialog.getByRole('textbox',{name:'Cantidad a recibir de BOL-QA',exact:true})).toHaveValue('1');
    await dialog.getByRole('button',{name:'Registrar recepción física',exact:true}).click();await expect(dialog).toContainText('Recepción #1 registrada. Ajuste económico pendiente.');await expect(dialog.getByRole('region',{name:'Recepciones anteriores'})).toContainText('Sin ingreso a existencias');
    await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();await expect(page.getByRole('button',{name:'Registrar depósito',exact:true})).toBeDisabled();
    expect(receipt.posts).toHaveLength(1);expect(receipt.posts[0].lines[0].restock).toBe(false);expect(receipt.restocked).toBe(0);expect(state.orders[0].paid_total).toBe('20.0000');expect(state.orders[0].grand_total).toBe('50.0000');expect(state.writes).toHaveLength(0);
});

test('lost return receipt recovers on returning to the order without another stock receipt',async({page,board:state})=>{
    const receipt=await returnRoutes(page,state);receipt.lost=true;await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true});
    await dialog.getByRole('textbox',{name:'Cantidad a recibir de BOL-QA',exact:true}).fill('1');await dialog.getByLabel('Motivo de la recepción').fill('Recepción para devolución de talla');await dialog.getByRole('button',{name:'Registrar recepción física',exact:true}).click();await expect(dialog.getByRole('button',{name:'Comprobar recepción',exact:true})).toBeEnabled();
    await page.reload();await page.getByRole('button',{name:'Todos',exact:true}).click();await expect(page.locator('.basket-card')).toHaveCount(2);await page.locator('.basket-card').first().click();await expect(page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true})).toContainText('Recepción #1 recuperada sin repetir el ingreso.');expect(receipt.posts).toHaveLength(1);expect(receipt.restocked).toBe(1);
});

test('return conflict preserves entered quantities and reason for an explicit refreshed review',async({page,board:state})=>{
    const receipt=await returnRoutes(page,state);receipt.conflict=true;await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true});
    await dialog.getByRole('textbox',{name:'Cantidad a recibir de BOL-QA',exact:true}).fill('1');await dialog.getByLabel('Motivo de la recepción').fill('Devolución con revisión de cantidades');await dialog.getByRole('button',{name:'Registrar recepción física',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la recepción')).toHaveValue('Devolución con revisión de cantidades');
    await dialog.getByRole('button',{name:'Actualizar revisión de devolución',exact:true}).click();await expect(dialog.getByRole('button',{name:'Registrar recepción física',exact:true})).toBeEnabled();await dialog.getByRole('button',{name:'Registrar recepción física',exact:true}).click();await expect(dialog).toContainText('Recepción #1 registrada. Ajuste económico pendiente.');expect(receipt.posts).toHaveLength(2);expect(receipt.posts[1].version).toBe(1);expect(receipt.posts[1].review_hash).toBe('b'.repeat(64));
});

test('return reader sees quantities and history without receiving controls',async({page,board:state})=>{
    const receipt=await returnRoutes(page,state);const readonly=access.filter(p=>p!=='returns.receive');
    await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));
    await openReturnOrder(page);await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true});await expect(dialog).toContainText('Disponible para devolución 2');await expect(dialog.getByRole('button',{name:'Registrar recepción física',exact:true})).toHaveCount(0);expect(receipt.posts).toHaveLength(0);
});

async function paymentRoutes(page:import('@playwright/test').Page,state:{orders:SavedOrder[]}){
    const mock={posts:[] as any[],lost:false,conflict:false};const replies=new Set<string>();let reversed=false;let reversal:any=null;
    await page.route(/\/sales\/orders\/1\/payments(?:\/.*)?$/,async route=>{
        const path=new URL(route.request().url()).pathname;
        if(path.includes('/requests/'))return replies.has(path.split('/').pop()!)?route.fulfill({json:{data:{order:state.orders[0],reversal_id:1,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});
        if(route.request().method()==='GET')return route.fulfill({json:{data:[{id:1,amount:'20.0000',currency:'USD',status:reversed?'reversed':'confirmed',payment_method:'cash',paid_at:'2026-11-13 12:00:00',reversal}]}});
        const body=route.request().postDataJSON();mock.posts.push(body);
        if(mock.conflict){mock.conflict=false;state.orders[0].lock_version++;return route.fulfill({status:409,json:{message:'El pedido cambió.'}});}
        if(!replies.has(body.request_key)){state.orders[0].paid_total='0.0000';state.orders[0].pending_total='50.0000';state.orders[0].lock_version++;reversed=true;reversal={id:1,reason:body.reason,actor_name:'Propietaria QA',reversed_at:'2026-11-14 12:00:00'};replies.add(body.request_key);}
        if(mock.lost){mock.lost=false;return route.abort('failed');}
        return route.fulfill({json:{data:{order:state.orders[0],reversal_id:1,replay:false}}});
    });return mock;
}

test('payment reversal keeps its reason on rotation and updates the selected basket and card',async({page,board:state})=>{
    const payments=await paymentRoutes(page,state);await board(page);await page.locator('.basket-card').first().click();await page.getByRole('button',{name:'Pagos y reversiones',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Pagos y reversiones',exact:true});await dialog.getByRole('button',{name:'Revertir pago #1',exact:true}).click();await dialog.getByLabel('Motivo de la reversión').fill('Cobro duplicado al registrar el abono');
    await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Motivo de la reversión')).toHaveValue('Cobro duplicado al registrar el abono');
    await dialog.getByRole('button',{name:'Revertir pago por error',exact:true}).click();await expect(dialog).toContainText('Pago revertido. El saldo del pedido se actualizó.');await expect(dialog.getByRole('region',{name:'Historial de pagos del pedido'})).toContainText('Revertido');
    await expect(page.locator('.basket-card').first()).toContainText('US$ 0.00');expect(payments.posts).toHaveLength(1);expect(state.writes).toHaveLength(0);expect(state.orders[0].grand_total).toBe('50.0000');
});

test('lost reversal recovers on returning to the basket after reload without a second mutation',async({page,board:state})=>{
    const payments=await paymentRoutes(page,state);payments.lost=true;await board(page);await page.locator('.basket-card').first().click();await page.getByRole('button',{name:'Pagos y reversiones',exact:true}).click();
    await page.getByRole('button',{name:'Revertir pago #1',exact:true}).click();await page.getByLabel('Motivo de la reversión').fill('Registro equivocado del cobro');await page.getByRole('button',{name:'Revertir pago por error',exact:true}).click();await expect(page.getByRole('button',{name:'Comprobar reversión',exact:true})).toBeEnabled();
    await page.reload();await page.locator('.basket-card').first().click();await expect(page.getByRole('dialog',{name:'Pagos y reversiones',exact:true})).toContainText('Reversión recuperada sin repetir la operación.');expect(payments.posts).toHaveLength(1);
});

test('stale reversal preserves the reason and requires refreshed order version',async({page,board:state})=>{
    const payments=await paymentRoutes(page,state);payments.conflict=true;await board(page);await page.locator('.basket-card').first().click();await page.getByRole('button',{name:'Pagos y reversiones',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Pagos y reversiones',exact:true});await dialog.getByRole('button',{name:'Revertir pago #1',exact:true}).click();await dialog.getByLabel('Motivo de la reversión').fill('Pago registrado por error');await dialog.getByRole('button',{name:'Revertir pago por error',exact:true}).click();await expect(dialog.getByRole('alert')).toBeVisible();await expect(dialog.getByLabel('Motivo de la reversión')).toHaveValue('Pago registrado por error');
    await dialog.getByRole('button',{name:'Actualizar pedido e historial',exact:true}).click();await expect(dialog.getByRole('button',{name:'Revertir pago por error',exact:true})).toBeEnabled();await dialog.getByRole('button',{name:'Revertir pago por error',exact:true}).click();await expect(dialog).toContainText('Pago revertido. El saldo del pedido se actualizó.');expect(payments.posts).toHaveLength(2);expect(payments.posts[1].version).toBe(1);
});

test('sales reader can inspect payment history without reversal controls',async({page,board:state})=>{
    const payments=await paymentRoutes(page,state);const readonly=access.filter(p=>p!=='payments.reverse');
    await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:readonly}}}));
    await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:readonly}}}));
    await board(page);await page.locator('.basket-card').first().click();await page.getByRole('button',{name:'Pagos y reversiones',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Pagos y reversiones',exact:true});await expect(dialog).toContainText('US$ 20.00');await expect(dialog.getByRole('button',{name:'Revertir pago #1',exact:true})).toHaveCount(0);expect(payments.posts).toHaveLength(0);
});

test('order view link generation and revocation preserve the financial order on rotation',async({page,board:state})=>{
    const links:any[]=[]; const writes:any[]=[];
    await page.route(/\/baskets\/\d+\/view-links(?:\/\d+\/revoke)?$/,async route=>{
        if(route.request().method()==='GET') return route.fulfill({json:{data:links}});
        writes.push({path:new URL(route.request().url()).pathname,body:route.request().postData()});
        if(route.request().url().endsWith('/revoke')) {links[0].status='revoked';return route.fulfill({json:{data:links[0]}});}
        links.push({id:1,status:'active',expires_at:'2026-11-21T12:00:00Z'});
        return route.fulfill({status:201,json:{data:{link:links[0],path:'/s/order/'+'a'.repeat(64),replay:false}}});
    });
    await board(page); await page.locator('.basket-card').first().click();
    await page.getByRole('button',{name:'Compartir enlace',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Compartir apartado',exact:true});
    await dialog.getByLabel('Caducidad del enlace').selectOption('72');
    await page.setViewportSize({width:1024,height:380});
    await expect(dialog.getByLabel('Caducidad del enlace')).toHaveValue('72');
    await dialog.getByRole('button',{name:'Generar enlace de consulta',exact:true}).click();
    await expect(dialog.getByLabel('Enlace para el cliente')).toHaveValue(new RegExp('/s/order/a{64}$'));
    expect(JSON.parse(writes[0].body).hours).toBe(72);
    await dialog.getByRole('button',{name:'Revocar enlace #1',exact:true}).click();
    await expect(dialog.getByLabel('Enlace para el cliente')).toHaveCount(0);
    await expect(dialog.getByRole('region',{name:'Enlaces recientes'})).toContainText('Revocado');
    await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();
    expect(writes).toHaveLength(2); expect(state.writes).toHaveLength(0);
    expect(state.orders[0].paid_total).toBe('20.0000'); expect(state.orders[0].grand_total).toBe('50.0000');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('lost link generation retries the same identity and unsaved lines disable sharing',async({page,board:state})=>{
    const bodies:any[]=[];
    await page.route(/\/baskets\/\d+\/view-links$/,async route=>{
        if(route.request().method()==='GET') return route.fulfill({json:{data:[]}});
        bodies.push(route.request().postDataJSON());
        if(bodies.length===1) return route.abort('failed');
        return route.fulfill({json:{data:{link:{id:2,status:'active',expires_at:'2026-11-21T12:00:00Z'},path:'/s/order/'+'b'.repeat(64),replay:true}}});
    });
    await board(page); await page.locator('.basket-card').first().click();
    await page.getByRole('button',{name:'Compartir enlace',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Compartir apartado',exact:true});
    await dialog.getByRole('button',{name:'Generar enlace de consulta',exact:true}).click();
    await expect(dialog.getByRole('button',{name:'Reintentar generación',exact:true})).toBeEnabled();
    await expect(dialog.getByLabel('Caducidad del enlace')).toBeDisabled();
    await dialog.getByRole('button',{name:'Reintentar generación',exact:true}).click();
    await expect(dialog.getByLabel('Enlace para el cliente')).toHaveValue(new RegExp('/s/order/b{64}$'));
    expect(bodies).toHaveLength(2); expect(bodies[0]).toEqual(bodies[1]);
    await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();
    await page.getByRole('button',{name:'Aumentar Cantidad de BOL-QA',exact:true}).click();
    await expect(page.getByRole('button',{name:'Compartir enlace',exact:true})).toBeDisabled();
    expect(state.writes).toHaveLength(0);
});

test('reservation detail explains protected deadlines and identifies expired reservations', async ({page, board:state}) => {
    state.orders[0].reservation_expires_at = '2026-11-16T12:00:00Z';
    state.orders[1].reservation_expired = true;
    await board(page);
    await page.locator('.basket-card').first().click();
    await expect(page.getByText(/Plazo original de reserva/)).toContainText('Conserva su reserva por tener abonos, factura o entregas.');
    state.orders[1].status = 'cancelled';
    await page.getByRole('button',{name:'Todos',exact:true}).click();
    await expect(page.locator('.basket-card')).toHaveCount(2);
    await page.locator('.basket-card').nth(1).click();
    await expect(page.getByText('Apartado vencido. El inventario reservado fue liberado.', {exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Guardar cambios',exact:true})).toHaveCount(0);
    expect(state.writes).toHaveLength(0);
});

test('basket steppers keep decimals exact and pending currency on one line',async({page,board:state},testInfo)=>{
    await board(page);
    await page.locator('.basket-card').first().click();
    const count=page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true});
    await expect(count).toHaveValue('2');
    await page.getByRole('button',{name:'Disminuir Cantidad de BOL-QA',exact:true}).click();
    await expect(count).toHaveValue('1');
    await expect(page.getByRole('button',{name:'Disminuir Cantidad de BOL-QA',exact:true})).toBeDisabled();
    await page.getByRole('button',{name:'Aumentar Cantidad de BOL-QA',exact:true}).click();
    await expect(count).toHaveValue('2');
    const shipping=page.getByRole('textbox',{name:'Envío USD',exact:true});
    const discount=page.getByRole('textbox',{name:'Descuento USD',exact:true});
    await expect(shipping).toHaveValue('0');
    await expect(discount).toHaveValue('0');
    for(const label of ['Envío USD','Descuento USD']){
        const input=page.getByRole('textbox',{name:label,exact:true});
        await input.fill('0.1');
        await page.getByRole('button',{name:`Aumentar ${label}`,exact:true}).click();
        await expect(input).toHaveValue('1.1');
        await page.getByRole('button',{name:`Disminuir ${label}`,exact:true}).click();
        await expect(input).toHaveValue('0.1');
        await page.getByRole('button',{name:`Disminuir ${label}`,exact:true}).click();
        await expect(input).toHaveValue('0');
        await expect(page.getByRole('button',{name:`Disminuir ${label}`,exact:true})).toBeDisabled();
    }
    await count.fill('5');await shipping.fill('1');await discount.fill('2');
    const pending=page.locator('.basket-pending strong');
    await expect(pending).toHaveText('US$ 104.00');
    const layout=await pending.evaluate(el=>{
        const range=document.createRange();range.selectNodeContents(el);
        return {lines:range.getClientRects().length,fits:el.scrollWidth<=el.clientWidth};
    });
    expect(layout).toEqual({lines:1,fits:true});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
    expect(state.writes).toHaveLength(0);
    await page.screenshot({path:testInfo.outputPath('basket-steppers.png'),fullPage:true});
});

test('receivables filters exports and opens the existing deposit flow',async({page,board:state},testInfo)=>{
    await page.route('**/receivables**',async route=>{
        const url=new URL(route.request().url());
        if(url.pathname.endsWith('/export'))return route.fulfill({contentType:'text/csv',body:'Cliente,Pendiente\nAna Demo,25.0000\n'});
        let rows=state.orders.map((o,i)=>({order_id:o.id,customer_id:o.customer_id,customer_name:o.customer_snapshot.full_name,phone:null,reference:`Apartado #${o.basket_number}`,invoiced:false,date:'2026-11-13',age_days:10,currency:'USD',total:o.grand_total,paid:o.paid_total,pending:o.pending_total}));
        const q=(url.searchParams.get('q')||'').toLowerCase();
        rows=rows.filter(r=>(!q||r.customer_name.toLowerCase().includes(q))&&url.searchParams.get('type')!=='invoiced'&&(url.searchParams.get('state')!=='partial'||r.paid!=='0.0000')&&Number(url.searchParams.get('min_days')||0)<=r.age_days);
        const fmt=(value:number)=>value.toFixed(4);
        const summary=rows.length?[{currency:'USD',total:fmt(rows.reduce((s,r)=>s+Number(r.total),0)),paid:fmt(rows.reduce((s,r)=>s+Number(r.paid),0)),pending:fmt(rows.reduce((s,r)=>s+Number(r.pending),0)),documents:rows.length,customers:rows.length}]:[];
        return route.fulfill({json:{data:rows,summary,meta:{current_page:1,last_page:1,total:rows.length}}});
    });
    await page.goto('/app/cxc');
    await expect(page.locator('.cxc-row')).toHaveCount(2);
    await expect(page.getByRole('region',{name:'Resumen USD',exact:true})).toContainText('US$ 55.00');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
    await page.screenshot({path:testInfo.outputPath('receivables.png'),fullPage:true});
    await page.getByRole('combobox',{name:'Documento',exact:true}).selectOption('invoiced');
    await expect(page.locator('.receivables .empty')).toContainText('No hay saldos');
    await page.getByRole('combobox',{name:'Documento',exact:true}).selectOption('all');
    await page.getByRole('combobox',{name:'Antigüedad',exact:true}).selectOption('30');
    await expect(page.locator('.cxc-row')).toHaveCount(0);
    await page.getByRole('combobox',{name:'Antigüedad',exact:true}).selectOption('0');
    await page.getByLabel('Buscar cliente o documento',{exact:true}).fill('Ana');
    await expect(page.locator('.cxc-row')).toHaveCount(1);
    await expect(page.locator('.cxc-row')).toContainText('Ana Demo');
    await expect(page.getByRole('region',{name:'Resumen USD',exact:true})).toContainText('US$ 25.00');
    const downloadPromise=page.waitForEvent('download');
    await page.getByRole('button',{name:'Exportar CSV',exact:true}).click();
    const download=await downloadPromise;expect(download.suggestedFilename()).toBe('cuentas-por-cobrar.csv');
    await page.getByRole('button',{name:'Ver pedido y abonar Apartado #2',exact:true}).click();
    await expect(page.getByRole('region',{name:'Detalle del apartado',exact:true})).toContainText('Ana Demo');
    await page.getByRole('button',{name:'Registrar depósito',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Registrar depósito',exact:true})).toBeVisible();
    expect(state.writes).toHaveLength(0);
});
test('single board keeps grid above reactive detail with no navigation or reload',async({page,board:state})=>{await board(page);const url=page.url();await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await expect(page.getByRole('region',{name:'Detalle del apartado'})).toContainText('María Demo');await page.getByRole('button',{name:'Seleccionar apartado 2 de Ana Demo',exact:true}).click();await expect(page.getByRole('region',{name:'Detalle del apartado'})).toContainText('Ana Demo');expect(page.url()).toBe(url);const geometry=await page.evaluate(()=>({grid:document.querySelector('.basket-grid')!.getBoundingClientRect().bottom,detail:document.querySelector('.basket-detail')!.getBoundingClientRect().top}));expect(geometry.detail).toBeGreaterThanOrEqual(geometry.grid);expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();});
test('editing quantities preserves deposit and updates upper card after save',async({page,board:state})=>{await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true}).fill('3');await expect(page.locator('.basket-pending')).toContainText('US$ 55.00');await page.getByRole('button',{name:'Guardar cambios',exact:true}).click();await expect(page.locator('.basket-card.selected')).toContainText('US$ 75.00');expect(state.orders[0].paid_total).toBe('20.0000');});
test('drafts survive selection and rotation while stock conflicts preserve edits',async({page,board:state})=>{await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true}).fill('3');await page.getByRole('button',{name:'Seleccionar apartado 2 de Ana Demo',exact:true}).click();await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.setViewportSize({width:768,height:1024});await expect(page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true})).toHaveValue('3');state.conflict=true;await expect(page.getByRole('button',{name:'Guardar cambios',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Guardar cambios',exact:true}).click();await expect(page.getByRole('alert')).toContainText('Los datos cambiaron');await expect(page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true})).toHaveValue('3');await page.getByRole('button',{name:'Descartar borrador y actualizar',exact:true}).click();await expect(page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true})).toHaveValue('2');});
test('renumber updates card and detail and filters remain in same view',async({page,board:state})=>{await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('button',{name:'Cambiar #',exact:true}).click();const d=page.getByRole('dialog',{name:'Cambiar número de apartado',exact:true});await d.getByLabel('Número visible',{exact:true}).fill('12');await d.getByRole('button',{name:'Confirmar operación',exact:true}).click();await expect(page.getByRole('button',{name:'Seleccionar apartado 12 de María Demo',exact:true})).toBeVisible();await expect(page.locator('.basket-number-tile')).toHaveText('12');await page.getByRole('button',{name:'Sin depósito',exact:true}).click();await expect(page.locator('.basket-card')).toHaveCount(1);await expect(page.locator('.basket-detail')).toContainText('María Demo');});
test('search adds real variant in lower detail then saves its reservation',async({page,board:state})=>{await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('searchbox',{name:'Buscar artículo para agregar',exact:true}).fill('aretes');await page.locator('.basket-product-result').first().click();await expect(page.locator('.basket-item')).toHaveCount(2);await expect(page.getByRole('button',{name:'Guardar cambios',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Guardar cambios',exact:true}).click();await expect(page.locator('.basket-card.selected')).toContainText('US$ 65.00');expect(state.orders[0].items).toHaveLength(2);});
test('optional WhatsApp sends inside app only when configured and never fakes sent status',async({page,board:state})=>{state.whatsapp=true;await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('button',{name:'Cerrar pedido y enviar factura',exact:true}).click();const d=page.getByRole('dialog',{name:'Cerrar pedido y generar factura',exact:true});await d.getByRole('button',{name:'Confirmar operación',exact:true}).click();await expect(page.locator('.basket-detail')).toContainText('Aceptada por WhatsApp');expect(state.sends).toBe(1);expect(state.orders[0].invoice?.id).toBe(101);expect(page.url()).toContain('/app/apartados');await page.getByRole('button',{name:'Factura enviada',exact:true}).click();await expect(page.locator('.basket-card')).toHaveCount(0);});
test('reload after lost save restores server result without another write',async({page,board:state})=>{await board(page);await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true}).fill('3');await expect(page.getByRole('button',{name:'Guardar cambios',exact:true})).toBeEnabled();state.lost=true;await page.getByRole('button',{name:'Guardar cambios',exact:true}).click();await expect(page.getByRole('button',{name:'Reintentar operación',exact:true})).toBeVisible();await page.reload();await expect(page.getByRole('status').filter({hasText:'Operación recuperada sin duplicados.'})).toBeVisible();expect(state.writes).toHaveLength(1);await expect(page.locator('.basket-pending')).toContainText('US$ 55.00');});
test('WhatsApp settings are optional and saving configuration sends no messages',async({page,board:state})=>{await board(page);if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();await page.getByRole('button',{name:'Configuración',exact:true}).click();const panel=page.getByRole('region',{name:'Configuración opcional de WhatsApp',exact:true});await expect(panel.getByRole('checkbox',{name:'Habilitar WhatsApp automático',exact:true})).not.toBeChecked();await panel.getByRole('button',{name:'Guardar configuración de WhatsApp',exact:true}).click();await expect(panel.getByRole('status')).toContainText('No se enviaron mensajes');expect(state.sends).toBe(0);});
test('grid layouts request square page sizes and preserve the selected unsaved draft',async({page,board:state})=>{
    await board(page);
    await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();
    await page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true}).fill('3');
    const selector=page.getByRole('combobox',{name:'Vista de cuadrícula',exact:true});
    for(const size of [2,6,4]){
        const response=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/baskets')&&new URL(r.url()).searchParams.get('per_page')===String(size*size));
        await selector.selectOption(String(size));await response;
        await expect(page.locator('.basket-grid')).toHaveAttribute('data-requested-columns',String(size));
        await expect(page.getByRole('textbox',{name:'Cantidad de BOL-QA',exact:true})).toHaveValue('3');
        await expect(page.locator('.basket-card.selected')).toContainText('María Demo');
    }
    expect(state.writes).toHaveLength(0);
});
test('six-column preference persists and adapts to mobile without horizontal overflow',async({page,board:state})=>{
    await board(page);await page.setViewportSize({width:1440,height:1000});
    await page.getByRole('combobox',{name:'Vista de cuadrícula',exact:true}).selectOption('6');
    await expect.poll(()=>page.locator('.basket-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)).toBe(6);
    await page.reload();await expect(page.getByRole('combobox',{name:'Vista de cuadrícula',exact:true})).toHaveValue('6');
    await page.setViewportSize({width:1024,height:768});
    await expect.poll(()=>page.locator('.basket-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)).toBe(6);
    await page.setViewportSize({width:768,height:1024});
    await expect.poll(()=>page.locator('.basket-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)).toBe(4);
    await page.setViewportSize({width:390,height:844});
    await expect.poll(()=>page.locator('.basket-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)).toBe(2);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
    await expect(page.getByRole('combobox',{name:'Vista de cuadrícula',exact:true})).toHaveValue('6');
    expect(state.writes).toHaveLength(0);
});
