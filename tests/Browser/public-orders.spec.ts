import {test as base,expect} from './tablet.fixture';
const token='c'.repeat(64);
type State={confirmed:boolean;lost:boolean;conflict:boolean;price:string;posts:any[];sessionReads:number};
const test=base.extend<{publicState:State}>({publicState:async({page,businessState},use)=>{
    const state:State={confirmed:false,lost:false,conflict:false,price:'25.0000',posts:[],sessionReads:0};
    const receipt=()=>({number:12,status:'reserved',currency:'USD',total:state.price==='25.0000'?'55.0000':'65.0000',items:[{name:'Bolso autorizado',quantity:'2.0000',price:state.price,total:state.price==='25.0000'?'50.0000':'60.0000'}]});
    await page.route('**/api/v1/session',route=>{state.sessionReads++;return route.fulfill({status:401,json:{message:'Guest'}});});
    await page.route('**/api/v1/order-requests/**',async route=>{
        const url=new URL(route.request().url());const path=url.pathname.replace(`/api/v1/order-requests/${token}`,'');
        if(path==='')return route.fulfill({json:{data:{store_name:'KeylaShop pública',currency:'USD',status:state.confirmed?'used':'active',expires_at:'2026-11-21T12:00:00Z',shipping:'5.0000',receipt:state.confirmed?receipt():null}}});
        if(path==='/catalog')return route.fulfill({json:{data:[{id:1,name:'Bolso autorizado',sku:'REQUEST-QA',price:state.price,available:true,attributes:[{name:'Color',value:'Violeta'}],image_url:null}],meta:{current_page:1,last_page:1,total:1}}});
        if(path==='/quote')return route.fulfill({json:{data:{currency:'USD',subtotal:state.price==='25.0000'?'50.0000':'60.0000',tax_total:'0.0000',shipping_total:'5.0000',grand_total:state.price==='25.0000'?'55.0000':'65.0000',quote_hash:state.price==='25.0000'?'a'.repeat(64):'b'.repeat(64),lines:[{variant_id:1,unit_price:state.price,line_total:state.price==='25.0000'?'50.0000':'60.0000'}]}}});
        if(path==='/confirm'){
            state.posts.push(route.request().postDataJSON());
            if(state.conflict){state.conflict=false;state.price='30.0000';return route.fulfill({status:409,json:{message:'Los precios cambiaron.'}});}
            state.confirmed=true;if(state.lost){state.lost=false;return route.abort('failed');}
            return route.fulfill({status:201,json:{data:{receipt:receipt(),replay:false}}});
        }
        return route.fulfill({status:404,json:{message:'Unavailable'}});
    });
    await use(state);expect(businessState.writes).toBe(0);expect(state.sessionReads).toBe(0);
}});
async function cart(page:import('@playwright/test').Page){
    await page.goto('/s/'+token);
    await expect(page.getByRole('heading',{name:'KeylaShop pública',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Agregar REQUEST-QA',exact:true}).click();
    await page.getByRole('region',{name:'Tu pedido',exact:true}).getByRole('textbox',{name:'Cantidad de Bolso autorizado · Color: Violeta',exact:true}).fill('2');
}

test('guest cart survives rotation and confirms the server quote without logging in',async({page,publicState})=>{
    await cart(page);await page.setViewportSize({width:1024,height:380});
    await expect(page.getByRole('textbox',{name:'Cantidad de Bolso autorizado · Color: Violeta',exact:true})).toHaveValue('2');
    await page.getByRole('button',{name:'Revisar pedido',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Revisar pedido',exact:true});
    await expect(dialog).toContainText('US$ 55.00');
    await dialog.getByRole('button',{name:'Confirmar pedido',exact:true}).click();
    await expect(page.getByRole('region',{name:'Pedido confirmado'})).toContainText('Pedido #12 confirmado');
    expect(publicState.posts).toHaveLength(1);expect(publicState.posts[0].lines).toEqual([{variant_id:1,quantity:'2'}]);
    expect(publicState.posts[0].quote_hash).toBe('a'.repeat(64));
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)).toBeFalsy();
});

test('reload after a lost public confirmation recovers by GET without resubmitting',async({page,publicState})=>{
    await cart(page);publicState.lost=true;
    await page.getByRole('button',{name:'Revisar pedido',exact:true}).click();
    await page.getByRole('dialog').getByRole('button',{name:'Confirmar pedido',exact:true}).click();
    await expect(page.getByRole('button',{name:'Reintentar confirmación',exact:true})).toBeEnabled();
    await page.reload();
    await expect(page.getByRole('region',{name:'Pedido confirmado'})).toContainText('US$ 55.00');
    expect(publicState.posts).toHaveLength(1);
});

test('changed prices preserve the public cart and require reviewing the new total',async({page,publicState})=>{
    await cart(page);publicState.conflict=true;
    await page.getByRole('button',{name:'Revisar pedido',exact:true}).click();
    await page.getByRole('dialog').getByRole('button',{name:'Confirmar pedido',exact:true}).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByRole('textbox',{name:'Cantidad de Bolso autorizado · Color: Violeta',exact:true})).toHaveValue('2');
    await page.getByRole('button',{name:'Revisar pedido',exact:true}).click();
    await expect(page.getByRole('dialog')).toContainText('US$ 65.00');
    await expect(page.getByRole('dialog')).toContainText('US$ 30.00');
    await page.getByRole('dialog').getByRole('button',{name:'Confirmar pedido',exact:true}).click();
    await expect(page.getByRole('region',{name:'Pedido confirmado'})).toContainText('US$ 65.00');
    expect(publicState.posts).toHaveLength(2);expect(publicState.posts[1].request_key).not.toBe(publicState.posts[0].request_key);
});
