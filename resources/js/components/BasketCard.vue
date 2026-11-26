<script setup lang="ts">
import type { BasketCardData } from '../baskets';
import { amount } from '../catalog';
import { quantity } from '../inventory';
defineProps<{ basket: BasketCardData; selected: boolean; disabled: boolean }>();
const emit = defineEmits<{ select: [number] }>();
function shortOpenedAt(iso?: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    try {
        return new Intl.DateTimeFormat('es', {
            day: '2-digit',
            month: 'short',
            hour: 'numeric',
            minute: '2-digit',
        }).format(d);
    } catch {
        return '';
    }
}
</script>
<template>
    <button
        type="button"
        class="basket-card"
        :class="{
            selected,
            paid: basket.paid,
            shipped: basket.shipped,
            closed: basket.closed,
            cancelled: basket.status === 'cancelled',
        }"
        :disabled="disabled"
        :aria-label="`Seleccionar apartado ${basket.number} de ${basket.customer_name}`"
        :aria-pressed="selected"
        @click="emit('select', basket.id)"
    >
        <div class="basket-card-top-row">
            <small v-if="shortOpenedAt(basket.created_at)" class="basket-opened-at">{{ shortOpenedAt(basket.created_at) }}</small>
            <small class="basket-deposit">Depósito: <b>{{ amount(basket.deposit, basket.currency) }}</b></small>
        </div><strong class="basket-number">{{ basket.number }}</strong><strong class="basket-customer-name">{{ basket.customer_name }}</strong
        ><span
            >{{ quantity(basket.quantity_count || String(basket.items_count)) }} artículos ·
            {{ amount(basket.total, basket.currency) }}</span
        >
        <div class="basket-flags">
            <span v-if="basket.status === 'cancelled'">Cancelado</span
            ><template v-else
                ><span v-if="!basket.closed">Abierto</span><span v-if="basket.invoice_generated">Factura generada</span
                ><span v-if="['sent', 'delivered', 'read'].includes(basket.whatsapp_status || '')">Factura enviada</span
                ><span v-if="basket.paid">Pagado</span><span v-if="basket.shipped">Enviado</span></template
            >
        </div>
        <span v-if="selected" class="basket-selection" aria-hidden="true">✓</span>
    </button>
</template>
<style scoped>
.basket-card-top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    width: 100%;
    flex-wrap: wrap;
}
.basket-opened-at {
    font-size: 0.72rem;
    color: #646982;
    font-weight: 600;
    white-space: nowrap;
}
.basket-card-top-row .basket-deposit {
    position: static;
    margin-left: auto;
    white-space: nowrap;
}
</style>
