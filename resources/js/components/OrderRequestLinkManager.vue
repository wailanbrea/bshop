<script setup lang="ts">
import { ref,watch,onUnmounted } from 'vue';
import axios from 'axios';
import { api,errorMessage } from '../api';
import { amount } from '../catalog';
import type { SalesProduct } from '../sales';
import AppDialog from './AppDialog.vue';
const props=defineProps<{root:string;customerId:number;customerActive:boolean;currency:string}>();
type Link={id:number;status:string;expires_at:string};
const open=ref(false);const busy=ref(false);const error=ref('');const opener=ref<HTMLElement|null>(null);
const warehouses=ref<{id:number;name:string;is_active:boolean}[]>([]);const warehouse=ref('');const hours=ref('24');const shipping=ref('0');const query=ref('');const products=ref<SalesProduct[]>([]);const selected=ref<{id:number;name:string}[]>([]);
const links=ref<Link[]>([]);const url=ref('');const urlId=ref<number|null>(null);const copied=ref(false);const pending=ref<Record<string,unknown>|null>(null);
const endpoint=`${props.root}/order-request-links`;let timer:ReturnType<typeof setTimeout>|undefined;let token=0;
async function search(){const current=++token;if(!warehouse.value)return;try{const result=await api.get(`${props.root}/sales/catalog`,{params:{warehouse_id:Number(warehouse.value),q:query.value}});if(current===token)products.value=result.data.data;}catch(e){if(current===token)error.value=errorMessage(e);}}
watch(query,()=>{clearTimeout(timer);++token;products.value=[];timer=setTimeout(search,200);});
watch(warehouse,()=>{selected.value=[];products.value=[];search();});
async function show(event:Event){opener.value=event.currentTarget as HTMLElement;open.value=true;busy.value=true;error.value='';try{const [list,locations]=await Promise.all([api.get(endpoint,{params:{customer_id:props.customerId}}),api.get(`${props.root}/warehouses`)]);links.value=list.data.data;warehouses.value=locations.data.data.filter((w:any)=>w.is_active);if(!warehouse.value)warehouse.value=String(warehouses.value[0]?.id || '');}catch(e){error.value=errorMessage(e);}finally{busy.value=false;}}
function toggle(id:number,name:string){if(busy.value||pending.value)return;selected.value=selected.value.some(v=>v.id===id)?selected.value.filter(v=>v.id!==id):[...selected.value,{id,name}].slice(0,50);}
async function generate(){if(busy.value||!props.customerActive)return;busy.value=true;error.value='';copied.value=false;pending.value??={request_key:crypto.randomUUID(),customer_id:props.customerId,warehouse_id:Number(warehouse.value),variant_ids:selected.value.map(v=>v.id),hours:Number(hours.value),shipping:shipping.value};try{const result=(await api.post(endpoint,pending.value)).data.data;pending.value=null;url.value=result.path?new URL(result.path,location.origin).href:'';urlId.value=result.link.id;links.value=[result.link,...links.value.filter(l=>l.id!==result.link.id)].slice(0,20);}catch(e){error.value=errorMessage(e);if(axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status))pending.value=null;}finally{busy.value=false;}}
async function revoke(link:Link){busy.value=true;error.value='';try{const result=(await api.post(`${endpoint}/${link.id}/revoke`)).data.data;links.value=links.value.map(l=>l.id===link.id?result:l);if(urlId.value===link.id){url.value='';copied.value=false;}}catch(e){error.value=errorMessage(e);}finally{busy.value=false;}}
async function copy(){try{await navigator.clipboard.writeText(url.value);copied.value=true;}catch{error.value='Selecciona y copia el enlace del campo.';}}
onUnmounted(()=>{++token;clearTimeout(timer);});
</script>
<template><button type="button" class="button secondary" @click="show">Enlace para nuevo pedido</button>
<AppDialog :open="open" title="Enlace para nuevo pedido" :busy="busy" :return-focus="opener" @close="open=false">
    <p>El cliente podrá elegir únicamente las variantes que autorices y confirmar un apartado. Se utilizarán los precios vigentes al confirmar.</p><p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="busy" role="status">Actualizando enlace…</p>
    <fieldset class="form-fields" :disabled="busy||!!pending||!customerActive"><label>Almacén del pedido<select v-model="warehouse"><option v-for="w in warehouses" :key="w.id" :value="String(w.id)">{{ w.name }}</option></select></label><label>Duración del enlace<select v-model="hours"><option value="1">1 hora</option><option value="24">24 horas</option><option value="72">3 días</option><option value="168">7 días</option></select></label><label>Envío acordado<input v-model="shipping" inputmode="decimal" maxlength="20"></label><label>Buscar variantes para autorizar<input v-model="query" type="search" maxlength="120" placeholder="Nombre o SKU…"></label>
        <section aria-label="Variantes autorizables" class="request-link-options"><article v-for="product in products" :key="product.id"><strong>{{ product.name }}</strong><button v-for="variant in product.variants" :key="variant.id" type="button" class="button secondary" :aria-pressed="selected.some(v=>v.id===variant.id)" :disabled="!selected.some(v=>v.id===variant.id)&&selected.length>=50" @click="toggle(variant.id,`${product.name} · ${variant.sku}`)">{{ selected.some(v=>v.id===variant.id)?'✓ ':'' }}{{ variant.sku }} · {{ amount(variant.price,currency) }}</button></article></section>
        <p>{{ selected.length }} variantes autorizadas (máximo 50).</p><ul><li v-for="variant in selected" :key="variant.id">{{ variant.name }} <button type="button" class="text-button" :aria-label="`Retirar autorización ${variant.name}`" @click="toggle(variant.id,variant.name)">Retirar</button></li></ul>
    </fieldset>
    <p v-if="!customerActive" class="notice">Reactiva el cliente para generar nuevos enlaces. Puedes revocar los existentes.</p>
    <button type="button" class="button primary" :disabled="busy||!customerActive||!warehouse||!selected.length" @click="generate">{{ pending?'Reintentar enlace de pedido':'Generar enlace de pedido' }}</button>
    <p v-if="pending" class="notice">Reintentar conserva la misma autorización y duración.</p>
    <template v-if="url"><label>Enlace de pedido para el cliente<input :value="url" type="url" readonly @focus="($event.target as HTMLInputElement).select()"></label><button type="button" class="button secondary" @click="copy">Copiar enlace de pedido</button><p v-if="copied" role="status">Enlace copiado.</p></template>
    <section aria-label="Enlaces de pedido recientes" class="request-link-options"><h3>Enlaces recientes</h3><article v-for="link in links" :key="link.id"><strong>#{{ link.id }} · {{ ({active:'Activo',used:'Pedido confirmado',expired:'Caducado',revoked:'Revocado'} as Record<string,string>)[link.status] }}</strong><p>{{ new Date(link.expires_at).toLocaleString('es') }}</p><button v-if="link.status!=='revoked'" type="button" class="button secondary" :disabled="busy" :aria-label="`Revocar enlace de pedido #${link.id}`" @click="revoke(link)">Revocar acceso</button></article></section>
    <template #footer><button type="button" class="button secondary" :disabled="busy" @click="open=false">Cerrar</button></template>
</AppDialog></template>
<style scoped>.request-link-options{display:grid;gap:12px;margin:16px 0}.request-link-options article{display:grid;gap:10px;border:1px solid #e5dff0;padding:14px;border-radius:12px}.request-link-options button[aria-pressed=true]{background:#eee7ff;border-color:#7051bb}.request-link-options strong{overflow-wrap:anywhere}li{overflow-wrap:anywhere}</style>
