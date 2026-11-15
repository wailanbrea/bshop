<script setup lang="ts">
import { ref } from 'vue';
import SalesCustomers from './SalesCustomers.vue';
import CustomerManagement from './CustomerManagement.vue';
import type { SalesCustomer, CustomerSummary } from '../sales';
import type { Business, Store } from '../types';
defineProps<{ business: Business; store?: Store; permissions: string[]; actorId: number }>();
const emit = defineEmits<{ openOrder: [number] }>();
const selected = ref<SalesCustomer | null>(null);
const refresh = ref(0);
const summary = ref<CustomerSummary|null>(null);
function selectCustomer(customer:SalesCustomer) { summary.value = null; selected.value = customer; }
function updateSummary(id:number, value:CustomerSummary|null) { if (selected.value?.id === id) summary.value = value; }
function changed(customer:SalesCustomer) { selectCustomer(customer); refresh.value++; }
</script>
<template><div v-if="store" class="customers-workspace"><SalesCustomers :root="`/business/stores/${store.id}`" :actor-id="actorId" :selected="selected" :refresh="refresh" :allow-archived="true" :manage="permissions.includes('customers.manage')" :can-summary="permissions.includes('sales.view')" :currency="business.default_currency" @select="selectCustomer" @summary="updateSummary" @open-order="emit('openOrder', $event)" /><CustomerManagement :root="`/business/stores/${store.id}`" :actor-id="actorId" :customer="selected" :summary="summary" :can-summary="permissions.includes('sales.view')" :can-request-order="permissions.includes('sales.create') && permissions.includes('sales.view')" :currency="business.default_currency" :store-name="store.name" @open-order="emit('openOrder',$event)" :manage="permissions.includes('customers.manage')" @changed="changed" /></div><p v-else class="notice">No hay una sucursal autorizada para consultar clientes.</p></template>
<style scoped>
.customers-workspace { display:grid; grid-template-columns:minmax(260px,320px) minmax(0,1fr); gap:24px; align-items:start; }
.customers-workspace > * { min-width:0; }
@media(min-width:768px) and (max-width:1023px) { .customers-workspace { grid-template-columns:minmax(220px,260px) minmax(0,1fr); gap:16px; } }
@media(max-width:767px) { .customers-workspace { grid-template-columns:minmax(0,1fr); gap:20px; } }
</style>
