<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch, useId, onActivated, onDeactivated, onMounted, onUnmounted } from 'vue';
import { api, errorMessage } from '../api';
import { downloadPrivatePdf } from '../lib/downloadPdf';
import { amount } from '../catalog';
import { decimal, units, type SalesCustomer, type SavedOrder } from '../sales';
import type { Business, Store, Warehouse } from '../types';
import { useSalesDraft } from '../composables/useSalesDraft';
import SalesCustomers from './SalesCustomers.vue';
import SalesProducts from './SalesProducts.vue';
import SalesSummary from './SalesSummary.vue';
import ReservationDeadline from './ReservationDeadline.vue';

import AppDialog from './AppDialog.vue';
import { readPending, savePending, clearPending } from '../lib/pendingRequests';

const props = defineProps<{
    business: Business;
    store?: Store;
    warehouses: Warehouse[];
    permissions: string[];
    active: boolean;
    actorId: number;
}>();
const emit = defineEmits<{ contextLocked: [boolean]; titleChange: [string]; openApartados: [number?] }>();
const actionFormId = `${useId()}-sales-action`;
const actionJournal = `bshop:pending-action:${props.actorId}:${props.store?.id}`;
const visible = ref(true);
let orderLoadSequence = 0;
const warehouseId = ref<number | ''>('');
const root = computed(() => `/business/stores/${props.store?.id}`);
const draft = useSalesDraft(root, warehouseId, `bshop:pending-sale:${props.actorId}:${props.store?.id}`);
const {
    customer,
    sellerId,
    lines,
    mode,
    discount,
    shipping,
    paid,
    method,
    delivery,
    notes,
    quote,
    quoting,
    busy,
    error,
    notice,
    saved,
    uncertain,
    documentState,
} = draft;

function customerChanged(event:Event) {
    const updated = (event as CustomEvent<SalesCustomer>).detail;
    if (!saved.value && !uncertain.value && !busy.value && customer.value?.id === updated?.id) customer.value = updated;
}
onMounted(() => window.addEventListener('bshop:customer-changed', customerChanged));
onUnmounted(() => window.removeEventListener('bshop:customer-changed', customerChanged));

const mobilePanel = ref('products');
const refresh = ref(0);
const customerPicker = ref(false);
const customerOpener = ref<HTMLElement | null>(null);
const canCreate = computed(() => props.permissions.includes('sales.create'));
const canPay = computed(() => props.permissions.includes('payments.record'));
const activeWarehouses = computed(() => props.warehouses.filter((w) => w.is_active));
const sellers = ref<{ id: number; employee_code: string; full_name: string }[]>([]);
watch(() => props.store?.id, (id) => {
    sellers.value = [];
    if (!id) return;
    api.get(`${root.value}/sales/sellers`).then(res => { sellers.value = res.data.data || []; }).catch(() => {});
}, { immediate: true });
const historyOpen = ref(false);
const orders = ref<SavedOrder[]>([]);
const historyLoading = ref(false);
const orderPage = ref(1);
const orderLastPage = ref(1);
const orderFilter = ref('open');
const historyOpener = ref<HTMLElement | null>(null);
const action = ref('');
const actionOpen = ref(false);
const actionBusy = ref(false);
const actionAmount = ref('');
const actionMethod = ref('cash');
const actionItem = ref<number | ''>('');
const actionQuantity = ref('');
const deliverNow = ref(false);
const actionError = ref('');
const actionPending = ref<Record<string, unknown> | null>(null);
const actionOpener = ref<HTMLElement | null>(null);
const actionTitle = computed(
    () =>
        ({
            invoice: 'Facturar apartado',
            payment: 'Registrar abono',
            deliver: 'Registrar entrega',
            cancel: 'Cancelar apartado',
            remove: 'Retirar artículo reservado',
        })[action.value] || 'Confirmar operación',
);
const locked = computed(() => busy.value || !!uncertain.value || actionBusy.value || !!actionPending.value);
function publishContext() {
    if (visible.value && props.active) {
        emit(
            'titleChange',
            saved.value ? `Pedido #${saved.value.id}` : mode.value === 'reservation' ? 'Nuevo apartado' : 'Venta',
        );
        emit('contextLocked', locked.value);
    }
}
watch(() => [saved.value?.id, mode.value, locked.value], publishContext, { immediate: true });
onActivated(() => {
    visible.value = true;
    publishContext();
});
onDeactivated(() => {
    visible.value = false;
});
watch(
    () => props.active,
    (active) => {
        if (active) {
            publishContext();
            if (saved.value) openOrder(saved.value.id);
            else if (lines.value.length && !uncertain.value) draft.calculate();
        }
    },
);
watch(
    () => props.warehouses,
    (list) => {
        if (!list.some((w) => w.id === warehouseId.value))
            warehouseId.value =
                list.find((w) => w.is_active && w.is_default)?.id || list.find((w) => w.is_active)?.id || '';
    },
    { immediate: true },
);
watch(
    () => saved.value?.lock_version,
    () => {
        if (saved.value) {
            ++refresh.value;
            mobilePanel.value = 'summary';
        }
    },
);
watch(() => saved.value?.id, () => {
    if (props.active && saved.value && (saved.value.order_kind === 'reservation' || saved.value.status === 'reserved')) {
        emit('openApartados', saved.value.id);
    }
});

function start(target: 'sale' | 'reservation') {
    if (locked.value) return;
    if (saved.value) draft.reset(target);
    else mode.value = target;
    mobilePanel.value = 'products';
}
function selectCustomer(value: SalesCustomer) {
    customer.value = value;
    customerPicker.value = false;
    mobilePanel.value = 'products';
}
function chooseCustomer() {
    customerOpener.value = document.activeElement as HTMLElement;
    customerPicker.value = true;
}
async function openHistory(target = 1) {
    emit('openApartados');
}
async function openOrder(id: number) {
    if (lines.value.length && !saved.value) {
        error.value = 'Conserva tu borrador: retira sus artículos antes de abrir otro apartado.';
        return;
    }
    const token = ++orderLoadSequence;
    try {
        const order: SavedOrder = (await api.get(`${root.value}/sales/orders/${id}`)).data.data;
        if (token !== orderLoadSequence) return;
        draft.restore(order);
        customer.value = { ...order.customer_snapshot, id: order.customer_id };
        historyOpen.value = false;
        customerPicker.value = false;
        mobilePanel.value = 'summary';
    } catch (e) {
        if (token === orderLoadSequence) error.value = errorMessage(e);
    }
}
defineExpose({ openOrder });
function openAction(kind: string, itemId?: number) {
    if (!saved.value || locked.value) return;
    action.value = kind;
    actionItem.value = itemId || '';
    actionAmount.value = '';
    actionQuantity.value = '';
    deliverNow.value = false;
    actionError.value = '';
    actionPending.value = null;
    actionOpener.value = document.activeElement as HTMLElement;
    actionOpen.value = true;
}
watch(actionItem, (id) => {
    if (!id || !saved.value || action.value !== 'deliver') return;
    const reservation = saved.value.reservations.find((r) => r.order_item_id === id);
    if (reservation)
        actionQuantity.value = decimal(
            units(reservation.quantity)! -
                units(reservation.fulfilled_quantity)! -
                units(reservation.released_quantity)!,
        );
});
async function applyAction() {
    if (!saved.value || actionBusy.value) return;
    actionBusy.value = true;
    actionError.value = '';
    actionPending.value ??= {
        request_key: crypto.randomUUID(),
        version: saved.value.lock_version,
        ...(action.value === 'payment' ? { amount: actionAmount.value, payment_method: actionMethod.value } : {}),
        ...(action.value === 'invoice' ? { deliver_now: deliverNow.value } : {}),
        ...(actionItem.value ? { item_id: actionItem.value } : {}),
        ...(action.value === 'deliver' && actionItem.value ? { quantity: actionQuantity.value } : {}),
    };
    try {
        savePending(actionJournal, { order_id: saved.value.id, action: action.value, payload: actionPending.value });
    } catch {
        actionPending.value = null;
        actionBusy.value = false;
        actionError.value = 'No se pudo conservar la recuperación. La operación no se envió.';
        return;
    }
    try {
        const result = (
            await api.post(`${root.value}/sales/orders/${saved.value.id}/${action.value}`, actionPending.value, {
                timeout: 60000,
            })
        ).data.data;
        saved.value = result.order;
        documentState.value = result.document?.status || documentState.value;
        actionPending.value = null;
        clearPending(actionJournal);
        actionOpen.value = false;
        notice.value = 'Operación guardada correctamente.';
    } catch (e) {
        actionError.value = errorMessage(e);
        if (axios.isAxiosError(e) && e.response && [409, 422].includes(e.response.status)) {
            actionPending.value = null;
            clearPending(actionJournal);
        }
    } finally {
        actionBusy.value = false;
    }
}
onMounted(async () => {
    const recovery = readPending(actionJournal);
    const payload = recovery?.payload as Record<string, unknown> | undefined;
    if (!recovery || !payload || typeof recovery.action !== 'string' || typeof payload.request_key !== 'string') return;
    actionPending.value = payload;
    action.value = recovery.action;
    actionBusy.value = true;
    try {
        const result = (
            await api.get(`${root.value}/sales/requests/${payload.request_key}`, {
                params: { action: recovery.action },
            })
        ).data.data;
        saved.value = result.order;
        documentState.value = result.document?.status || '';
        actionPending.value = null;
        clearPending(actionJournal);
        notice.value = 'Operación recuperada tras recargar. No se duplicaron pagos ni documentos.';
    } catch (e) {
        if (axios.isAxiosError(e) && e.response?.status === 404) {
            try {
                saved.value = (await api.get(`${root.value}/sales/orders/${recovery.order_id}`)).data.data;
                actionAmount.value = String(payload.amount || '');
                actionMethod.value = String(payload.payment_method || 'cash');
                actionOpen.value = true;
                actionError.value = 'La respuesta está pendiente. Reintenta la misma operación.';
            } catch (lookupError) {
                error.value = errorMessage(lookupError);
            }
        } else error.value = errorMessage(e);
    } finally {
        actionBusy.value = false;
    }
});
async function downloadPdf() {
    if (!saved.value?.invoice || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await api.post(`${root.value}/invoices/${saved.value.invoice.id}/document`);
        documentState.value = result.data.data.status;
        await downloadPrivatePdf(result.data.data.url, `${saved.value.invoice.invoice_number}.pdf`);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
async function share() {
    if (!saved.value) return;
    const order = saved.value;
    const brand = props.store?.name || props.business.name;
    const text = `${brand} · Pedido #${order.id}\n${order.customer_snapshot.full_name}\n${order.items.map((item) => `${item.product_name_snapshot} (${item.sku_snapshot}) × ${item.quantity}`).join('\n')}\nTotal: ${amount(order.grand_total, order.currency)}\nAbonado: ${amount(order.paid_total, order.currency)}\nPendiente: ${amount(order.pending_total, order.currency)}${order.invoice ? '\nFactura: ' + order.invoice.invoice_number : ''}`;
    try {
        if (navigator.share) {
            await navigator.share({ title: `${brand} · Resumen`, text });
            notice.value = 'Resumen compartido mediante la opción elegida en tu dispositivo.';
        } else {
            await navigator.clipboard.writeText(text);
            notice.value = 'Resumen copiado. Pégalo y envíalo manualmente al cliente.';
        }
    } catch (e) {
        if (!(e instanceof DOMException && e.name === 'AbortError'))
            error.value = 'No se pudo compartir desde este navegador. Puedes descargar la factura PDF.';
    }
}
</script>
<template>
    <div class="sales-component">
        <div class="sales-workspace">
            <div class="sales-workspace-toolbar">
                <div v-if="activeWarehouses.length === 1" class="sales-single-warehouse">
                    <span>ALMACÉN DE VENTA</span><strong>{{ activeWarehouses[0].name }}</strong>
                </div>
                <label v-else class="sales-warehouse-picker"
                    >Almacén de venta<select
                        v-model="warehouseId"
                        :disabled="locked || lines.length > 0 || !!saved"
                        aria-label="Almacén de venta"
                    >
                        <option v-for="item in activeWarehouses" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                        <option v-if="!activeWarehouses.length" value="">Sin almacén activo</option>
                    </select></label
                >
                <div class="actions">
                    <button class="button secondary" :disabled="locked" @click="start('sale')">Nueva venta</button
                    ><button
                        class="button secondary"
                        :disabled="locked"
                        @click="
                            historyOpener = $event.currentTarget as HTMLElement;
                            openHistory();
                        "
                    >
                        Apartados guardados
                    </button>
                </div>
            </div>
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
            <p v-if="uncertain&&!busy" class="notice" role="status">
                La respuesta se interrumpió. Reintenta la misma operación para consultar su resultado sin duplicarla.
            </p>
            <p v-if="documentState === 'failed'" class="notice error" role="alert">
                La factura está emitida; su PDF requiere reintento. Usa «Descargar factura PDF».
            </p>
            <div v-if="warehouseId" class="sales-quick-bar" aria-label="Tipo de operación y cliente">
                <div class="sales-quick-mode" role="group" aria-label="Tipo de operación">
                    <button
                        type="button"
                        :class="{ active: mode === 'sale' }"
                        :disabled="locked || !!saved"
                        @click="mode = 'sale'"
                    >
                        Venta
                    </button>
                    <button
                        type="button"
                        :class="{ active: mode === 'reservation' }"
                        :disabled="locked || !!saved"
                        @click="mode = 'reservation'"
                    >
                        Apartado
                    </button>
                </div>
                <button
                    type="button"
                    class="sales-quick-customer"
                    :class="{ 'has-customer': !!customer }"
                    :disabled="locked || !!saved"
                    @click="chooseCustomer"
                >
                    <span class="sales-quick-customer-dot" aria-hidden="true">{{ customer ? customer.full_name.slice(0, 1).toUpperCase() : '+' }}</span>
                    <span class="sales-quick-customer-text">
                        <strong>{{ customer ? customer.full_name : 'Seleccionar cliente' }}</strong>
                        <small>{{ customer ? (customer.phone || 'Cambiar cliente') : 'Toca para elegir' }}</small>
                    </span>
                </button>
            </div>
            <div class="sales-mobile-tabs">
                <button type="button" :class="{ active: mobilePanel === 'products' }" @click="mobilePanel = 'products'">
                    Productos</button
                ><button type="button" :class="{ active: mobilePanel === 'summary' }" @click="mobilePanel = 'summary'">
                    Resumen ({{ saved?.items.length || lines.length }})
                </button>
            </div>
            <div
                v-if="warehouseId"
                class="sales-layout"
                :class="[`show-${mobilePanel}`, { 'has-mobile-cart': mobilePanel === 'products' && (saved?.items.length || lines.length) > 0 }]"
            >
                <div class="sales-products-pane">
                    <SalesProducts
                        :root="root"
                        :warehouse-id="warehouseId"
                        :store-id="store?.id"
                        :currency="business.default_currency"
                        :disabled="locked || !!saved || !canCreate"
                        :refresh="refresh"
                        :lines="lines"
                        mode="sale"
                        @add="draft.add"
                        @quantity="draft.changeQuantity"
                    />
                </div>
                <SalesSummary
                    v-model:mode="mode"
                    v-model:seller-id="sellerId"
                    v-model:discount="discount"
                    v-model:shipping="shipping"
                    v-model:paid="paid"
                    v-model:method="method"
                    v-model:delivery="delivery"
                    v-model:notes="notes"
                    :customer="customer"
                    :sellers="sellers"
                    :lines="lines"
                    :quote="quote"
                    :currency="saved?.currency || business.default_currency"
                    :waiting="quoting"
                    :busy="busy"
                    :uncertain="!!uncertain"
                    :order="saved"
                    :can-create="canCreate"
                    :can-pay="canPay&&!saved?.return_pending"
                    :can-cancel="permissions.includes('sales.cancel')"
                    :payment-context="{root,actorId,canReverse:permissions.includes('payments.reverse'),canReceive:permissions.includes('returns.receive'),canAdjust:permissions.includes('returns.adjust'),canRefund:permissions.includes('refunds.record'),canApply:permissions.includes('credits.apply'),canDownload:permissions.includes('invoices.view'),canReverseCredit:permissions.includes('credits.reverse'),canReverseReturn:permissions.includes('returns.reverse'),canReverseRefund:permissions.includes('refunds.reverse'),canReverseAdjustment:permissions.includes('adjustments.reverse')}"
                    @payments-changed="saved=$event;refresh++"
                    @select-customer="chooseCustomer"
                    @quantity="draft.changeQuantity"
                    @remove="
                        (id) => {
                            lines = lines.filter((line) => line.variant_id !== id);
                        }
                    "
                    @submit="draft.submit"
                    @action="openAction"
                    @share="share"
                    @pdf="downloadPdf"
                />
            </div>
            <div
                v-if="warehouseId && mobilePanel === 'products' && (saved?.items.length || lines.length) > 0"
                class="sales-mobile-cart-bar"
                role="region"
                aria-label="Resumen rápido del carrito"
            >
                <div class="sales-mobile-cart-info">
                    <strong class="sales-mobile-cart-total">{{ amount(saved?.grand_total || quote?.grand_total || lines.reduce((sum, l) => sum + Number(l.price || 0) * Number(l.quantity || 0), 0).toFixed(4), saved?.currency || business.default_currency) }}</strong>
                    <small>{{ saved ? saved.items.length : lines.length }} {{ (saved ? saved.items.length : lines.length) === 1 ? 'artículo' : 'artículos' }} · {{ customer ? customer.full_name : 'Sin cliente' }}</small>
                </div>
                <button type="button" class="button primary sales-mobile-cart-cta" @click="mobilePanel = 'summary'">
                    {{ mode === 'reservation' ? 'Ver apartado →' : 'Ver resumen →' }}
                </button>
            </div>
            <p v-else-if="!warehouseId" class="notice">Selecciona una sucursal y un almacén activos para operar.</p>
        </div>
        <AppDialog
            :open="customerPicker"
            title="Seleccionar cliente para la venta"
            :return-focus="customerOpener"
            @close="customerPicker = false"
            ><SalesCustomers
                v-if="customerPicker"
                :root="root"
                :actor-id="actorId"
                :selected="customer"
                :manage="permissions.includes('customers.manage')"
                :currency="business.default_currency"
                :refresh="refresh"
                @select="selectCustomer"
                @open-order="openOrder"
            /><template #footer
                ><button class="button secondary" @click="customerPicker = false">Cerrar</button></template
            ></AppDialog
        >
        <AppDialog
            :open="historyOpen"
            title="Apartados y ventas guardados"
            :return-focus="historyOpener"
            @close="historyOpen = false"
            ><label
                >Mostrar pedidos<select v-model="orderFilter" aria-label="Mostrar pedidos" @change="openHistory()">
                    <option value="open">Apartados abiertos</option>
                    <option value="all">Todos, incluidas ventas y cancelados</option>
                </select></label
            >
            <p v-if="historyLoading" role="status">Cargando apartados…</p>
            <article v-for="order in orders" :key="order.id" class="record">
                <div class="record-main">
                    <strong>#{{ order.id }} · {{ order.customer_snapshot?.full_name || 'Cliente histórico' }}</strong>
                    <p>
                        {{ order.status }} · {{ amount(order.grand_total, order.currency) }} · pendiente
                        {{ amount(order.pending_total, order.currency) }}
                    </p>
                </div>
                <button class="button secondary" :aria-label="`Abrir pedido ${order.id}`" @click="openOrder(order.id)">
                    Abrir
                </button>
            </article>
            <p v-if="!historyLoading && !orders.length" class="empty">No hay apartados para mostrar.</p>
            <div v-if="orderLastPage > 1" class="catalog-pagination">
                <button class="button secondary" :disabled="orderPage <= 1" @click="openHistory(orderPage - 1)">
                    Anterior</button
                ><span>{{ orderPage }}/{{ orderLastPage }}</span
                ><button
                    class="button secondary"
                    :disabled="orderPage >= orderLastPage"
                    @click="openHistory(orderPage + 1)"
                >
                    Siguiente
                </button>
            </div>
            <template #footer
                ><button class="button secondary" @click="historyOpen = false">Cerrar</button></template
            ></AppDialog
        >
        <ReservationDeadline v-if="saved" :order="saved" :timezone="business.timezone" />
        <AppDialog
            :open="actionOpen"
            :title="actionTitle"
            :busy="actionBusy || !!actionPending"
            :return-focus="actionOpener"
            @close="actionOpen = false"
        >
            <p v-if="actionError" class="notice error" role="alert">{{ actionError }}</p>
            <p v-if="saved" class="notice">
                Pedido #{{ saved.id }} · {{ saved.customer_snapshot.full_name }}<br />Pendiente
                {{ amount(saved.pending_total, saved.currency) }}
            </p>
            <form :id="actionFormId" @submit.prevent="applyAction">
                <fieldset class="form-fields" :disabled="actionBusy || !!actionPending">
                    <template v-if="action === 'payment'"
                        ><label
                            >Importe del abono USD<input
                                v-model="actionAmount"
                                inputmode="decimal"
                                required
                                maxlength="20"
                                @input="actionAmount = actionAmount.replace(',', '.')" /></label
                        ><label
                            >Método de abono<select v-model="actionMethod" aria-label="Método de abono">
                                <option value="cash">Efectivo</option>
                                <option value="bank_transfer">Transferencia bancaria</option>
                                <option value="card">Tarjeta</option>
                            </select></label
                        ></template
                    >
                    <label v-if="action === 'invoice'" class="check-label"
                        ><input v-model="deliverNow" type="checkbox" /> Entregar los artículos ahora</label
                    >
                    <template v-if="action === 'deliver'"
                        ><label
                            >Artículo a entregar<select v-model="actionItem" aria-label="Artículo a entregar">
                                <option value="">Todo lo pendiente</option>
                                <option v-for="item in saved?.items" :key="item.id" :value="item.id">
                                    {{ item.product_name_snapshot }} · {{ item.sku_snapshot }}
                                </option>
                            </select></label
                        ><label v-if="actionItem"
                            >Cantidad a entregar<input
                                v-model="actionQuantity"
                                inputmode="decimal"
                                required
                                maxlength="20"
                                @input="actionQuantity = actionQuantity.replace(',', '.')"
                        /></label>
                        <p class="muted">
                            La entrega descuenta físico y libera exactamente la reserva correspondiente. La factura
                            existente se conserva.
                        </p></template
                    >
                    <p v-if="action === 'cancel'">Se liberarán las reservas sin borrar la canasta ni su historial.</p>
                    <p v-if="action === 'remove'">
                        Se retirará esta línea y se liberarán sus unidades reservadas. El historial se conserva.
                    </p>
                    <p v-if="action === 'invoice'" class="muted">
                        Se emitirá una única factura con los precios guardados. Si no marcas entrega, los productos
                        continúan reservados.
                    </p>
                </fieldset>
            </form>
            <template #footer
                ><button class="button secondary" :disabled="actionBusy || !!actionPending" @click="actionOpen = false">
                    Cancelar</button
                ><button type="submit" :form="actionFormId" class="button primary" :disabled="actionBusy">
                    {{ actionBusy ? 'Guardando…' : actionPending ? 'Reintentar operación' : 'Confirmar operación' }}
                </button></template
            >
        </AppDialog>
    </div>
</template>
