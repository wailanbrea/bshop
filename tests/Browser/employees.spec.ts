import { test as base, expect } from './tablet.fixture';

type State = { employees:any[]; plans:any[]; writes:any[]; lost:boolean; failPlans:boolean };
const test = base.extend<{ staff:State }>({ staff:async({page,businessState},use)=>{
    const state:State={employees:[],plans:[],writes:[],lost:false,failPlans:false};const replies=new Map<string,any>();
    await page.route('**/api/v1/business/stores/*/employees**',async route=>{
        const url=new URL(route.request().url());const method=route.request().method();const isPlans=url.pathname.endsWith('/plans');
        if(method==='GET'){if(isPlans&&state.failPlans){state.failPlans=false;return route.fulfill({status:500,json:{message:'No disponible'}});}return route.fulfill({json:isPlans?{data:state.plans,summary:{currency:'USD',orders_count:3,sales_net:'450.0000',collected_net:'300.0000',earned_commission:'30.0000'}}:{data:state.employees,meta:{current_page:1,last_page:1,total:state.employees.length}}});}
        if(method!=='POST')return route.fulfill({status:403});
        const data=route.request().postDataJSON();state.writes.push({isPlans,data});let result=replies.get(data.request_key);
        if(!result){if(isPlans){const plan={id:state.plans.length+1,...data,currency:'USD'};state.plans.push(plan);result={plan,replay:false};}else{const employee={id:state.employees.length+1,employee_code:data.employee_code.toUpperCase(),full_name:data.full_name,status:'active'};state.employees.push(employee);result={employee,replay:false};}replies.set(data.request_key,result);}
        else result={...result,replay:true};
        if(state.lost){state.lost=false;return route.abort('failed');}
        return route.fulfill({json:{data:result}});
    });
    await use(state);expect(businessState.writes).toBe(0);
}});

test('seller and contract forms work across rotation without generating payroll',async({page,staff})=>{
    await page.goto('/app/vendedores');await page.getByRole('button',{name:'Nuevo vendedor'}).click();
    const seller=page.getByRole('dialog',{name:'Nuevo vendedor'});await seller.getByLabel('Nombre',{exact:true}).fill('Ana Vendedora');await seller.getByLabel('Código',{exact:true}).fill('ANA-01');
    await page.setViewportSize({width:1024,height:380});await expect(seller.getByLabel('Nombre',{exact:true})).toHaveValue('Ana Vendedora');await seller.getByRole('button',{name:'Guardar vendedor'}).click();
    await expect(page.getByRole('region',{name:'Contratos del vendedor'})).toContainText('Ana Vendedora');
    await page.getByRole('button',{name:'Nuevo contrato'}).click();const contract=page.getByRole('dialog',{name:'Nuevo contrato'});
    await contract.getByLabel('Porcentaje de comisión').fill('12,5');await contract.getByLabel('Vigente desde').fill('2026-12-01');
    await expect(contract.getByRole('button',{name:'Guardar contrato'})).toBeInViewport();await contract.getByRole('button',{name:'Guardar contrato'}).click();
    await expect(page.getByRole('region',{name:'Contratos del vendedor'})).toContainText('12.5 %');
    await expect(page.getByText('El registro de contratos no calcula nómina ni genera pagos o comisiones todavía.')).toBeVisible();
    expect(staff.plans).toHaveLength(1);expect(staff.plans[0].commission_rate).toBe('12.5');expect(staff.writes).toHaveLength(2);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('lost seller creation retries the same key without creating a second employee',async({page,staff})=>{
    await page.goto('/app/vendedores');staff.lost=true;await page.getByRole('button',{name:'Nuevo vendedor'}).click();
    const dialog=page.getByRole('dialog',{name:'Nuevo vendedor'});await dialog.getByLabel('Nombre',{exact:true}).fill('Marta QA');await dialog.getByLabel('Código',{exact:true}).fill('MAR-01');await dialog.getByRole('button',{name:'Guardar vendedor'}).click();
    await expect(page.getByRole('alert')).toContainText('No hay conexión');
    await expect(page.getByRole('button',{name:'Reintentar la misma operación'})).toBeVisible();
    await page.reload();await page.getByRole('button',{name:'Reintentar la misma operación'}).click();
    await expect(page.getByRole('region',{name:'Contratos del vendedor'})).toContainText('Marta QA');
    expect(staff.employees).toHaveLength(1);expect(staff.writes).toHaveLength(2);expect(staff.writes[0].data.request_key).toBe(staff.writes[1].data.request_key);
});

test('a failed contract lookup can be retried without showing a false empty state',async({page,staff})=>{
    staff.employees.push({id:1,employee_code:'ANA',full_name:'Ana QA',status:'active'});
    staff.plans.push({id:1,pay_type:'commission',commission_rate:'10.0000',currency:'USD',effective_from:'2026-11-01',effective_to:null});staff.failPlans=true;
    await page.goto('/app/vendedores');await page.getByRole('button',{name:/Ana QA/}).click();
    const detail=page.getByRole('region',{name:'Contratos del vendedor'});await expect(page.getByRole('alert')).toBeVisible();
    await expect(detail.getByText('No hay contratos registrados.')).toHaveCount(0);await expect(detail.getByRole('button',{name:'Nuevo contrato'})).toBeDisabled();
    await detail.getByRole('button',{name:'Reintentar contratos'}).click();await expect(detail).toContainText('10.0000 %');await expect(detail.getByRole('button',{name:'Nuevo contrato'})).toBeEnabled();
    expect(staff.writes).toHaveLength(0);
});
