<script setup lang="ts">
import { ref } from 'vue';
import type { Business, Store } from '../types';
import type { SalesCustomer } from '../sales';
import SalesCustomers from './SalesCustomers.vue';
import OrderRequestLinkManager from './OrderRequestLinkManager.vue';

defineProps<{ business: Business; store?: Store; actorId: number }>();
const selected = ref<SalesCustomer | null>(null);
</script>

<template>
    <div v-if="store" class="customer-link-view">
        <SalesCustomers :root="`/business/stores/${store.id}`" :actor-id="actorId" :selected="selected" :manage="false" :can-summary="false" :currency="business.default_currency" @select="selected = $event" />
        <section class="panel link-panel">
            <h2>Vista del cliente</h2>
            <p>Elige un cliente, selecciona las variantes que podrá ver y genera su enlace de pedido.</p>
            <p class="notice">El enlace es real: si se confirma el carrito, se crea un apartado en el sistema. Para probar, abre el enlace y revisa la selección sin confirmar el pedido.</p>
            <template v-if="selected">
                <p>Cliente seleccionado: <strong>{{ selected.full_name }}</strong></p>
                <OrderRequestLinkManager :key="`${store.id}:${selected.id}`" :root="`/business/stores/${store.id}`" :customer-id="selected.id" :customer-active="selected.status !== 'archived'" :currency="business.default_currency" />
            </template>
            <p v-else class="muted">Selecciona un cliente para continuar.</p>
        </section>
    </div>
    <p v-else class="notice">Selecciona una sucursal autorizada para gestionar enlaces.</p>
</template>

<style scoped>
.customer-link-view{display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr);gap:24px;align-items:start}.customer-link-view>*{min-width:0}.link-panel{display:grid;gap:14px}.link-panel h2,.link-panel p{margin:0}@media(max-width:767px){.customer-link-view{grid-template-columns:minmax(0,1fr);gap:16px}}
</style>
