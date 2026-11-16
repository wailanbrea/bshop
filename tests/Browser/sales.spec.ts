import { test as base, expect, business, permissions, stores } from './tablet.fixture';
import type { SalesProduct, SavedOrder, SalesCustomer } from '../../resources/js/sales';

const usd = { ...business, default_currency: 'USD' };
const access = [...permissions, 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'invoices.view', 'payments.record', 'sales.cancel','payments.reverse','returns.receive','credits.apply'];
const test = base.extend<{ sales: { orders: SavedOrder[]; writes: any[]; lostResponse: boolean; quoteDelay: number; customers: any[]; products: SalesProduct[] } }>({
    sales: async ({ page, businessState }, use) => {
        const products: SalesProduct[] = [['Camiseta Básica', '25'], ['Jeans Mom Fit', '45'], ['Gafas de Sol', '22'], ['Perfume Floral', '49']].map(([name, price], index) => ({ id: index + 1, name, category_id: index < 2 ? 1 : 2, track_stock: true, unit_code: 'unit', image: null, variants: [{ id: index + 1, sku: ['CAM-M', 'JEA-28', 'GAF-N', 'PER-100'][index], price: price + '.0000', available: '5.0000', attributes: [{ name: index === 3 ? 'Presentación' : 'Variante', value: index === 3 ? '100 ml' : 'General' }] }] }));
        products[0].variants.push({ id: 10, sku: 'CAM-L', price: '25.0000', available: '0.0000', attributes: [{ name: 'Talla', value: 'L' }] });
        const state = { products, orders: [] as SavedOrder[], writes: [] as any[], lostResponse: false, quoteDelay: 0, customers: [{ id: 1, full_name: 'María Demo', phone: '+1 202-555-0101', email: null, address: null, notes: 'Cliente ficticio de pruebas.',status:'active',lock_version:0 }] as SalesCustomer[] };
        const replies = new Map<string, any>();
        const format = (n: bigint) => `${n / 10000n}.${String(n % 10000n).padStart(4, '0')}`;
        const raw = (n: string) => { const [a, b = ''] = String(n).split('.'); return BigInt(a) * 10000n + BigInt(b.padEnd(4, '0')); };
        const price = (body: any) => {
            let subtotal = 0n;
            const lines = body.lines.map((line: any) => { const product = state.products.find(p => p.variants.some(v => v.id === line.variant_id))!; const variant = product.variants.find(v => v.id === line.variant_id)!; const total = raw(variant.price) * raw(line.quantity) / 10000n; subtotal += total; return { ...line, unit_price: variant.price, line_total: format(total), product_name: product.name, sku: variant.sku, variant: variant.attributes }; });
            return { currency: 'USD', subtotal: format(subtotal), discount_total: format(raw(body.discount)), shipping_total: format(raw(body.shipping)), tax_total: '0.0000', grand_total: format(subtotal - raw(body.discount) + raw(body.shipping)), quote_hash: 'a'.repeat(64), lines };
        };
        await page.route('**/api/v1/session', route => route.fulfill({ json: { data: { user: { id: 1, name: 'Propietaria QA', email: 'qa@example.test' }, business: usd, roles: ['Propietario'], permissions: access } } }));
        await page.route('**/api/v1/business/workspace', route => route.fulfill({ json: { data: { business: usd, stores, permissions: access } } }));
        await page.route('**/api/v1/business/catalog/options', route => route.fulfill({ json: { data: { categories: [{ id: 1, name: 'Ropa', is_active: true }, { id: 2, name: 'Accesorios', is_active: true }], brands: [], attributes: [] } } }));
        await page.route('**/api/v1/business/stores/**', async route => {
            const path = new URL(route.request().url()).pathname.replace(/^\/api\/v1\/business\/stores\/\d+/, ''); const method = route.request().method(); const params = new URL(route.request().url()).searchParams;
            if (path === '/customers' && method === 'GET') return route.fulfill({ json: { data: state.customers.filter(c => (!c.status || (params.get('status') || 'active') === 'all' || c.status === (params.get('status') || 'active')) && c.full_name.toLowerCase().includes((params.get('q') || '').toLowerCase())), meta: { current_page: 1, last_page: 1, total: state.customers.length } } });
            if(path === '/customers/1/status' && method === 'PATCH') { const body=route.request().postDataJSON(); const c=state.customers[0]; c.status=body.status; c.lock_version=(c.lock_version || 0)+1; return route.fulfill({json:{data:{customer:c,replay:false}}}); }
            if(path === '/customers/1' && method === 'GET') return route.fulfill({json:{data:state.customers[0]}});
            if (/\/customers\/\d+\/summary/.test(path)) return route.fulfill({ json: { data: { pending_total: '0.0000', purchase_count: 0, last_purchase_at: null, open_baskets: [] } } });
            if (path === '/customers' && method === 'POST') { const body = route.request().postDataJSON(); const customer = { id: state.customers.length + 1, ...body }; state.customers.push(customer); return route.fulfill({ status: 201, json: { data: { customer } } }); }
            if (path === '/sales/catalog') { const q = (params.get('q') || '').toLowerCase(); const category = Number(params.get('category_id')); const list = state.products.filter(p => (!category || p.category_id === category) && (!q || p.name.toLowerCase().includes(q) || p.variants.some(v => v.sku.toLowerCase().includes(q)))); return route.fulfill({ json: { data: list, meta: { current_page: 1, last_page: 1, total: list.length } } }); }
            if (path === '/sales/quote') { if (state.quoteDelay) await new Promise(r => setTimeout(r, state.quoteDelay)); return route.fulfill({ json: { data: price(route.request().postDataJSON()) } }); }
            if (path === '/sales/orders' && method === 'POST') {
                const body = route.request().postDataJSON(); state.writes.push(body);
                if (replies.has(body.request_key)) return route.fulfill({ json: { data: { ...replies.get(body.request_key), replay: true } } });
                const quote = price(body); const customer = state.customers.find(c => c.id === body.customer_id)!; const id = state.orders.length + 1;
                const order: SavedOrder = { id, store_id: 1, warehouse_id: 1, customer_id: customer.id, customer_snapshot: customer, status: body.mode === 'sale' ? 'fulfilled' : 'reserved', currency: 'USD', lock_version: 0, subtotal: quote.subtotal, discount_total: quote.discount_total, shipping_total: quote.shipping_total, tax_total: '0.0000', grand_total: quote.grand_total, paid_total: format(raw(body.initial_payment)), pending_total: format(raw(quote.grand_total) - raw(body.initial_payment)), estimated_delivery: null, items: quote.lines.map((line: any, index: number) => ({ id: index + 1, variant_id: line.variant_id, product_name_snapshot: line.product_name, sku_snapshot: line.sku, quantity: line.quantity, unit_price: line.unit_price, line_total: line.line_total, variant_snapshot: line.variant, image_url_snapshot: null })), invoice: body.mode === 'sale' ? { id, invoice_number: `FAC-1-${id}`, status: 'paid', currency: 'USD' } : null, reservations: [] };
                order.order_kind=body.mode==='sale'?'sale':'reservation';order.basket_number=String(id);order.closed_at=body.mode==='sale'?'2026-11-13T12:00:00Z':null;
                state.orders.push(order); const result = { order, replay: false, document: body.mode === 'sale' ? { status: 'ready' } : undefined }; replies.set(body.request_key, result);
                if (state.lostResponse) { state.lostResponse = false; return route.abort('failed'); }
                return route.fulfill({ status: 201, json: { data: result } });
            }
            if (path.startsWith('/sales/requests/')) { const result = replies.get(path.split('/').pop()!); return result ? route.fulfill({ json: { data: { ...result, replay: true } } }) : route.fulfill({ status: 404, json: { message: 'Sin confirmación.' } }); }
            if(path==='/baskets')return route.fulfill({json:{data:state.orders.filter(o=>o.order_kind==='reservation').map(o=>({id:o.id,number:o.basket_number,customer_id:o.customer_id,customer_name:o.customer_snapshot.full_name,items_count:o.items.length,total:o.grand_total,deposit:o.paid_total,pending:o.pending_total,currency:o.currency,status:o.status,closed:!!o.closed_at,invoice_generated:!!o.invoice,paid:o.pending_total==='0.0000',shipped:false,version:o.lock_version})),meta:{current_page:1,last_page:1,total:state.orders.length}}});
            if (path === '/sales/orders') return route.fulfill({ json: { data: state.orders, meta: { current_page: 1, last_page: 1, total: state.orders.length } } });
            if (/^\/sales\/orders\/\d+$/.test(path)) return route.fulfill({ json: { data: state.orders.find(o => o.id === Number(path.split('/').pop())) } });
            if (/\/invoices\/\d+\/document/.test(path)) return method === 'POST' ? route.fulfill({ json: { data: { status: 'ready', url: '/api/v1/business/stores/1/invoices/1/document' } } }) : route.fulfill({ contentType: 'application/pdf', headers: { 'Content-Disposition': 'attachment; filename="FAC-1-1.pdf"' }, body: '%PDF-1.4\n%%EOF' });
            return route.fallback();
        });
        await use(state); expect(businessState.writes).toBe(0);
    },
});
async function openSales(page: import('@playwright/test').Page) {
    await page.goto('/'); if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: 'Abrir menú', exact: true }).click();
    await page.getByRole('button', { name: 'Ventas', exact: true }).click(); await expect(page.getByRole('button', { name: 'Elegir Camiseta Básica', exact: true })).toBeVisible();
}
async function summary(page: import('@playwright/test').Page) { if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: /^Resumen \(/ }).click(); }
async function products(page: import('@playwright/test').Page) { if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: 'Productos', exact: true }).click(); }
async function selectMaria(page: import('@playwright/test').Page) { await summary(page); await page.locator('button.sales-selected-customer').click(); await page.getByRole('dialog', { name: 'Seleccionar cliente para la venta', exact: true }).getByRole('button', { name: /María Demo/ }).click(); }
async function add(page: import('@playwright/test').Page, name: string) {
    await products(page); await page.getByRole('button', { name: `Elegir ${name}`, exact: true }).click();
    if (name === 'Camiseta Básica') { const dialog = page.getByRole('dialog', { name: 'Elegir variante de Camiseta Básica', exact: true }); await dialog.getByRole('combobox', { name: 'Variante de Camiseta Básica', exact: true }).selectOption('1'); await dialog.getByRole('button', { name: 'Agregar a la venta', exact: true }).click(); }
}

test('sale uses real server totals in USD and exposes a private PDF download', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); for (const name of ['Camiseta Básica', 'Jeans Mom Fit', 'Gafas de Sol']) await add(page, name);
    await summary(page); await page.locator('.sales-adjustments summary').click(); await page.getByLabel('Envío USD', { exact: true }).fill('5'); await page.getByLabel('Pago recibido USD', { exact: true }).fill('97');
    await expect(page.locator('.sales-total-emphasis')).toContainText('US$ 97.00'); expect(sales.orders).toHaveLength(0);
    await page.getByRole('button', { name: 'Facturar y enviar', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Venta registrada' })).toBeVisible(); expect(sales.orders).toHaveLength(1);
    const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Descargar factura PDF', exact: true }).click(); expect((await download).suggestedFilename()).toContain('.pdf');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy();
});

test('direct sale can apply existing credit while preserving zero new cash received',async({page,sales})=>{
    let applied=false;let posts=0;
    await page.route(/\/sales\/orders\/1\/credits$/,async route=>{
        const order=sales.orders[0];if(route.request().method()==='GET')return route.fulfill({json:{data:{version:order.lock_version,review_hash:'a'.repeat(64),currency:'USD',invoice_number:'FAC-1-1',application_allowed:true,return_pending:false,balance:{pending_total:order.pending_total,paid_total:order.paid_total},sources:applied?[]:[{payment_id:77,origin_number:99,available:'22.0000',method:'cash',pools:[]}],history:[]}}});
        posts++;applied=true;order.lock_version++;order.paid_total='22.0000';order.pending_total='0.0000';order.gross_received='0.0000';order.credit_received_total='22.0000';return route.fulfill({json:{data:{order,credit_application_id:1,replay:false}}});
    });
    await openSales(page);await selectMaria(page);await add(page,'Gafas de Sol');await summary(page);await page.getByRole('button',{name:'Facturar y enviar',exact:true}).click();await expect(page.getByRole('status').filter({hasText:'Venta registrada'})).toBeVisible();await page.getByRole('button',{name:'Saldo a favor del cliente',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Aplicar saldo a favor',exact:true});await dialog.getByRole('button',{name:'Seleccionar crédito del pago #77',exact:true}).click();await dialog.getByLabel('Motivo de la aplicación').fill('Crédito anterior usado en esta venta');await dialog.getByRole('button',{name:'Aplicar crédito al pedido',exact:true}).click();await expect(dialog).toContainText('Saldo a favor aplicado. No se registró un nuevo cobro.');expect(posts).toBe(1);expect(sales.orders[0].gross_received).toBe('0.0000');expect(sales.orders[0].paid_total).toBe('22.0000');expect(sales.writes).toHaveLength(1);
});

test('a saved direct sale exposes payment history and updates its pending balance after reversal',async({page,sales})=>{
    let reversed=false;let posts=0;
    await page.route(/\/sales\/orders\/1\/payments(?:\/.*)?$/,async route=>{
        if(route.request().method()==='GET')return route.fulfill({json:{data:[{id:1,amount:'22.0000',currency:'USD',status:reversed?'reversed':'confirmed',payment_method:'cash',paid_at:'2026-11-13 12:00:00',reversal:null}]}});
        posts++;reversed=true;const order=sales.orders[0];order.paid_total='0.0000';order.pending_total='22.0000';order.lock_version++;order.invoice!.status='issued';return route.fulfill({json:{data:{order,reversal_id:1,replay:false}}});
    });
    await openSales(page);await selectMaria(page);await add(page,'Gafas de Sol');await summary(page);await page.locator('.sales-adjustments summary').click();await page.getByLabel('Pago recibido USD',{exact:true}).fill('22');await page.getByRole('button',{name:'Facturar y enviar',exact:true}).click();await expect(page.getByRole('status').filter({hasText:'Venta registrada'})).toBeVisible();
    await page.getByRole('button',{name:'Pagos y reversiones',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Pagos y reversiones',exact:true});await dialog.getByRole('button',{name:'Revertir pago #1',exact:true}).click();await dialog.getByLabel('Motivo de la reversión').fill('Cobro de prueba registrado por error');await dialog.getByRole('button',{name:'Revertir pago por error',exact:true}).click();await expect(dialog).toContainText('Pago revertido. El saldo del pedido se actualizó.');await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();await expect(page.locator('.sales-total-emphasis')).toContainText('US$ 22.00');expect(posts).toBe(1);expect(sales.orders[0].status).toBe('fulfilled');
});

test('direct sale receives returned merchandise while preserving its invoice and paid total',async({page,sales})=>{
    let posts=0;let received=false;
    await page.route(/\/sales\/orders\/1\/returns$/,async route=>{
        const order=sales.orders[0];
        if(route.request().method()==='GET')return route.fulfill({json:{data:{order_id:1,version:order.lock_version,invoice:{id:1,number:'FAC-1-1'},warehouse_name:'Almacén principal',review_hash:'a'.repeat(64),return_pending:received,lines:[{invoice_item_id:1,variant_id:3,name:'Gafas de Sol',sku:'GAF-N',invoiced_quantity:'1.0000',delivered_quantity:'1.0000',received_quantity:received?'1.0000':'0.0000',available_quantity:received?'0.0000':'1.0000',track_stock:true}],history:[]}}});
        posts++;received=true;order.return_pending=true;order.lock_version++;return route.fulfill({status:201,json:{data:{order,sales_return_id:1,economic_status:'pending_adjustment',replay:false}}});
    });
    await openSales(page);await selectMaria(page);await add(page,'Gafas de Sol');await summary(page);await page.locator('.sales-adjustments summary').click();await page.getByLabel('Pago recibido USD',{exact:true}).fill('22');await page.getByRole('button',{name:'Facturar y enviar',exact:true}).click();await expect(page.getByRole('status').filter({hasText:'Venta registrada'})).toBeVisible();
    await page.getByRole('button',{name:'Devoluciones de mercancía',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Recepción de mercancía devuelta',exact:true});await dialog.getByRole('textbox',{name:'Cantidad a recibir de GAF-N',exact:true}).fill('1');await dialog.getByLabel('Motivo de la recepción').fill('Artículo recibido sin uso para revisión');await dialog.getByRole('button',{name:'Registrar recepción física',exact:true}).click();await expect(dialog).toContainText('Recepción #1 registrada. Ajuste económico pendiente.');
    expect(posts).toBe(1);expect(sales.orders[0].paid_total).toBe('22.0000');expect(sales.orders[0].grand_total).toBe('22.0000');expect(sales.orders[0].invoice?.invoice_number).toBe('FAC-1-1');
});

test('reservation starts as draft and becomes active only after save with pending96', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); for (const name of ['Camiseta Básica', 'Jeans Mom Fit', 'Gafas de Sol', 'Perfume Floral']) await add(page, name);
    await summary(page);await page.locator('.sales-adjustments summary').click(); await page.getByLabel('Envío USD', { exact: true }).fill('5'); await page.getByLabel('Pago recibido USD', { exact: true }).fill('50');
    await expect(page.locator('.sales-total-emphasis')).toContainText('US$ 146.00');expect(sales.orders).toHaveLength(0);
    await page.getByRole('button', { name: 'Guardar como apartado', exact: true }).click();
    await expect(page.getByRole('heading',{name:'Tablero de Apartados',exact:true})).toBeVisible();await expect(page.locator('.basket-pending')).toContainText('US$ 96.00');expect(sales.orders[0].status).toBe('reserved');expect(sales.orders).toHaveLength(1);
    await page.getByRole('button',{name:'Seleccionar apartado 1 de María Demo',exact:true}).click();await expect(page.locator('.basket-pending')).toContainText('US$ 96.00');
});

test('lost confirmation retries the same request without a duplicate order', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); await add(page, 'Gafas de Sol'); await summary(page);
    await expect(page.getByRole('button', { name: 'Facturar y enviar', exact: true })).toBeEnabled(); sales.lostResponse = true;
    await page.getByRole('button', { name: 'Facturar y enviar', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('No hay conexión');
    await expect(page.getByRole('button', { name: 'Reintentar la misma operación', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Reintentar la misma operación', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Operación recuperada sin duplicados' })).toBeVisible(); expect(sales.orders).toHaveLength(1); expect(sales.writes).toHaveLength(2); expect(sales.writes[1]).toEqual(sales.writes[0]);
});

test('customer creation selects the new record and keeps actions visible with keyboard viewport', async ({ page, sales }) => {
    await openSales(page); await summary(page); await page.locator('button.sales-selected-customer').click(); await page.getByRole('button', { name: '+ Nuevo cliente', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo cliente', exact: true }); await dialog.getByLabel('Nombre completo', { exact: true }).fill('Ana Demo'); await dialog.getByLabel('Teléfono', { exact: true }).fill('+1 202-555-0103');
    await page.setViewportSize({ width: 1024, height: 380 });
    const create=dialog.getByRole('button', { name: 'Crear y seleccionar', exact: true });
    // WebKit's intersection ratio can round to .99992 while the complete CSS box is visible.
    await expect(create).toBeInViewport({ratio:.99});
    await expect.poll(async()=>{const box=await create.boundingBox();return !!box&&box.y>=-1&&box.y+box.height<=380+1;}).toBe(true);
    await dialog.getByRole('button', { name: 'Crear y seleccionar', exact: true }).click(); await expect(dialog).not.toBeVisible(); await expect(page.locator('.sales-selected-customer')).toContainText('Ana Demo'); expect(sales.customers).toHaveLength(2);
});

test('variant selection and zero stock prevent adding an unavailable item', async ({ page, sales }) => {
    await openSales(page); await page.getByRole('button', { name: 'Elegir Camiseta Básica', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Elegir variante de Camiseta Básica', exact: true }); await expect(dialog.getByRole('button', { name: 'Agregar a la venta', exact: true })).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Variante de Camiseta Básica', exact: true }).selectOption('10'); await expect(dialog.getByRole('button', { name: 'Agregar a la venta', exact: true })).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Variante de Camiseta Básica', exact: true }).selectOption('1'); await dialog.getByRole('button', { name: 'Agregar a la venta', exact: true }).click(); await summary(page); await expect(page.getByRole('button', { name: 'Sumar CAM-M', exact: true })).toBeVisible(); expect(sales.orders).toHaveLength(0);
});

test('clothing selection chooses color before size and sends the exact variant', async ({ page, sales }) => {
    sales.products.push({id:99,name:'Blusa',category_id:1,track_stock:true,unit_code:'unit',image:null,variants:[
        {id:201,sku:'BLUSA-VS',price:'25.0000',available:'5.0000',attributes:[{name:'Color',value:'Verde'},{name:'Talla',value:'S'}]},
        {id:202,sku:'BLUSA-VM',price:'25.0000',available:'3.0000',attributes:[{name:'Color',value:'Verde'},{name:'Talla',value:'M'}]},
        {id:203,sku:'BLUSA-NS',price:'25.0000',available:'2.0000',attributes:[{name:'Color',value:'Negro'},{name:'Talla',value:'S'}]},
        {id:204,sku:'BLUSA-NM',price:'25.0000',available:'0.0000',attributes:[{name:'Color',value:'Negro'},{name:'Talla',value:'M'}]},
    ]});
    await openSales(page);await selectMaria(page);await products(page);await page.getByRole('button',{name:'Elegir Blusa',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Elegir variante de Blusa'});const color=dialog.getByLabel('Color de Blusa');const size=dialog.getByLabel('Talla de Blusa');
    await expect(size).toBeDisabled();await color.selectOption('Verde');await size.selectOption('M');await expect(dialog).toContainText('Disponible: 3');
    await color.selectOption('Negro');await expect(size).toHaveValue('');await expect(size.locator('option[value="M"]')).toBeDisabled();await expect(size.locator('option[value="L"]')).toHaveCount(0);
    await color.selectOption('Verde');await size.selectOption('S');await expect(dialog).toContainText('Cantidad inicial: 1');await expect(dialog).toContainText('Disponible: 5');
    await dialog.getByRole('button',{name:'Agregar a la venta'}).click();await summary(page);expect(sales.writes).toHaveLength(0);
    await page.getByRole('button',{name:'Guardar como apartado',exact:true}).click();await expect.poll(()=>sales.writes.length).toBe(1);
    expect(sales.writes[0].lines).toEqual([{variant_id:201,quantity:'1.0000'}]);
});

test('cart and USD quote survive orientation changes without hidden horizontal overflow', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); await add(page, 'Jeans Mom Fit'); await summary(page);
    await expect(page.locator('.sales-total-emphasis')).toContainText('US$ 45.00');
    await page.setViewportSize({ width: 1024, height: 768 }); await expect(page.getByRole('button', { name: 'Sumar JEA-28', exact: true })).toBeVisible();
    await page.setViewportSize({ width: 768, height: 1024 }); await expect(page.locator('.sales-total-emphasis')).toContainText('US$ 45.00');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBeFalsy(); expect(sales.orders).toHaveLength(0);
    await page.setViewportSize({ width: 390, height: 844 }); await summary(page);
    const geometry = await page.evaluate(() => ({ lastLineBottom: document.querySelector('.sales-order-line')!.getBoundingClientRect().bottom, totalsTop: document.querySelector('.sales-totals')!.getBoundingClientRect().top, totalsBottom: document.querySelector('.sales-totals')!.getBoundingClientRect().bottom, actionsTop: document.querySelector('.sales-summary-actions')!.getBoundingClientRect().top }));
    expect(geometry.totalsTop).toBeGreaterThanOrEqual(geometry.lastLineBottom);
    expect(geometry.actionsTop).toBeGreaterThanOrEqual(geometry.totalsBottom);
});

test('customer page submits its own form while a sales workspace remains cached', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); await add(page, 'Gafas de Sol');
    if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: 'Abrir menú', exact: true }).click();
    await page.getByRole('button', { name: 'Clientes', exact: true }).click(); await page.getByRole('button', { name: '+ Nuevo cliente', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo cliente', exact: true }); await dialog.getByLabel('Nombre completo', { exact: true }).fill('Cliente de formulario correcto');
    await dialog.getByRole('button', { name: 'Crear y seleccionar', exact: true }).click(); await expect(dialog).not.toBeVisible();
    expect(sales.customers).toHaveLength(2); expect(sales.customers[1].full_name).toBe('Cliente de formulario correcto');
    if ((page.viewportSize()?.width || 0) < 768) await page.getByRole('button', { name: 'Abrir menú', exact: true }).click();
    await page.getByRole('button', { name: 'Ventas', exact: true }).click(); await summary(page); await expect(page.getByRole('button', { name: 'Sumar GAF-N', exact: true })).toBeVisible();
    await expect(page.locator('.sales-selected-customer')).toContainText('María Demo');
});

test('archiving a selected customer updates the cached sale without discarding its cart', async ({page,sales}) => {
    await openSales(page); await selectMaria(page); await add(page,'Gafas de Sol');
    async function navigate(name:string) {
        if((page.viewportSize()?.width || 0)<768) await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
        await page.getByRole('button',{name,exact:true}).click();
    }
    await navigate('Clientes');
    await page.locator('.sales-customer').filter({hasText:'María Demo'}).click();
    await page.getByRole('button',{name:'Archivar cliente',exact:true}).click();
    await page.getByRole('button',{name:'Confirmar archivo',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Archivar cliente',exact:true})).not.toBeVisible();
    await navigate('Ventas'); await summary(page);
    await expect(page.getByText('Cliente archivado. Selecciona un cliente activo para registrar una nueva venta.',{exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Facturar y enviar',exact:true})).toBeDisabled();
    await expect(page.getByRole('button',{name:'Sumar GAF-N',exact:true})).toBeVisible();
    await navigate('Clientes');
    await page.getByRole('combobox',{name:'Estado de clientes'}).selectOption('archived');
    await page.locator('.sales-customer').filter({hasText:'María Demo'}).click();
    await page.getByRole('button',{name:'Reactivar cliente',exact:true}).click();
    await page.getByRole('button',{name:'Confirmar reactivación',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Reactivar cliente',exact:true})).not.toBeVisible();
    await navigate('Ventas'); await summary(page);
    await expect(page.getByRole('button',{name:'Facturar y enviar',exact:true})).toBeEnabled();
    await expect(page.getByRole('button',{name:'Sumar GAF-N',exact:true})).toBeVisible();
    expect(sales.orders).toHaveLength(0);
});

test('switching branches keeps independent drafts and restores the original branch safely', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); await add(page, 'Jeans Mom Fit'); await summary(page);
    await page.getByRole('combobox', { name: 'SUCURSAL ACTIVA', exact: true }).selectOption('2');
    await expect(page.locator('.sales-order-line')).toHaveCount(0);
    await page.getByRole('combobox', { name: 'SUCURSAL ACTIVA', exact: true }).selectOption('1'); await summary(page);
    await expect(page.getByRole('button', { name: 'Sumar JEA-28', exact: true })).toBeVisible(); expect(sales.orders).toHaveLength(0);
});

test('reload after a lost committed response recovers the original order without resubmitting', async ({ page, sales }) => {
    await openSales(page); await selectMaria(page); await add(page, 'Gafas de Sol'); await summary(page);
    await expect(page.getByRole('button', { name: 'Facturar y enviar', exact: true })).toBeEnabled(); sales.lostResponse = true;
    await page.getByRole('button', { name: 'Facturar y enviar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Reintentar la misma operación', exact: true })).toBeVisible();
    await expect.poll(() => sales.orders.length).toBe(1);
    await expect(page.getByRole('button', { name: 'Reintentar la misma operación', exact: true })).toBeEnabled();
    await page.reload(); await summary(page);
    await expect(page.getByRole('status').filter({ hasText: 'Operación recuperada tras recargar' })).toBeVisible();
    expect(sales.orders).toHaveLength(1); expect(sales.writes).toHaveLength(1);
});

test('failed private PDF download stays visible and retries without issuing another sale', async ({page,sales}) => {
    await openSales(page); await selectMaria(page); await add(page,'Gafas de Sol'); await summary(page);
    await page.getByRole('button',{name:'Facturar y enviar',exact:true}).click();
    await expect(page.getByRole('status').filter({hasText:'Venta registrada'})).toBeVisible();
    let unavailable = true;
    await page.route('**/invoices/1/document', async route => {
        if (route.request().method() === 'GET' && unavailable) return route.fulfill({status:503,json:{message:'Documento temporalmente no disponible.'}});
        return route.fallback();
    });
    await page.getByRole('button',{name:'Descargar factura PDF',exact:true}).click();
    await expect(page.getByRole('alert').filter({hasText:'No se pudo completar la operación'})).toBeVisible();
    unavailable = false;
    const downloaded = page.waitForEvent('download');
    await page.getByRole('button',{name:'Descargar factura PDF',exact:true}).click();
    expect((await downloaded).suggestedFilename()).toContain('.pdf');
    expect(sales.orders).toHaveLength(1); expect(sales.writes).toHaveLength(1);
});

for (const target of ['reservation', 'sale'] as const) {
    test('single cart preserves items and customer before ' + target, async ({page, sales}) => {
        await openSales(page);
        await add(page, 'Gafas de Sol');
        await summary(page);
        const url = page.url();
        await expect(page.locator('.sales-mode-switch')).toHaveCount(0);
        await expect(page.getByRole('button', {name:'Nuevo apartado', exact:true})).toHaveCount(0);
        await expect(page).toHaveURL(url);
        await expect(page.locator('.sales-order-lines')).toContainText('Gafas de Sol');
        expect(sales.orders).toHaveLength(0);
        await selectMaria(page);
        await summary(page);
        await expect(page.locator('button.sales-selected-customer')).toContainText('María Demo');
        await page.getByRole('button', {name:target === 'sale' ? 'Facturar y enviar' : 'Guardar como apartado', exact:true}).click();
        await expect.poll(() => sales.orders.length).toBe(1);
        expect(sales.writes[0].mode).toBe(target);
        expect(sales.writes[0].customer_id).toBe(1);
        expect(sales.writes[0].lines).toEqual([{variant_id:3,quantity:'1.0000'}]);
    });
}
