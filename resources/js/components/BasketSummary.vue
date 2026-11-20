<script setup lang="ts">
import type { BasketQuote } from '../baskets';
import type { SavedOrder } from '../sales';
import { amount } from '../catalog';
import DecimalStepper from './DecimalStepper.vue';
defineProps<{
    order: SavedOrder;
    quote: BasketQuote | null;
    shipping: string;
    discount: string;
    editable: boolean;
    busy: boolean;
    canDeposit: boolean;
}>();
const emit = defineEmits<{ shipping: [string]; discount: [string]; deposit: [] }>();
</script>
<template>
    <section class="basket-summary" aria-label="Resumen económico">
        <div>
            <span>Abonado</span><strong>{{ amount(order.paid_total, order.currency) }}</strong
            ><button
                type="button"
                class="text-button"
                :disabled="busy || !canDeposit || order.status === 'cancelled' || Number(order.pending_total || 0) <= 0"
                @click="emit('deposit')"
            >
                + Abonar
            </button>
        </div>
        <div><DecimalStepper
                :model-value="shipping"
                :label="`Envío ${order.currency}`"
                :readonly="!editable"
                :disabled="busy"
                @update:model-value="emit('shipping', $event)" /></div>
        <div><DecimalStepper
                :model-value="discount"
                :label="`Descuento ${order.currency}`"
                :readonly="!editable"
                :disabled="busy"
                @update:model-value="emit('discount', $event)"
        /></div>
        <div>
            <span>Subtotal</span><strong>{{ amount(quote?.subtotal || order.subtotal, order.currency) }}</strong
            ><small v-if="(quote?.tax_total || order.tax_total) !== '0.0000'"
                >Impuestos {{ amount(quote?.tax_total || order.tax_total, order.currency) }}</small
            >
        </div>
        <div class="basket-pending">
            <p v-if="order.credit_received_total&&order.credit_received_total!=='0.0000'" class="muted">Crédito recibido de otras operaciones {{ amount(order.credit_received_total,order.currency) }} · Recibido originalmente {{ amount(order.gross_received||'0',order.currency) }}</p>
            <p v-if="order.return_credit_total&&order.return_credit_total!=='0.0000'" class="muted">Original {{ amount(order.original_total||order.grand_total,order.currency) }} · Ajuste {{ amount(order.return_credit_total,order.currency) }} · Saldo a favor {{ amount(order.credit_available||'0',order.currency) }} · Reembolsado {{ amount(order.refunded_total||'0',order.currency) }}</p>
            <span>Total pendiente</span
            ><strong>{{ amount(quote?.pending_total || order.pending_total, order.currency) }}</strong
            ><small>Total neto {{ amount(quote?.grand_total || order.net_total || order.grand_total, order.currency) }}</small>
        </div>
    </section>
</template>
