<script setup lang="ts">
import axios from 'axios';
import {onMounted,onUnmounted,ref} from 'vue';
import {api,errorMessage} from '../api';
import {amount} from '../catalog';
import type {SavedOrder} from '../sales';
import {readPending,savePending,clearPending} from '../lib/pendingRequests';
import {creditReversalText as t} from '../locales/es/creditReversal';
import AppDialog from './AppDialog.vue';
type Review={version:number;review_hash:string;currency:string;amount:string;payment_id:number;invoice_number:string;pending_after:string;allowed:boolean;balance:{pending_total:string}};
type Pending={applicationId:number;payload:{request_key:string;version:number;review_hash:string;reason:string}};
const props=defineProps<{root:string;actorId:number;orderId:number;applicationId:number;returnFocus:HTMLElement|null}>();const emit=defineEmits<{changed:[SavedOrder];closed:[]}>();
const endpoint=`${props.root}/sales/orders/${props.orderId}/credits/${props.applicationId}/reversal`;const journal=`bshop:credit-reversal:${props.actorId}:${props.root}:${props.orderId}`;
const busy=ref(false);const error=ref('');const notice=ref('');const reason=ref('');const review=ref<Review|null>(null);const pending=ref<Pending|null>(null);let alive=true;
const explain=(e:unknown)=>axios.isAxiosError(e)&&e.response?.status===409&&typeof e.response.data?.message==='string'?e.response.data.message:errorMessage(e);
async function load(){const result=(await api.get(endpoint)).data.data;if(alive)review.value=result;}
async function refresh(){if(busy.value||pending.value)return;busy.value=true;error.value='';try{await load();}catch(e){if(alive)error.value=explain(e);}finally{if(alive)busy.value=false;}}
function finish(order:SavedOrder,recovered=false){if(!alive)return;pending.value=null;error.value='';notice.value=recovered?t.recovered:t.success;try{clearPending(journal);}catch{}emit('changed',order);}
async function send(){if(busy.value||notice.value)return;error.value='';if(!pending.value){if(!review.value?.allowed||reason.value.trim().length<5)return;const operation:Pending={applicationId:props.applicationId,payload:{request_key:crypto.randomUUID(),version:review.value.version,review_hash:review.value.review_hash,reason:reason.value}};try{savePending(journal,operation as unknown as Record<string,unknown>);pending.value=operation;}catch{error.value=t.journalError;return;}}
    busy.value=true;try{finish((await api.post(endpoint,pending.value.payload)).data.data.order);}catch(e){if(alive){error.value=explain(e);if(axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)){pending.value=null;review.value=null;try{clearPending(journal);}catch{}}}}finally{if(alive)busy.value=false;}}
async function recover(){if(!pending.value||busy.value)return;busy.value=true;error.value='';try{finish((await api.get(`${endpoint}/requests/${pending.value.payload.request_key}`)).data.data.order,true);}catch(e){if(alive)error.value=axios.isAxiosError(e)&&e.response?.status===404?t.notConfirmed:explain(e);}finally{if(alive)busy.value=false;}}
onMounted(async()=>{const stored=readPending(journal) as unknown as Pending|null;if(stored?.applicationId===props.applicationId&&typeof stored.payload?.request_key==='string'&&typeof stored.payload?.reason==='string'){pending.value=stored;reason.value=stored.payload.reason;await recover();}else await refresh();});onUnmounted(()=>{alive=false;});
</script>
<template><AppDialog :open="true" :title="t.title" :busy="busy" :return-focus="returnFocus" @close="emit('closed')"><p>{{ t.description }}</p><p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p><p v-if="busy" role="status">{{ t.loading }}</p><p v-if="pending" class="notice">{{ t.pendingMessage }}</p>
    <template v-if="review&&!notice"><p><strong>{{ t.original(applicationId) }}</strong> · {{ review.invoice_number }} · {{ t.payment(review.payment_id) }}</p><dl><dt>{{ t.amount }}</dt><dd>{{ amount(review.amount,review.currency) }}</dd><dt>{{ t.pending }}</dt><dd>{{ amount(review.balance.pending_total,review.currency) }}</dd><dt>{{ t.after }}</dt><dd>{{ amount(review.pending_after,review.currency) }}</dd></dl><p v-if="!review.allowed" class="notice">{{ t.blocked }}</p></template>
    <label v-if="!notice">{{ t.reason }}<textarea v-model="reason" minlength="5" maxlength="255" rows="3" :disabled="busy||!!pending"></textarea></label>
    <button v-if="pending" type="button" class="button secondary" :disabled="busy" @click="recover">{{ t.check }}</button><button v-else-if="!notice" type="button" class="button secondary" :disabled="busy" @click="refresh">{{ t.refresh }}</button>
    <template #footer><button type="button" class="button secondary" :disabled="busy" @click="emit('closed')">{{ t.close }}</button><button v-if="!notice" type="button" class="button danger" :disabled="busy||(!pending&&(!review?.allowed||reason.trim().length<5))" @click="send">{{ pending?t.retry:t.confirm }}</button></template>
</AppDialog></template>
<style scoped>dl{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;margin:20px 0}dd{margin:0;font-weight:650;white-space:nowrap}p,dt{overflow-wrap:anywhere}label{display:grid;gap:10px;margin:20px 0}textarea{font-size:16px;min-height:110px}</style>
