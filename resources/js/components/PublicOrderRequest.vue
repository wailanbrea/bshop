<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import { readPending, savePending, clearPending } from '../lib/pendingRequests';
import AppDialog from './AppDialog.vue';
import DecimalStepper from './DecimalStepper.vue';
import VariantSelector from './VariantSelector.vue';
import { available, supportsColorSize } from '../lib/variantSelection';
import type { SalesVariant } from '../sales';

type Product={id:number; name:string; image_url:string|null; variants:SalesVariant[]};
type Line={id:number; name:string; price:string; quantity:string};
type Quote={currency:string; subtotal:string; tax_total:string; shipping_total:string; grand_total:string; quote_hash:string; lines?:{variant_id:number;unit_price:string;line_total:string}[]};
type Receipt={number:number|string;status:string;currency:string;total:string;items:{name:string;quantity:string;price:string;total:string}[];return_pending?:boolean;original_total?:string;return_credit_total?:string;credit_available?:string;refunded_total?:string};
type Pending={body:{request_key:string;quote_hash:string;lines:{variant_id:number;quantity:string}[]};cart:Line[];quote:Quote};
const props=defineProps<{token:string}>();
const endpoint=`/order-requests/${props.token}`;
const storeName=ref('Pedido por enlace');const currency=ref('USD');const expires=ref('');const ready=ref(false);const error=ref('');const busy=ref(false);const loading=ref(false);
const products=ref<Product[]>([]);const query=ref('');const page=ref(1);const lastPage=ref(1);const cart=ref<Line[]>([]);const quote=ref<Quote|null>(null);const receipt=ref<Receipt|null>(null);
const selections=ref<Record<number,number|''>>({});
const detail=ref<Product|null>(null);const detailOpener=ref<HTMLElement|null>(null);
function openDetail(product:Product,event:Event){detailOpener.value=event.currentTarget as HTMLElement;detail.value=product;}
const catalogError=ref('');
const pending=ref<Pending|null>(null);const review=ref(false);const opener=ref<HTMLElement|null>(null);
let journal='';let timer:ReturnType<typeof setTimeout>|undefined;let version=0;let controller:AbortController|undefined;
const publicError=(e:unknown)=>axios.isAxiosError(e)&&e.response?.status===404 ? 'Este enlace ya no está disponible. Solicita uno vigente a la tienda.' : axios.isAxiosError(e)&&e.response?.status===419 ? 'Vuelve a abrir el enlace para renovar la sesión. Tu confirmación pendiente se conserva.' : errorMessage(e);
async function catalog(target=1){
    const current=++version;controller?.abort();controller=new AbortController();loading.value=true;catalogError.value='';
    try{const result=await api.get(`${endpoint}/catalog`,{signal:controller.signal,params:{q:query.value,page:target,group_products:1}});if(current===version){products.value=result.data.data.map((item:any)=>Array.isArray(item.variants)?item:{id:item.id,name:item.name,image_url:item.image_url,variants:[{id:item.id,sku:item.sku,price:item.price,available:item.available?'1.0000':'0.0000',attributes:item.attributes,image_url:item.image_url}]});selections.value={};page.value=result.data.meta.current_page;lastPage.value=result.data.meta.last_page;}}
    catch(e){if(current===version&&!axios.isCancel(e))catalogError.value=publicError(e);}
    finally{if(current===version)loading.value=false;}
}
function complete(value:Receipt){receipt.value=value;pending.value=null;review.value=false;cart.value=[];error.value='';try{clearPending(journal);clearPending(journal+':draft');}catch{/* Server receipt remains authoritative. */}}
async function info(){
    busy.value=true;error.value='';
    try{const value=(await api.get(endpoint)).data.data;storeName.value=value.store_name;currency.value=value.currency;expires.value=value.expires_at;ready.value=true;if(value.receipt)complete(value.receipt);else await catalog();}
    catch(e){error.value=publicError(e);}
    finally{busy.value=false;}
}
function selected(product:Product|null){if(!product)return;return product.variants.length===1&&!supportsColorSize(product.variants)?product.variants[0]:product.variants.find(v=>v.id===selections.value[product.id]);}
function add(product:Product|null){if(!product)return;const variant=selected(product);if(busy.value||pending.value||!variant||!available(variant))return;const existing=cart.value.find(line=>line.id===variant.id);if(existing)return;cart.value.push({id:variant.id,name:[product.name,...variant.attributes.map(a=>`${a.name}: ${a.value}`)].join(' · '),price:variant.price,quantity:'1'});quote.value=null;detail.value=null;}
function remove(id:number){if(!busy.value&&!pending.value){cart.value=cart.value.filter(line=>line.id!==id);quote.value=null;}}
async function preview(event:Event){
    if(busy.value||pending.value||!cart.value.length)return;opener.value=event.currentTarget as HTMLElement;busy.value=true;error.value='';
    try{const result:Quote=(await api.post(`${endpoint}/quote`,{lines:cart.value.map(line=>({variant_id:line.id,quantity:line.quantity}))})).data.data;quote.value=result;cart.value=cart.value.map(line=>({...line,price:result.lines?.find(item=>item.variant_id===line.id)?.unit_price??line.price}));review.value=true;}
    catch(e){error.value=publicError(e);}
    finally{busy.value=false;}
}
async function confirm(){
    if(busy.value||!quote.value)return;error.value='';
    if(!pending.value){
        const value:Pending={body:{request_key:crypto.randomUUID(),quote_hash:quote.value.quote_hash,lines:cart.value.map(line=>({variant_id:line.id,quantity:line.quantity}))},cart:structuredClone(cart.value.map(line=>({...line}))),quote:{...quote.value}};
        try{if(!journal)throw new Error('Recovery unavailable');savePending(journal,value as unknown as Record<string,unknown>);pending.value=value;}
        catch{error.value='No se pudo guardar la recuperación en este navegador. No se confirmó el pedido.';return;}
    }
    busy.value=true;
    try{complete((await api.post(`${endpoint}/confirm`,pending.value.body)).data.data.receipt);}
    catch(e){error.value=publicError(e);if(axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)){pending.value=null;review.value=false;quote.value=null;try{clearPending(journal);}catch{}}}
    finally{busy.value=false;}
}
watch(query,()=>{clearTimeout(timer);++version;controller?.abort();loading.value=true;timer=setTimeout(()=>catalog(),200);});
watch(cart,()=>{if(!journal||receipt.value||pending.value)return;try{savePending(journal+':draft',{cart:cart.value});}catch{}},{deep:true});
onMounted(async()=>{
    try{
        const hash=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(props.token));
        journal='bshop:public-order:'+Array.from(new Uint8Array(hash)).map(v=>v.toString(16).padStart(2,'0')).join('');
        const saved=readPending(journal) as unknown as Pending|null;
        if(saved&&typeof saved.body?.request_key==='string'&&Array.isArray(saved.cart)&&saved.quote?.quote_hash){pending.value=saved;cart.value=saved.cart;quote.value=saved.quote;}
        else{const draft=readPending(journal+':draft');if(Array.isArray(draft?.cart))cart.value=draft.cart.filter((v:any)=>Number.isInteger(v?.id)&&typeof v?.name==='string'&&typeof v?.quantity==='string'&&typeof v?.price==='string').slice(0,50);}
    }catch{error.value='La recuperación requiere almacenamiento y un navegador con conexión segura.';}
    await info();if(pending.value&&!receipt.value)review.value=true;
});
onUnmounted(()=>{++version;controller?.abort();clearTimeout(timer);});
</script>
<template>
<main class="public-order"><header><span class="eyebrow">BShop · Pedido por enlace</span><h1>{{ storeName }}</h1><p class="muted">Elige los artículos disponibles para preparar tu apartado.</p></header>
    <p v-if="error&&!review" class="notice error" role="alert">{{ error }}</p>
    <p v-if="busy&&!review" role="status">Consultando pedido…</p>
    <p v-if="receipt?.return_pending" class="notice">Hay mercancía devuelta con ajuste económico pendiente. Los importes mostrados corresponden al documento original; coordina la conciliación con la tienda.</p>
    <p v-if="receipt?.return_credit_total&&receipt.return_credit_total!=='0.0000'" class="notice">Original {{ amount(receipt.original_total,receipt.currency) }} · Ajuste por devolución {{ amount(receipt.return_credit_total,receipt.currency) }} · Saldo a favor {{ amount(receipt.credit_available||'0',receipt.currency) }} · Reembolso registrado {{ amount(receipt.refunded_total||'0',receipt.currency) }}</p>
    <section v-if="receipt" class="panel" aria-label="Pedido confirmado"><h2>Pedido #{{ receipt.number }} confirmado</h2><p>La tienda recibió tu pedido. Contacta con ella para coordinar pago y entrega.</p><article v-for="(item,index) in receipt.items" :key="index" class="public-cart-line"><strong>{{ item.name }}</strong><span>{{ item.quantity.replace(/\.?0+$/,'') }} × {{ amount(item.price,receipt.currency) }}</span><strong>{{ amount(item.total,receipt.currency) }}</strong></article><p class="public-total">Total {{ amount(receipt.total,receipt.currency) }}</p></section>
    <template v-else-if="ready">
    <div class="public-order-layout"><section class="panel" aria-label="Catálogo autorizado"><h2>Artículos disponibles</h2><label>Buscar artículo<input v-model="query" type="search" maxlength="120" :disabled="busy||!!pending" placeholder="Nombre o código…"></label><p v-if="loading" role="status">Buscando artículos…</p>
        <p v-if="catalogError" class="notice error" role="alert">{{ catalogError }} <button type="button" class="button secondary" :disabled="loading||busy" @click="catalog(page)">Reintentar catálogo</button></p>
        <div class="public-product-grid"><article v-for="product in products" :key="product.id" class="public-product"><button type="button" class="product-open" :aria-label="`Ver producto ${product.name}`" :disabled="busy||!!pending||loading" @click="openDetail(product,$event)"><img v-if="product.image_url || product.variants.find(v=>v.image_url)?.image_url" :src="product.image_url || product.variants.find(v=>v.image_url)?.image_url || undefined" :alt="product.name" loading="lazy"><span v-else class="product-no-image">Sin foto</span><strong>{{ product.name }}</strong><span>Ver colores, tallas y precio</span></button></article></div>
        <p v-if="!loading&&!products.length" class="muted">No hay artículos con esta búsqueda.</p><div v-if="lastPage>1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="busy||loading||!!pending||page<=1" @click="catalog(page-1)">Anterior</button><span>{{ page }}/{{ lastPage }}</span><button type="button" class="button secondary" :disabled="busy||loading||!!pending||page>=lastPage" @click="catalog(page+1)">Siguiente</button></div>
    </section><section class="panel public-cart" aria-label="Tu pedido"><h2>Tu pedido</h2><p v-if="!cart.length" class="muted">Agrega artículos para comenzar.</p>
        <article v-for="line in cart" :key="line.id" class="public-cart-line"><strong>{{ line.name }}</strong><span>{{ amount(line.price,currency) }} por unidad</span><DecimalStepper v-model="line.quantity" :label="`Cantidad de ${line.name}`" positive :disabled="busy||!!pending"/><button type="button" class="text-button" :disabled="busy||!!pending" :aria-label="`Quitar ${line.name}`" @click="remove(line.id)">Quitar</button></article>
        <p class="muted">Revisarás el total actualizado, impuestos y envío antes de confirmar.</p><button type="button" class="button primary full" :disabled="busy||!!pending||!cart.length" @click="preview">Revisar pedido</button>
        <template v-if="pending"><p class="notice">Hay una confirmación sin respuesta. Se conserva la misma operación.</p><button type="button" class="button secondary full" :disabled="busy" @click="review=true">Revisar confirmación pendiente</button><button type="button" class="button secondary full" :disabled="busy" @click="info">Comprobar confirmación</button></template>
    </section></div><p class="muted">Enlace disponible hasta {{ new Date(expires).toLocaleString('es') }}. Los artículos se reservan al confirmar.</p>
    </template><button v-else-if="!busy" type="button" class="button secondary" @click="info">Reintentar consulta</button>
    <AppDialog :open="!!detail" :title="detail?.name || 'Producto'" :busy="busy" :return-focus="detailOpener" @close="detail=null"><template v-if="detail"><img v-if="!(detail.variants.length>1 || supportsColorSize(detail.variants)) && (selected(detail)?.image_url || detail.image_url)" class="detail-image" :src="selected(detail)?.image_url || detail.image_url || undefined" :alt="detail.name"><VariantSelector v-if="detail.variants.length>1 || supportsColorSize(detail.variants)" :key="detail.id" v-model="selections[detail.id]" :product-name="detail.name" :variants="detail.variants" :currency="currency" :disabled="busy||!!pending||loading"/><p v-if="selected(detail)" class="detail-price">{{ amount(selected(detail)?.price,currency) }}</p><p v-else class="muted">Elige una opción para ver su precio.</p><p v-if="selected(detail) && !available(selected(detail)!)" class="muted">Sin disponibilidad</p></template><template #footer><button type="button" class="button secondary" @click="detail=null">Cerrar</button><button v-if="detail" type="button" class="button primary" :disabled="busy||!!pending||loading||!!catalogError||!selected(detail)||!available(selected(detail)!)||cart.some(line=>line.id===selected(detail)?.id)" :aria-label="`Agregar ${detail.name}`" @click="add(detail)">{{ cart.some(line=>line.id===selected(detail)?.id) ? 'En tu pedido' : 'Agregar al carrito' }}</button></template></AppDialog>
    <AppDialog :open="review" title="Revisar pedido" :busy="busy" :return-focus="opener" @close="review=false"><p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-for="line in cart" :key="line.id">{{ line.name }} · {{ line.quantity }} × {{ amount(line.price,currency) }}</p><dl v-if="quote" class="public-totals"><dt>Subtotal</dt><dd>{{ amount(quote.subtotal,quote.currency) }}</dd><dt>Impuestos</dt><dd>{{ amount(quote.tax_total,quote.currency) }}</dd><dt>Envío</dt><dd>{{ amount(quote.shipping_total,quote.currency) }}</dd><dt>Total</dt><dd><strong>{{ amount(quote.grand_total,quote.currency) }}</strong></dd></dl><p>Al confirmar se crea un apartado y se reserva disponibilidad. El pago se coordina con la tienda.</p><button v-if="pending" type="button" class="button secondary" :disabled="busy" @click="info">Comprobar confirmación</button><template #footer><button type="button" class="button secondary" :disabled="busy" @click="review=false">Volver</button><button type="button" class="button primary" :disabled="busy" @click="confirm">{{ pending ? 'Reintentar confirmación' : 'Confirmar pedido' }}</button></template></AppDialog>
</main>
</template>
<style scoped>
.public-order{max-width:1200px;margin:auto;padding:28px 20px}.public-order header{margin-bottom:24px}.public-order h1{font-size:30px;overflow-wrap:anywhere}.public-order-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,1fr);gap:24px;align-items:start}.public-order-layout>*{min-width:0}.public-product-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:20px}.public-product{display:grid;align-content:start;gap:10px;min-width:0;padding:16px;border:1px solid #e5dff0;border-radius:14px}.public-product img{width:100%;height:160px;object-fit:contain}.product-open{border:0;background:transparent;color:inherit;text-align:left;display:grid;gap:10px;width:100%;cursor:pointer;font:inherit}.product-open span{color:#615a6b}.product-no-image{height:160px;display:grid;place-items:center;background:#f7f5fa}.detail-image{width:100%;max-height:340px;object-fit:contain}.detail-price{font-size:1.4rem;font-weight:700}.public-product h3,.public-product p,.public-cart-line strong{overflow-wrap:anywhere}.public-cart-line{display:grid;gap:10px;padding:18px 0;border-bottom:1px solid #e5dff0;min-width:0}.public-cart>.button{margin-top:16px}.public-total{font-size:22px;font-weight:700}.public-totals{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px}.public-totals dd{margin:0;white-space:nowrap}.public-order input,.public-order select{font-size:16px}.public-order button{min-height:48px;min-width:48px}@media(max-width:900px){.public-order-layout{grid-template-columns:minmax(0,1fr)}.public-product-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:480px){.public-order{padding:20px 12px}.public-product-grid{grid-template-columns:minmax(0,1fr)}.public-order h1{font-size:26px}}
</style>
