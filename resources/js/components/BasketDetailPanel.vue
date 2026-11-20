<script setup lang="ts">
import axios from 'axios';
import { onUnmounted, ref, watch } from 'vue';
import type { BasketDraft, BasketQuote } from '../baskets';
import { editable as domainEditable, sendNames } from '../baskets';
import type { SalesProduct, SalesVariant, SavedOrder } from '../sales';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import BasketItemsList from './BasketItemsList.vue';
import BasketSummary from './BasketSummary.vue';
import ReservationDeadline from './ReservationDeadline.vue';
import OrderShareLinks from './OrderShareLinks.vue';
import PaymentHistory from './PaymentHistory.vue';
import SalesReturnReceipt from './SalesReturnReceipt.vue';
import CreditApplication from './CreditApplication.vue';
import VariantSelector from './VariantSelector.vue';
import { available, supportsColorSize } from '../lib/variantSelection';
const props = defineProps<{
    order: SavedOrder;
    actorId: number;
    timezone?: string;
    draft: BasketDraft;
    quote: BasketQuote | null;
    dirty: boolean;
    busy: boolean;
    previewing: boolean;
    error: string;
    root: string;
    permissions: string[];
    whatsapp: { ready: boolean; reason: string };
}>();
const emit = defineEmits<{
    quantity: [number, string];
    remove: [number];
    shipping: [string];
    discount: [string];
    add: [SalesProduct, SalesVariant];
    save: [];
    action: [string];
    refresh: [];
    paymentChanged: [SavedOrder];
    returnReceived: [SavedOrder];
    creditApplied: [SavedOrder];
}>();
function editable(order: SavedOrder) { return props.permissions.includes('sales.create') && domainEditable(order); }
const query = ref('');
const catalogOpen = ref(false);
const products = ref<SalesProduct[]>([]);
const selectedVariants = ref<Record<number, number | ''>>({});
const searching = ref(false);
const searchError = ref('');
async function fetchCatalog(searchTerm: string) {
    clearTimeout(timer);
    controller?.abort();
    const token = ++version;
    searching.value = true;
    controller = new AbortController();
    try {
        const result = await api.get(`${props.root}/sales/catalog`, {
            signal: controller.signal,
            params: { warehouse_id: props.order.warehouse_id, q: searchTerm || undefined },
        });
        if (token === version) {
            products.value = result.data.data;
            searchError.value = '';
        }
    } catch (e) {
        if (token === version && !axios.isCancel(e)) searchError.value = errorMessage(e);
    } finally {
        if (token === version) searching.value = false;
    }
}
function toggleCatalog() {
    catalogOpen.value = !catalogOpen.value;
    if (catalogOpen.value && !products.value.length) {
        fetchCatalog(query.value.trim());
    }
}
const sellerEditing = ref(false);
const sellerOptions = ref<{ id: number; full_name: string; employee_code: string }[]>([]);
const sellerValue = ref<number | ''>('');
const sellerSaving = ref(false);
const sellerError = ref('');
async function openSellerPicker() {
    sellerEditing.value = true;
    sellerValue.value = props.order.seller_employee_id || '';
    sellerError.value = '';
    try {
        sellerOptions.value = (await api.get(`${props.root}/sales/sellers`)).data.data || [];
    } catch (e) {
        sellerError.value = errorMessage(e);
    }
}
async function saveSeller() {
    if (sellerSaving.value) return;
    sellerSaving.value = true;
    sellerError.value = '';
    try {
        const res = await api.post(`${props.root}/sales/orders/${props.order.id}/seller`, {
            request_key: crypto.randomUUID(),
            version: props.order.lock_version,
            seller_employee_id: sellerValue.value === '' ? null : Number(sellerValue.value),
        });
        sellerEditing.value = false;
        emit('paymentChanged', res.data.data.order);
    } catch (e) {
        sellerError.value = errorMessage(e);
    } finally {
        sellerSaving.value = false;
    }
}
let timer: ReturnType<typeof setTimeout> | undefined;
let version = 0;
let controller: AbortController | undefined;
watch(query, (value) => {
    clearTimeout(timer);
    controller?.abort();
    selectedVariants.value = {};
    if (!value.trim() && !catalogOpen.value) {
        products.value = [];
        return;
    }
    if (value.trim()) catalogOpen.value = true;
    timer = setTimeout(() => fetchCatalog(value.trim()), 200);
});
watch(
    () => props.order.id,
    () => {
        query.value = '';
        catalogOpen.value = false;
        products.value = [];
        sellerEditing.value = false;
        sellerError.value = '';
    },
);
onUnmounted(() => {
    ++version;
    controller?.abort();
    clearTimeout(timer);
});
</script>
<template>
    <section class="basket-detail panel" aria-label="Detalle del apartado">
        <header class="basket-detail-heading">
            <div class="basket-detail-identity">
                <span class="basket-number-tile">{{ order.basket_number || order.id }}</span>
                <div>
                    <h2>{{ order.customer_live?.full_name || order.customer_snapshot.full_name }}</h2>
                    <p>{{ order.customer_live?.phone || order.customer_snapshot.phone || 'Sin teléfono' }}<template v-if="order.seller_name"> · Vendedor: <strong>{{ order.seller_name }}</strong></template></p>
                </div>
            </div>
            <div class="actions">
                <OrderShareLinks v-if="permissions.includes('sales.create') && ['reserved','confirmed','fulfilled'].includes(order.status)" :key="`${root}:${order.id}`" :root="root" :order-id="order.id" :disabled="busy || dirty" />
                <button
                    v-if="permissions.includes('members.manage') && !order.invoice && !order.closed_at && order.status !== 'cancelled'"
                    type="button"
                    class="button secondary"
                    :disabled="busy || dirty || sellerSaving"
                    @click="openSellerPicker"
                >
                    {{ order.seller_name ? 'Cambiar vendedor' : 'Asignar vendedor' }}
                </button>
                <button
                    type="button"
                    class="button secondary"
                    :disabled="busy || dirty || !permissions.includes('customers.manage')"
                    @click="emit('action', 'customer')"
                >
                    Editar cliente</button
                ><button
                    type="button"
                    class="button secondary"
                    :disabled="busy || dirty || !editable(order)"
                    @click="emit('action', 'number')"
                >
                    Cambiar #</button
                ><button
                    type="button"
                    class="button danger"
                    :disabled="
                        busy ||
                        !!order.invoice ||
                        Number(order.paid_total || 0) > 0 ||
                        !permissions.includes('sales.cancel') ||
                        order.status === 'cancelled'
                    "
                    :title="
                        order.status === 'cancelled'
                            ? 'Este apartado ya está cancelado.'
                            : order.invoice
                              ? 'Ya tiene factura emitida; usa el flujo de devolución.'
                              : Number(order.paid_total || 0) > 0
                                ? 'Tiene depósitos registrados. Revierte el depósito en Pagos y reversiones antes de cancelar.'
                                : 'Cancela el apartado y devuelve los artículos al inventario disponible.'
                    "
                    @click="emit('action', 'cancel')"
                >
                    Cancelar apartado
                </button>
            </div>
        </header>
        <div v-if="sellerEditing" class="notice" role="region" aria-label="Asignación de vendedor">
            <p v-if="sellerError" class="notice error" role="alert">{{ sellerError }}</p>
            <div class="actions" style="align-items:end;flex-wrap:wrap">
                <label style="flex:1;min-width:200px">Vendedor atribuido<select v-model="sellerValue" :disabled="sellerSaving"><option value="">Sin vendedor</option><option v-for="s in sellerOptions" :key="s.id" :value="s.id">{{ s.full_name }} ({{ s.employee_code }})</option></select></label>
                <button type="button" class="button secondary" :disabled="sellerSaving" @click="sellerEditing = false">Cancelar</button>
                <button type="button" class="button primary" :disabled="sellerSaving" @click="saveSeller">{{ sellerSaving ? 'Guardando…' : 'Guardar vendedor' }}</button>
            </div>
        </div>
        <ReservationDeadline :order="order" :timezone="timezone" />
        <PaymentHistory :key="`${root}:${order.id}`" :root="root" :actor-id="actorId" :order="order" :can-reverse="permissions.includes('payments.reverse')&&!order.return_pending" :disabled="busy||dirty" @changed="emit('paymentChanged',$event)" />
        <SalesReturnReceipt v-if="order.invoice" :key="`return:${root}:${order.id}`" :root="root" :actor-id="actorId" :order="order" :can-receive="permissions.includes('returns.receive')" :can-adjust="permissions.includes('returns.adjust')" :can-refund="permissions.includes('refunds.record')" :can-download="permissions.includes('invoices.view')" :can-reverse-return="permissions.includes('returns.reverse')" :can-reverse-refund="permissions.includes('refunds.reverse')" :can-reverse-adjustment="permissions.includes('adjustments.reverse')" :disabled="busy||dirty" @changed="emit('returnReceived',$event)" />
        <p v-if="order.return_pending" class="notice" role="status">Devolución recibida: ajuste económico pendiente. El saldo original está pendiente de conciliación y el cobro está bloqueado.</p>
        <CreditApplication v-if="order.invoice" :key="`credit:${root}:${order.id}`" :root="root" :actor-id="actorId" :order="order" :can-apply="permissions.includes('credits.apply')" :can-download="permissions.includes('invoices.view')" :can-reverse-credit="permissions.includes('credits.reverse')" :disabled="busy||dirty" @changed="emit('creditApplied',$event)"/>
        <p v-if="error" class="notice error" role="alert">{{ error }} <button type="button" class="text-button" :disabled="busy" @click="emit('refresh')">Descartar borrador y actualizar</button></p>
        <p v-if="dirty" class="notice" role="status">
            Cambios sin guardar. Guarda los artículos antes de cambiar cliente/número o registrar depósito; el inventario y la tarjeta se actualizarán al guardar.
        </p>
        <p v-if="order.closed_at" class="notice">
            Factura generada. Los artículos y precios históricos están cerrados.
        </p>
        <div v-if="editable(order)" class="basket-add-bar">
            <button type="button" class="button primary" :disabled="busy" @click="toggleCatalog">
                {{ catalogOpen ? 'Ocultar catálogo' : '+ Agregar producto' }}
            </button>
            <input
                v-model="query"
                type="search"
                class="basket-add-search"
                placeholder="Buscar producto por nombre o SKU…"
                maxlength="120"
                :disabled="busy"
                autocomplete="off"
                aria-label="Buscar artículo para agregar"
            />
        </div>
        <p v-if="searching" role="status">Cargando productos…</p>
        <p v-if="searchError" class="notice error">{{ searchError }}</p>
        <section v-if="editable(order) && (catalogOpen || query.trim())" class="basket-product-results" aria-label="Productos disponibles para agregar">
            <template v-for="product in products.slice(0, 12)" :key="product.id">
                <div v-if="supportsColorSize(product.variants)" class="basket-product-result basket-variant-group">
                    <strong>{{ product.name }}</strong>
                    <VariantSelector v-model="selectedVariants[product.id]" :product-name="product.name" :variants="product.variants" :currency="order.currency" :disabled="busy" />
                    <button type="button" class="button secondary" :disabled="busy || !product.variants.find(v=>v.id===selectedVariants[product.id] && available(v))" :aria-label="`Agregar ${product.name}`" @click="emit('add',product,product.variants.find(v=>v.id===selectedVariants[product.id])!); query=''">+ Agregar al apartado</button>
                </div>
                <button v-else
                    v-for="variant in product.variants"
                    :key="variant.id"
                    type="button"
                    class="basket-product-result"
                    :disabled="busy || !available(variant)"
                    @click="
                        emit('add', product, variant);
                        query = '';
                    "
                >
                    <strong>+ {{ product.name }}</strong
                    ><span>{{ variant.attributes.map((a) => `${a.name}: ${a.value}`).join(' · ') || variant.sku }}</span
                    ><span
                        >{{ amount(variant.price, order.currency) }} ·
                        {{
                            variant.available === null
                                ? 'Sin control de stock'
                                : `Disponible: ${variant.available}`
                        }}</span
                    >
                </button></template>
            <p v-if="!searching && !products.length" class="muted">No hay artículos disponibles con esta búsqueda.</p>
        </section>
        <BasketItemsList
            :lines="draft.lines"
            :currency="order.currency"
            :editable="editable(order)"
            :busy="busy"
            @quantity="(id, q) => emit('quantity', id, q)"
            @remove="emit('remove', $event)"
        /><BasketSummary
            :order="order"
            :quote="quote"
            :shipping="draft.shipping"
            :discount="draft.discount"
            :editable="editable(order)"
            :busy="busy"
            :can-deposit="permissions.includes('payments.record') && !dirty && !order.return_pending"
            @shipping="emit('shipping', $event)"
            @discount="emit('discount', $event)"
            @deposit="emit('action', 'payment')"
        />
        <p v-if="previewing" role="status">Calculando total…</p>
        <div class="basket-final-actions">
            <button
                v-if="editable(order)"
                type="button"
                class="button secondary"
                :disabled="busy || previewing || !quote || !dirty"
                @click="emit('save')"
            >
                Guardar cambios</button
            ><button
                v-if="permissions.includes('payments.record') && order.status !== 'cancelled' && !order.return_pending && Number(order.pending_total || 0) > 0"
                type="button"
                class="button secondary"
                :disabled="busy || dirty"
                @click="emit('action', 'payment')"
            >
                Abonar</button
            ><button
                v-if="permissions.includes('payments.record') && order.status !== 'cancelled' && !order.return_pending && Number(order.pending_total || 0) > 0"
                type="button"
                class="button basket-pay-full-btn"
                :disabled="busy || dirty"
                @click="emit('action', 'pay_full')"
            >
                Marcar pagada ({{ amount(order.pending_total, order.currency) }})</button
            ><span
                v-if="order.status !== 'cancelled' && Number(order.pending_total || 0) <= 0 && Number(order.grand_total || 0) > 0"
                class="badge basket-paid-pill"
            >
                ✓ Pagada
            </span><button
                v-if="!order.invoice && order.status !== 'cancelled' && permissions.includes('sales.create')"
                type="button"
                class="button primary"
                :disabled="busy || previewing || !draft.lines.length || (dirty && !quote)"
                @click="emit('action', 'close')"
            >
                {{ whatsapp.ready ? 'Cerrar pedido y enviar factura' : 'Cerrar pedido y generar factura' }}</button
            ><button
                v-if="order.invoice && permissions.includes('invoices.view')"
                type="button"
                class="button secondary"
                :disabled="busy"
                @click="emit('action', 'pdf')"
            >
                Descargar PDF</button
            ><button
                v-if="order.invoice"
                type="button"
                class="button secondary"
                :disabled="
                     busy ||
                     !permissions.includes('sales.create') ||
                    !whatsapp.ready ||
                    ['sending', 'unknown', 'accepted', 'sent', 'delivered', 'read'].includes(
                        order.whatsapp_delivery?.status || '',
                    )
                "
                @click="emit('action', 'whatsapp')"
            >
                {{
                    order.whatsapp_delivery?.status === 'failed'
                        ? 'Reintentar factura por WhatsApp'
                        : 'Enviar factura por WhatsApp'
                }}</button
            ><button
                v-if="['reserved', 'confirmed'].includes(order.status) && permissions.includes('sales.create')"
                type="button"
                class="button secondary"
                :disabled="busy || dirty"
                @click="emit('action', 'deliver')"
            >
                Registrar entrega</button
            ><button
                v-if="order.status === 'fulfilled' && !order.shipped_at && permissions.includes('sales.create')"
                type="button"
                class="button secondary"
                :disabled="busy"
                @click="emit('action', 'dispatch')"
            >
                Marcar enviado
            </button>
        </div>
        <p v-if="!whatsapp.ready" class="muted">{{ whatsapp.reason }}</p>
        <p v-if="order.whatsapp_delivery" class="notice" role="status">
            WhatsApp: {{ sendNames[order.whatsapp_delivery.status] || order.whatsapp_delivery.status }}.
            {{
                order.whatsapp_delivery.status === 'unknown'
                    ? 'Se espera confirmación del proveedor; no se repetirá automáticamente.'
                    : ''
            }}
        </p>
        <p v-if="order.shipped_at" class="notice success">Mercancía marcada como enviada.</p>
        <p v-if="order.paid_total !== '0.0000'" class="muted">
            Los depósitos registrados no se sobrescriben. El total no puede quedar por debajo de lo abonado.
        </p>
    </section>
</template>
<style scoped>
.basket-add-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 10px 0 4px; }
.basket-add-bar .button { flex-shrink: 0; white-space: nowrap; }
.basket-add-search { flex: 1 1 220px; min-width: 0; }
.basket-pay-full-btn { background: #16a34a; color: #fff; border: 0; white-space: nowrap; }
.basket-pay-full-btn:disabled { opacity: 0.5; }
.basket-paid-pill { display: inline-flex; align-items: center; padding: 8px 14px; border-radius: 999px; background: #dcfce7; color: #166534; font-weight: 750; font-size: 13px; white-space: nowrap; }
@media (max-width: 600px) {
    .basket-add-bar .button, .basket-add-search { width: 100%; }
}
</style>
