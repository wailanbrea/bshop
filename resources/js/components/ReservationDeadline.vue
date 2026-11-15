<script setup lang="ts">
import { computed } from 'vue';
import type { SavedOrder } from '../sales';
const props = defineProps<{order:SavedOrder; timezone?:string}>();
const protectedOrder = computed(() => !!props.order.invoice || props.order.paid_total !== '0.0000' || props.order.reservations.some(r => r.fulfilled_quantity !== '0.0000'));
const label = computed(() => {
    if (!props.order.reservation_expires_at) return '';
    return new Intl.DateTimeFormat('es', {dateStyle:'medium',timeStyle:'short',timeZone:props.timezone || 'UTC'}).format(new Date(props.order.reservation_expires_at));
});
</script>
<template>
    <p v-if="order.reservation_expired" class="notice" role="status">Apartado vencido. El inventario reservado fue liberado.</p>
    <p v-else-if="label && order.status !== 'cancelled' && order.status !== 'fulfilled'" class="muted">{{ protectedOrder ? 'Plazo original de reserva' : 'Vencimiento de reserva' }}: {{ label }}{{ protectedOrder ? '. Conserva su reserva por tener abonos, factura o entregas.' : '. Se libera automáticamente si no tiene abonos, factura ni entregas.' }}</p>
</template>
