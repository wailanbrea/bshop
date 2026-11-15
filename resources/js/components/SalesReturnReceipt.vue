<script setup lang="ts">
import axios from 'axios';
import {computed,nextTick,onMounted,onUnmounted,ref,useId} from 'vue';
import {api,errorMessage} from '../api';
import {quantity} from '../inventory';
import {units,type SavedOrder} from '../sales';
import {readPending,savePending,clearPending} from '../lib/pendingRequests';
import AppDialog from './AppDialog.vue';
import DecimalStepper from './DecimalStepper.vue';
import ReturnSettlement from './ReturnSettlement.vue';
type Line={invoice_item_id:number;name:string;sku:string;delivered_quantity:string;received_quantity:string;available_quantity:string;track_stock:boolean;quantity:string;restock:boolean};
type Receipt={id:number;received_at:string;actor_name:string;reason:string;status:string;items:{name:string;quantity:string;restock:boolean}[]};
type Info={order_id:number;version:number;invoice:{id:number;number:string};warehouse_name:string;lines:Line[];history:Receipt[];review_hash:string;return_pending:boolean};
type Pending={payload:{request_key:string;version:number;review_hash:string;reason:string;lines:{invoice_item_id:number;quantity:string;restock:boolean}[]}};
const props=defineProps<{root:string;actorId:number;order:SavedOrder;canReceive:boolean;canAdjust?:boolean;canRefund?:boolean;canDownload?:boolean;disabled:boolean}>();
const emit=defineEmits<{changed:[SavedOrder]}>();
const endpoint=`${props.root}/sales/orders/${props.order.id}/returns`;const journal=`bshop:return-receipt:${props.actorId}:${props.root}:${props.order.id}`;
const open=ref(false);const busy=ref(false);const error=ref('');const notice=ref('');const info=ref<Info|null>(null);const lines=ref<Line[]>([]);const reason=ref('');const pending=ref<Pending|null>(null);const opener=ref<HTMLElement|null>(null);const formId=useId();let alive=true;
const financialReceipt=ref<number|null>(null);
async function financial(id:number){if(busy.value||pending.value)return;open.value=false;await nextTick();financialReceipt.value=id;}
async function financialClosed(){financialReceipt.value=null;await nextTick();open.value=true;try{await load();}catch(e){error.value=explain(e);}}
async function financialChanged(order:SavedOrder){emit('changed',order);try{await load();}catch(e){error.value=explain(e);}}
const explain=(e:unknown)=>axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)&&typeof e.response.data?.message==='string'&&!e.response.data?.errors?e.response.data.message:errorMessage(e);
const canSubmit=computed(()=>!!info.value&&reason.value.trim().length>=5&&lines.value.some(line=>(units(line.quantity)??0n)>0n)&&lines.value.every(line=>{const value=units(line.quantity);const max=units(line.available_quantity);return value!==null&&max!==null&&value<=max;}));
async function load(reset=false){const result=(await api.get(endpoint)).data.data as Info;if(!alive)return;info.value=result;lines.value=result.lines.map(line=>{const previous=lines.value.find(old=>old.invoice_item_id===line.invoice_item_id);const stored=pending.value?.payload.lines.find(old=>old.invoice_item_id===line.invoice_item_id);return {...line,quantity:stored?.quantity??(reset?'0':previous?.quantity??'0'),restock:stored?.restock??(reset?true:previous?.restock??true)};});}
async function show(event:Event){opener.value=event.currentTarget as HTMLElement;open.value=true;busy.value=true;error.value='';try{await load();}catch(e){if(alive)error.value=explain(e);}finally{if(alive)busy.value=false;}}
async function finish(order:SavedOrder,id:number,recovered=false){if(!alive)return;pending.value=null;reason.value='';error.value='';notice.value=recovered?`Recepción #${id} recuperada sin repetir el ingreso.`:`Recepción #${id} registrada. Ajuste económico pendiente.`;try{clearPending(journal);}catch{}emit('changed',order);await load(true);}
async function send(){
    if(busy.value||props.disabled||!props.canReceive)return;error.value='';
    if(!pending.value){if(!info.value||!canSubmit.value)return;const operation:Pending={payload:{request_key:crypto.randomUUID(),version:info.value.version,review_hash:info.value.review_hash,reason:reason.value,lines:lines.value.filter(line=>(units(line.quantity)??0n)>0n).map(line=>({invoice_item_id:line.invoice_item_id,quantity:line.quantity,restock:line.restock}))}};try{savePending(journal,operation as unknown as Record<string,unknown>);pending.value=operation;}catch{error.value='No se pudo guardar la recuperación. No se recibió mercancía.';return;}}
    busy.value=true;try{const result=(await api.post(endpoint,pending.value.payload)).data.data;await finish(result.order,result.sales_return_id);}
    catch(e){if(alive){error.value=explain(e);if(axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)){pending.value=null;try{clearPending(journal);}catch{}}}}
    finally{if(alive)busy.value=false;}
}
async function recover(){if(!pending.value||busy.value)return;busy.value=true;error.value='';try{const result=(await api.get(`${endpoint}/requests/${pending.value.payload.request_key}`)).data.data;await finish(result.order,result.sales_return_id,true);}catch(e){if(alive)error.value=axios.isAxiosError(e)&&e.response?.status===404?'La recepción no está confirmada. Reintenta las mismas cantidades.':explain(e);}finally{if(alive)busy.value=false;}}
async function refresh(){if(busy.value||pending.value)return;busy.value=true;error.value='';try{await load();const result=(await api.get(`${props.root}/sales/orders/${props.order.id}`)).data.data;if(alive)emit('changed',result);}catch(e){if(alive)error.value=explain(e);}finally{if(alive)busy.value=false;}}
onMounted(async()=>{const stored=readPending(journal) as unknown as Pending|null;if(stored&&typeof stored.payload?.request_key==='string'&&typeof stored.payload.reason==='string'&&Array.isArray(stored.payload.lines)){pending.value=stored;reason.value=stored.payload.reason;open.value=true;busy.value=true;try{await load();}catch{}finally{busy.value=false;}await recover();}});
onUnmounted(()=>{alive=false;});
</script>
<template><button type="button" class="button secondary" :disabled="busy||disabled" @click="show">Devoluciones de mercancía</button>
<AppDialog :open="open" title="Recepción de mercancía devuelta" :busy="busy" :return-focus="opener" @close="open=false">
    <p>Recibe artículos facturados y entregados. Las unidades que marques para reposición ingresarán al almacén original.</p><p class="notice">Esta recepción física deja el ajuste económico pendiente. El importe fiscal y los pagos originales se conservan; los nuevos cobros de la factura quedarán bloqueados hasta conciliar la devolución.</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p><p v-if="busy" role="status">Consultando recepción…</p><p v-if="pending" class="notice">Recepción sin respuesta confirmada. Se conservan UUID, cantidades y destino.</p>
    <template v-if="info"><p><strong>{{ info.invoice.number }}</strong> · {{ info.warehouse_name }}</p>
        <form :id="formId" @submit.prevent="send"><fieldset class="form-fields" :disabled="busy||!!pending||!canReceive||disabled">
            <article v-for="line in lines" :key="line.invoice_item_id" class="return-receipt-line"><h3>{{ line.name }}</h3><p>{{ line.sku }} · Entregado {{ quantity(line.delivered_quantity) }} · Recibido {{ quantity(line.received_quantity) }} · Disponible para devolución {{ quantity(line.available_quantity) }}</p>
                <template v-if="line.track_stock&&units(line.available_quantity)!==0n&&canReceive"><DecimalStepper v-model="line.quantity" :label="`Cantidad a recibir de ${line.sku}`" :disabled="busy||!!pending||disabled"/><label>Destino de {{ line.sku }}<select v-model="line.restock"><option :value="true">Reponer en existencias</option><option :value="false">No ingresar a existencias</option></select></label></template><p v-else-if="!line.track_stock||units(line.available_quantity)===0n" class="muted">No hay unidades físicas disponibles para recibir en esta línea.</p>
            </article><label v-if="canReceive">Motivo de la recepción<textarea v-model="reason" required minlength="5" maxlength="255" rows="3"></textarea></label>
        </fieldset></form>
        <section aria-label="Recepciones anteriores" class="return-receipt-history"><h3>Últimas recepciones</h3><article v-for="receipt in info.history" :key="receipt.id"><strong>Recepción #{{ receipt.id }} · {{ receipt.status==='received'?'Ajuste económico pendiente':receipt.status==='adjusted'?'Ajuste confirmado':receipt.status }}</strong><p>{{ receipt.reason }} · {{ receipt.actor_name }} · {{ receipt.received_at }}</p><p v-for="(item,index) in receipt.items" :key="index">{{ item.name }} · {{ quantity(item.quantity) }} · {{ item.restock?'Repuesto':'Sin ingreso a existencias' }}</p><button type="button" class="button secondary" :disabled="busy||!!pending||disabled" :aria-label="`Conciliar recepción #${receipt.id}`" @click="financial(receipt.id)">Ver conciliación y reembolso</button></article><p v-if="!info.history.length&&!busy" class="muted">No hay recepciones registradas.</p></section>
    </template>
    <button v-if="pending" type="button" class="button secondary" :disabled="busy" @click="recover">Comprobar recepción</button><button v-else type="button" class="button secondary" :disabled="busy" @click="refresh">Actualizar revisión de devolución</button>
    <template #footer><button type="button" class="button secondary" :disabled="busy" @click="open=false">Cerrar</button><button v-if="canReceive" type="button" class="button primary" :disabled="busy||disabled||(!pending&&!canSubmit)" @click="send">{{ pending?'Reintentar recepción':'Registrar recepción física' }}</button></template>
</AppDialog><ReturnSettlement v-if="financialReceipt" :root="root" :actor-id="actorId" :order-id="order.id" :receipt-id="financialReceipt" :can-adjust="!!canAdjust" :can-refund="!!canRefund" :can-download="!!canDownload" :return-focus="opener" @closed="financialClosed" @changed="financialChanged"/></template>
<style scoped>.return-receipt-line{display:grid;gap:12px;border:1px solid #e4ddec;border-radius:14px;padding:16px;min-width:0}.return-receipt-line h3,.return-receipt-line p,.return-receipt-history p{overflow-wrap:anywhere}.return-receipt-history{margin-top:24px}.return-receipt-history article{padding:16px 0;border-bottom:1px solid #e4ddec}.return-receipt-line h3{font-size:17px}</style>
