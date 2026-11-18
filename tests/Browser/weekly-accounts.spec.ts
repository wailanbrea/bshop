import {test as base,expect,business,permissions,stores} from './tablet.fixture';
type State={account:any;writes:any[];lost:boolean;conflict:boolean;loadFailures:number;closeWeekday:number;dueCount:number};
const test=base.extend<{weekly:State}>({weekly:async({page,businessState},use)=>{
    const access=[...permissions,'sales.view','customers.view','sales.create','invoices.view'];
    const state:State={account:{id:1,customer_id:1,customer_name:'María Semanal',period_start:'2026-11-09',period_end:'2026-11-15',timezone:'America/Santo_Domingo',currency:'USD',status:'open',lock_version:0,review_hash:'a'.repeat(64),orders:[{id:1,number:12,version:0,status:'reserved',included:true,total:'25.0000',paid:'5.0000',pending:'20.0000',invoice_id:null}],totals:{grand_total:'25.0000',paid_total:'5.0000',pending_total:'20.0000'},invoice:null},writes:[],lost:false,conflict:false,loadFailures:0,closeWeekday:4,dueCount:0};
    const replies=new Set<string>();
    await page.route('**/api/v1/session',r=>r.fulfill({json:{data:{user:{id:1,name:'QA',email:'qa@example.test'},business:{...business,default_currency:'USD'},roles:['QA'],permissions:access}}}));
    await page.route('**/api/v1/business/workspace',r=>r.fulfill({json:{data:{business:{...business,default_currency:'USD'},stores,permissions:access}}}));
    await page.route('**/api/v1/business/weekly/settings',route=>{if(route.request().method()==='PATCH'){const body=route.request().postDataJSON();if(body.previous_weekday!==state.closeWeekday)return route.fulfill({status:409,json:{message:'Día modificado'}});state.closeWeekday=body.close_weekday;}return route.fulfill({json:{data:{close_weekday:state.closeWeekday}}});});
    await page.route('**/api/v1/business/stores/**',async route=>{
        const url=new URL(route.request().url());const path=url.pathname.replace(/^\/api\/v1\/business\/stores\/\d+/,'');const method=route.request().method();
        if(path==='/customers')return route.fulfill({json:{data:[{id:1,full_name:'María Semanal',phone:'QA',status:'active',lock_version:0}],meta:{current_page:1,last_page:1,total:1}}});
        if(path==='/weekly-accounts'&&method==='GET'){if(state.loadFailures>0){state.loadFailures--;return route.fulfill({status:401,json:{message:'Unauthenticated.'}});}const filter=url.searchParams.get('status')||'open';const visible=filter==='all'||state.account.status===filter||(filter==='due'&&state.dueCount>0&&state.account.status==='open');return route.fulfill({json:{data:visible?[state.account]:[],meta:{current_page:1,last_page:1,total:visible?1:0,due_count:state.dueCount}}});}
        if(path.includes('/weekly-accounts/requests/')){const key=path.split('/').pop()!;return replies.has(key)?route.fulfill({json:{data:{account:state.account,replay:true}}}):route.fulfill({status:404,json:{message:'Not confirmed'}});}
        if(/^\/weekly-accounts\/\d+$/.test(path)&&method==='GET')return route.fulfill({json:{data:state.account}});
        if(path.endsWith('/candidates'))return route.fulfill({json:{data:state.account.id===1&&state.account.status==='open'&&!state.account.orders.some((o:any)=>o.id===2)?[{id:2,number:13,total:'25.0000',currency:'USD',created_at:'2026-11-12T12:00:00Z'}]:[],meta:{current_page:1,last_page:1,total:1}}});
        if(path.startsWith('/weekly-accounts')&&method==='POST'){
            const body=route.request().postDataJSON();state.writes.push({path,body});
            if(path.endsWith('/close')&&state.conflict){state.conflict=false;state.account.orders[0].paid='6.0000';state.account.totals.paid_total='6.0000';state.account.totals.pending_total='19.0000';state.account.review_hash='b'.repeat(64);return route.fulfill({status:409,json:{message:'Un abono cambió durante la revisión.'}});}
            if(!replies.has(body.request_key)){
                if(path==='/weekly-accounts'){state.account={...state.account,id:2,period_start:body.period_start,period_end:'2026-11-22',orders:[],totals:{grand_total:'0.0000',paid_total:'0.0000',pending_total:'0.0000'},lock_version:0};}
                else if(path.endsWith('/attach')){state.account.orders.push({id:2,number:13,version:0,status:'reserved',included:true,total:'25.0000',paid:'0.0000',pending:'25.0000',invoice_id:null});state.account.totals.grand_total='50.0000';state.account.totals.pending_total='45.0000';state.account.lock_version++;}
                else if(path.endsWith('/cancel')){state.account.status='cancelled';state.account.lock_version++;}
                else if(path.endsWith('/close')){state.account.status='closed';state.account.lock_version++;state.account.invoice={id:10,invoice_number:'FAC-1-000010',status:'partially_paid'};state.dueCount=0;}
                replies.add(body.request_key);
            }
            if(path.endsWith('/close')&&state.lost){state.lost=false;return route.abort('failed');}
            return route.fulfill({json:{data:{account:state.account,replay:false,document:{status:'ready'}}}});
        }
        return route.fallback();
    });
    await use(state);expect(businessState.writes).toBe(0);
}});
async function open(page:import('@playwright/test').Page){await page.goto('/app/cuentas-semanales');await expect(page.getByRole('region',{name:'Cuentas semanales',exact:true})).toBeVisible();await page.locator('.weekly-card').first().click();await expect(page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true})).toBeVisible();}

test('weekly list explains an authentication failure and clears it after a successful retry',async({page,weekly})=>{
    weekly.loadFailures=1;await page.goto('/app/cuentas-semanales');
    await expect(page.getByRole('alert')).toContainText('Tu sesión ha caducado o no está disponible. Recarga la página e inicia sesión de nuevo.');
    await expect(page.getByRole('link',{name:'Ir a iniciar sesión'})).toHaveAttribute('href','/login');
    await expect(page.getByText('No hay cuentas con este filtro.')).toHaveCount(0);
    await page.getByRole('button',{name:'Actualizar cuentas',exact:true}).click();
    await expect(page.getByRole('alert')).toHaveCount(0);await expect(page.locator('.weekly-card').first()).toContainText('María Semanal');
});

test('configured cutoff suggests the current business-local period and remains editable',async({page,weekly})=>{
    await page.clock.setFixedTime(new Date('2026-11-15T12:00:00Z'));
    await page.goto('/');if((page.viewportSize()?.width||0)<768)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
    await page.getByRole('button',{name:'Configuración',exact:true}).click();
    const settings=page.getByRole('region',{name:'Corte semanal',exact:true});
    await settings.getByLabel('Día de corte').selectOption('6');await settings.getByRole('button',{name:'Guardar día de corte'}).click();
    await expect(settings.getByRole('status')).toContainText('Día de corte guardado.');expect(weekly.closeWeekday).toBe(6);
    await page.goto('/app/cuentas-semanales');await page.getByRole('button',{name:'Nueva cuenta semanal',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Nueva cuenta semanal',exact:true});
    await expect(dialog.getByLabel('Inicio de la semana')).toHaveValue('2026-11-15');
    await dialog.getByLabel('Inicio de la semana').fill('2026-11-16');await expect(dialog.getByLabel('Inicio de la semana')).toHaveValue('2026-11-16');
});

test('due account prompts review without closing or invoicing automatically',async({page,weekly})=>{
    weekly.dueCount=1;await page.goto('/app/cuentas-semanales');
    await expect(page.getByText(/1 cuenta llegó a su día de corte/)).toBeVisible();
    await page.getByRole('button',{name:'Ver cuentas por revisar'}).click();
    await expect(page.getByLabel('Estado de cuentas')).toHaveValue('due');
    await page.locator('.weekly-card').first().click();
    await expect(page.getByRole('button',{name:'Revisar cierre semanal'})).toBeVisible();
    expect(weekly.writes).toHaveLength(0);expect(weekly.account.invoice).toBeNull();
});

test('weekly grouping and explicit closure retain totals on rotation',async({page,weekly})=>{
    await open(page);await page.getByRole('button',{name:'Agregar apartado #13',exact:true}).click();
    const detail=page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true});await expect(detail).toContainText('US$ 50.00');await expect(detail).toContainText('US$ 45.00');
    await page.getByRole('button',{name:'Revisar cierre semanal',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Confirmar cierre semanal',exact:true});await page.setViewportSize({width:1024,height:380});
    await expect(dialog).toContainText('US$ 50.00');await dialog.getByRole('button',{name:'Cerrar cuenta y facturar',exact:true}).click();
    await expect(detail).toContainText('FAC-1-000010');await expect(page.getByRole('button',{name:'Descargar factura semanal',exact:true})).toBeVisible();
    expect(weekly.writes.filter(w=>w.path.endsWith('/close'))).toHaveLength(1);expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('lost weekly closure recovers after reload without another invoice request',async({page,weekly})=>{
    await open(page);weekly.lost=true;
    await page.getByRole('button',{name:'Revisar cierre semanal',exact:true}).click();await page.getByRole('button',{name:'Cerrar cuenta y facturar',exact:true}).click();
    await expect(page.getByRole('button',{name:'Comprobar operación semanal',exact:true})).toBeEnabled();
    await page.reload();await expect(page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true})).toContainText('FAC-1-000010');
    await expect(page.getByText('Operación recuperada sin repetir cambios.',{exact:true})).toBeVisible();
    expect(weekly.writes.filter(w=>w.path.endsWith('/close'))).toHaveLength(1);
});

test('weekly stale review requires an explicit refresh and a new confirmation',async({page,weekly})=>{
    await open(page);weekly.conflict=true;
    await page.getByRole('button',{name:'Revisar cierre semanal',exact:true}).click();await page.getByRole('button',{name:'Cerrar cuenta y facturar',exact:true}).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('button',{name:'Actualizar revisión',exact:true}).click();
    await expect(page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true})).toContainText('US$ 19.00');
    await page.getByRole('button',{name:'Revisar cierre semanal',exact:true}).click();await page.getByRole('button',{name:'Cerrar cuenta y facturar',exact:true}).click();
    await expect(page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true})).toContainText('FAC-1-000010');
    const attempts=weekly.writes.filter(w=>w.path.endsWith('/close'));expect(attempts).toHaveLength(2);expect(attempts[1].body.request_key).not.toBe(attempts[0].body.request_key);expect(attempts[1].body.review_hash).toBe('b'.repeat(64));
});

test('new weekly account preserves selected customer and dates on rotation and empty cancellation retains history',async({page,weekly})=>{
    await page.goto('/app/cuentas-semanales');await page.getByRole('button',{name:'Nueva cuenta semanal',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Nueva cuenta semanal',exact:true});await dialog.getByRole('button',{name:/María Semanal/}).click();await dialog.getByLabel('Inicio de la semana').fill('2026-11-16');
    await page.setViewportSize({width:1024,height:380});await expect(dialog.getByLabel('Inicio de la semana')).toHaveValue('2026-11-16');
    await dialog.getByRole('button',{name:'Crear cuenta semanal',exact:true}).click();
    await expect(page.getByRole('region',{name:'Detalle de cuenta semanal',exact:true})).toContainText('2026-11-22');
    await page.getByRole('button',{name:'Anular cuenta vacía',exact:true}).click();await expect(page.getByText('Cuenta anulada. El historial se conserva.',{exact:true})).toBeVisible();
    expect(weekly.writes.filter(w=>w.path==='/weekly-accounts')).toHaveLength(1);expect(weekly.writes.filter(w=>w.path.endsWith('/cancel'))).toHaveLength(1);
});
