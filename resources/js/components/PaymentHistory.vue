<script setup lang="ts">
import axios from 'axios';
import { onMounted,onUnmounted,ref,useId } from 'vue';
import { api,errorMessage } from '../api';
import { amount } from '../catalog';
import { readPending,savePending,clearPending } from '../lib/pendingRequests';
import type { SavedOrder } from '../sales';
import AppDialog from './AppDialog.vue';
type Payment={id:number;amount:string;currency:string;status:string;payment_method:string;paid_at:string|null;reversal:{id:number;reason:string;actor_name:string;reversed_at:string}|null};
type Pending={paymentId:number;payload:{request_key:string;version:number;reason:string}};
const props=defineProps<{root:string;actorId:number;order:SavedOrder;canReverse:boolean;disabled:boolean}>();
const emit=defineEmits<{changed:[SavedOrder]}>();
const endpoint=`${props.root}/sales/orders/${props.order.id}/payments`;
const journal=`bshop:payment-reversal:${props.actorId}:${props.root}:${props.order.id}`;
const open=ref(false);const busy=ref(false);const error=ref('');const notice=ref('');const rows=ref<Payment[]>([]);const target=ref<Payment|null>(null);const reason=ref('');const pending=ref<Pending|null>(null);const opener=ref<HTMLElement|null>(null);const formId=useId();let alive=true;
const explain=(e:unknown)=>axios.isAxiosError(e)&&e.response?.status===409&&typeof e.response.data?.message==='string'?e.response.data.message:errorMessage(e);
const method=(name:string)=>({cash:'Efectivo',bank_transfer:'Transferencia',card:'Tarjeta'} as Record<string,string>)[name]||name;
async function load(){const result=await api.get(endpoint);if(alive)rows.value=result.data.data;}
async function show(event:Event){opener.value=event.currentTarget as HTMLElement;open.value=true;busy.value=true;error.value='';try{await load();}catch(e){if(alive)error.value=errorMessage(e);}finally{if(alive)busy.value=false;}}
function choose(payment:Payment){if(busy.value||pending.value||props.disabled||!props.canReverse)return;target.value=payment;reason.value='';error.value='';notice.value='';}
async function finish(order:SavedOrder,recovered=false){if(!alive)return;const id=pending.value?.paymentId;pending.value=null;target.value=null;reason.value='';notice.value=recovered?'Reversión recuperada sin repetir la operación.':'Pago revertido. El saldo del pedido se actualizó.';error.value='';if(id)rows.value=rows.value.map(row=>row.id===id?{...row,status:'reversed'}:row);try{clearPending(journal);}catch{}emit('changed',order);await load();}
async function send(){
    if(busy.value||props.disabled||!props.canReverse)return;error.value='';
    if(!pending.value){if(!target.value)return;const operation:Pending={paymentId:target.value.id,payload:{request_key:crypto.randomUUID(),version:props.order.lock_version,reason:reason.value}};try{savePending(journal,operation as unknown as Record<string,unknown>);pending.value=operation;}catch{error.value='No se pudo guardar la recuperación. No se envió la reversión.';return;}}
    busy.value=true;
    try{const result=(await api.post(`${endpoint}/${pending.value.paymentId}/reverse`,pending.value.payload)).data.data;await finish(result.order);}
    catch(e){if(alive){error.value=explain(e);if(axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)){pending.value=null;try{clearPending(journal);}catch{}}}}
    finally{if(alive)busy.value=false;}
}
async function recover(){if(!pending.value||busy.value)return;busy.value=true;error.value='';try{const result=(await api.get(`${endpoint}/requests/${pending.value.payload.request_key}`)).data.data;await finish(result.order,true);}catch(e){if(alive)error.value=axios.isAxiosError(e)&&e.response?.status===404?'La reversión no está confirmada. Reintenta la misma operación.':errorMessage(e);}finally{if(alive)busy.value=false;}}
async function refresh(){if(busy.value||pending.value)return;busy.value=true;error.value='';try{const order=(await api.get(`${props.root}/sales/orders/${props.order.id}`)).data.data;if(alive)emit('changed',order);await load();if(target.value&&rows.value.find(row=>row.id===target.value?.id)?.status!=='confirmed')target.value=null;}catch(e){if(alive)error.value=errorMessage(e);}finally{if(alive)busy.value=false;}}
onMounted(async()=>{const saved=readPending(journal) as unknown as Pending|null;if(saved&&Number.isInteger(saved.paymentId)&&typeof saved.payload?.request_key==='string'&&typeof saved.payload.reason==='string'){pending.value=saved;reason.value=saved.payload.reason;open.value=true;busy.value=true;try{await load();target.value=rows.value.find(row=>row.id===saved.paymentId)||null;}catch{}finally{busy.value=false;}await recover();}});
onUnmounted(()=>{alive=false;});
</script>
<template><button type="button" class="button secondary" :disabled="disabled||busy" @click="show">Pagos y reversiones</button>
<AppDialog :open="open" title="Pagos y reversiones" :busy="busy" :return-focus="opener" @close="open=false">
    <p>Historial de cobros del pedido. Una reversión corrige un pago registrado por error y reabre el saldo correspondiente. Los reembolsos al cliente se gestionan en su propio flujo.</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p><p v-if="busy" role="status">Consultando pagos…</p>
    <p v-if="pending" class="notice">Reversión sin respuesta confirmada. Se conserva el mismo UUID y motivo.</p>
    <section aria-label="Historial de pagos del pedido" class="payment-history-list"><article v-for="payment in rows" :key="payment.id"><div><strong>Pago #{{ payment.id }} · {{ amount(payment.amount,payment.currency) }}</strong><p>{{ method(payment.payment_method) }} · {{ payment.paid_at || 'Sin fecha' }}</p><p>{{ payment.status==='confirmed'?'Confirmado':payment.status==='reversed'?'Revertido':payment.status }}</p><p v-if="payment.reversal" class="payment-reason">{{ payment.reversal.reason }} · {{ payment.reversal.actor_name }} · {{ payment.reversal.reversed_at }}</p></div><button v-if="canReverse&&payment.status==='confirmed'&&order.status!=='cancelled'" type="button" class="button secondary" :disabled="busy||!!pending||disabled" :aria-label="`Revertir pago #${payment.id}`" @click="choose(payment)">Revertir</button></article><p v-if="!busy&&!rows.length" class="muted">No hay pagos registrados en este pedido.</p></section>
    <form v-if="target||pending" :id="formId" @submit.prevent="send"><p><strong>Corregir pago #{{ target?.id || pending?.paymentId }}</strong></p><label>Motivo de la reversión<textarea v-model="reason" required minlength="5" maxlength="500" rows="3" :disabled="busy||!!pending"></textarea></label><p class="muted">La reversión es contable. No procesa una transferencia ni un reembolso de tarjeta.</p></form>
    <button v-if="pending" type="button" class="button secondary" :disabled="busy" @click="recover">Comprobar reversión</button><button v-else type="button" class="button secondary" :disabled="busy" @click="refresh">Actualizar pedido e historial</button>
    <template #footer><button type="button" class="button secondary" :disabled="busy" @click="open=false">Cerrar</button><button v-if="canReverse&&(target||pending)" type="submit" :form="formId" class="button danger" :disabled="busy||disabled">{{ pending?'Reintentar reversión':'Revertir pago por error' }}</button></template>
</AppDialog></template>
<style scoped>.payment-history-list{display:grid;gap:14px;margin:20px 0}.payment-history-list article{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px;border:1px solid #e4ddec;border-radius:14px}.payment-history-list article>div{min-width:0;overflow-wrap:anywhere}.payment-reason{font-size:14px;color:#665976}@media(max-width:600px){.payment-history-list article{align-items:stretch;flex-direction:column}}</style>
