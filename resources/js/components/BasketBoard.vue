<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onActivated, onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { api, errorMessage } from '../api';
import { downloadPrivatePdf } from '../lib/downloadPdf';
import type { Business, Store, Warehouse } from '../types';
import type { SalesCustomer, SalesProduct, SalesVariant, SavedOrder } from '../sales';
import { decimal, units } from '../sales';
import { fromOrder, editable, type BasketCardData, type BasketDraft, type BasketQuote } from '../baskets';
import { readPending, savePending, clearPending } from '../lib/pendingRequests';
import BasketGrid from './BasketGrid.vue';
import BasketDetailPanel from './BasketDetailPanel.vue';
import BasketWhatsAppDispatch from './BasketWhatsAppDispatch.vue';
import PaymentMethodPicker from './PaymentMethodPicker.vue';
import SalesCustomers from './SalesCustomers.vue';
import AppDialog from './AppDialog.vue';

const props = defineProps<{
    business: Business;
    store?: Store;
    warehouses: Warehouse[];
    permissions: string[];
    actorId: number;
    active: boolean;
}>();
const emit = defineEmits<{ contextLocked: [boolean] }>();
const root = computed(() => `/business/stores/${props.store?.id}`);
const query = ref('');
const filter = ref('open');
type GridView = 2 | 4 | 6;
const gridPreferenceKey = `bshop:basket-grid:${props.actorId}`;
function initialGridView(): GridView {
    try { const saved = Number(localStorage.getItem(gridPreferenceKey)); if (saved === 2 || saved === 4 || saved === 6) return saved; }
    catch { /* The layout remains usable when browser storage is unavailable. */ }
    return 4;
}
const gridView = ref<GridView>(initialGridView());
const gridHelpId = `${useId()}-grid-help`;
const cards = ref<BasketCardData[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const selecting = ref(false);
const busy = ref(false);
const selectedId = ref<number | null>(null);
const selected = ref<SavedOrder | null>(null);
const draft = ref<BasketDraft | null>(null);
const quote = ref<BasketQuote | null>(null);
const previewing = ref(false);
const error = ref('');
const notice = ref('');
const base = ref('');
const detail = ref<HTMLElement>();
const drafts = new Map<number, BasketDraft>();
const whatsapp = ref({ enabled: false, ready: false, reason: 'WhatsApp está desactivado; es opcional.' });
const pending = ref<Record<string, any> | null>(null);
const journal = `bshop:pending-basket:${props.actorId}:${props.store?.id}`;
const clientOpen = ref(false);
const whatsappOpen = ref(false);
const whatsappOpener = ref<HTMLElement | null>(null);
const newCustomer = ref<SalesCustomer | null>(null);
const warehouse = ref<number | ''>('');
const modal = ref('');
const opener = ref<HTMLElement | null>(null);
const formId = `${useId()}-basket-action`;
const number = ref('');
const amount = ref('');
const paymentMethod = ref('cash');
const deliverNow = ref(false);
const contact = ref({ full_name: '', phone: '', email: '', address: '', notes: '', version: 0 });
const filters = [
    ['without_deposit', 'Sin depósito'],
    ['open', 'Abierto'],
    ['invoice_sent', 'Factura enviada'],
    ['paid', 'Pagado'],
    ['shipped', 'Enviado'],
    ['all', 'Todos'],
];
let listSequence = 0;
let selectSequence = 0;
let previewSequence = 0;
let listController: AbortController | undefined;
let selectController: AbortController | undefined;
let previewController: AbortController | undefined;
let timer: ReturnType<typeof setTimeout> | undefined;
let previewTimer: ReturnType<typeof setTimeout> | undefined;
const payload = computed(() =>
    draft.value
        ? {
              version: draft.value.version,
              lines: draft.value.lines.map((l) => ({
                  ...(l.id ? { id: l.id } : {}),
                  variant_id: l.variant_id,
                  quantity: l.quantity,
              })),
              shipping: draft.value.shipping,
              discount: draft.value.discount,
          }
        : null,
);
const dirty = computed(() => !!selected.value && JSON.stringify(payload.value) !== base.value);
const locked = computed(() => busy.value || selecting.value || !!pending.value);
watch(locked, (v) => {
    if (props.active) emit('contextLocked', v);
}, { immediate:true });
watch(
    () => props.warehouses,
    (list) => {
        if (!list.some((w) => w.id === warehouse.value))
            warehouse.value =
                list.find((w) => w.is_active && w.is_default)?.id || list.find((w) => w.is_active)?.id || '';
    },
    { immediate: true },
);
async function load(target = 1) {
    if (!props.store) return;
    const token = ++listSequence;
    listController?.abort();
    listController = new AbortController();
    loading.value = true;
    try {
        const r = await api.get(`${root.value}/baskets`, {
            signal: listController.signal,
            params: { q: query.value, filter: filter.value, page: target, per_page: gridView.value * gridView.value },
        });
        if (token !== listSequence) return;
        cards.value = r.data.data;
        page.value = r.data.meta.current_page;
        lastPage.value = r.data.meta.last_page;
        total.value = r.data.meta.total;
    } catch (e) {
        if (token === listSequence && !axios.isCancel(e)) error.value = errorMessage(e);
    } finally {
        if (token === listSequence) loading.value = false;
    }
}
watch([query, filter], () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 200);
});
watch(gridView, () => {
    try { localStorage.setItem(gridPreferenceKey, String(gridView.value)); } catch { /* Preference only; no business data depends on storage. */ }
    clearTimeout(timer);
    load(1);
});
function apply(order: SavedOrder) {
    selected.value = order;
    selectedId.value = order.id;
    draft.value = fromOrder(order);
    base.value = JSON.stringify({
        version: draft.value.version,
        lines: draft.value.lines.map((l) => ({ id: l.id, variant_id: l.variant_id, quantity: l.quantity })),
        shipping: draft.value.shipping,
        discount: draft.value.discount,
    });
    quote.value = null;
    drafts.delete(order.id);
}
async function select(id: number, discard = false) {
    if (busy.value || pending.value) return;
    if (!discard && selected.value && draft.value && dirty.value)
        drafts.set(selected.value.id, structuredClone(JSON.parse(JSON.stringify(draft.value))));
    if(discard)drafts.delete(id);
    const token = ++selectSequence;
    selectController?.abort();
    selectController = new AbortController();
    selecting.value = true;
    error.value = '';
    selectedId.value = id;
    try {
        const r = await api.get(`${root.value}/sales/orders/${id}`, { signal: selectController.signal });
        if (token !== selectSequence) return;
        const cached = drafts.get(id);
        apply(r.data.data);
        if (cached) {
            draft.value = cached;
            if (cached.version !== r.data.data.lock_version)
                error.value =
                    'Tu borrador se conserva, pero el apartado fue modificado. Actualiza antes de guardar.';
        }
        await nextTick();
        if (innerWidth < 768) detail.value?.scrollIntoView({ block: 'start' });
    } catch (e) {
        if (token === selectSequence && !axios.isCancel(e)) error.value = errorMessage(e);
    } finally {
        if (token === selectSequence) selecting.value = false;
    }
}
defineExpose({ select });
async function refreshStates(){await load(page.value);try{whatsapp.value=(await api.get('/business/integrations/whatsapp/status')).data.data;}catch{}if(selected.value&&!dirty.value)await select(selected.value.id,true);}
async function activityChanged(order:SavedOrder){if(selected.value?.id===order.id){if(dirty.value){selected.value=order;quote.value=null;error.value='El pedido se actualizó. Tu borrador se conserva; actualiza antes de guardarlo.';}else apply(order);}await load(page.value);}
watch(
    payload,
    () => {
        clearTimeout(previewTimer);
        previewController?.abort();
        ++previewSequence;
        quote.value = null;
        previewing.value = false;
        if (!dirty.value || !selected.value || !editable(selected.value) || pending.value) return;
        previewing.value = true;
        previewTimer = setTimeout(async () => {
            const token = ++previewSequence;
            previewController = new AbortController();
            try {
                const r = await api.post(`${root.value}/baskets/${selected.value!.id}/preview`, payload.value, {
                    signal: previewController.signal,
                });
                if (token === previewSequence) {
                    quote.value = r.data.data;
                    error.value = '';
                }
            } catch (e) {
                if (token === previewSequence && !axios.isCancel(e)) error.value = errorMessage(e);
            } finally {
                if (token === previewSequence) previewing.value = false;
            }
        }, 200);
    },
    { deep: true },
);
function add(product: SalesProduct, variant: SalesVariant) {
    if (!draft.value || locked.value) return;
    const existing = draft.value.lines.find((l) => l.variant_id === variant.id);
    if (existing) existing.quantity = decimal((units(existing.quantity) || 0n) + 10000n);
    else
        draft.value.lines.push({
            variant_id: variant.id,
            product_id: product.id,
            name: product.name,
            sku: variant.sku,
            quantity: '1.0000',
            price: variant.price,
            available: variant.available,
            attributes: variant.attributes,
            image: variant.thumb_url || product.image?.renditions.thumb.url || null,
        });
}
async function commit(record: Record<string, any>) {
    if (busy.value) return false;
    busy.value = true;
    error.value = '';
    pending.value = record;
    try {
        savePending(journal, record);
        const r = await api.request({
            url: record.path,
            method: record.method || 'post',
            data: record.payload,
            timeout: 90000,
        });
        if (r.data.data.order) apply(r.data.data.order);
        else if (record.target) apply((await api.get(`${root.value}/sales/orders/${record.target}`)).data.data);
        pending.value = null;
        clearPending(journal);
        modal.value = '';
        clientOpen.value = false;
        newCustomer.value = null;
        notice.value =
            record.operation === 'cancel'
                ? 'Apartado cancelado. Los artículos volvieron al inventario disponible.'
                : 'Apartado actualizado correctamente.';
        await load(page.value);
        return true;
    } catch (e) {
        error.value = errorMessage(e);
        if (axios.isAxiosError(e) && e.response && [409, 422].includes(e.response.status)) {
            pending.value = null;
            clearPending(journal);
        }
        return false;
    } finally {
        busy.value = false;
    }
}
async function save() {
    if (!selected.value || !payload.value || !quote.value || previewing.value) return false;
    return commit({
        path: `${root.value}/baskets/${selected.value.id}`,
        method: 'patch',
        target: selected.value.id,
        namespace: 'baskets',
        operation: 'save',
        payload: { ...payload.value, quote_hash: quote.value.quote_hash, request_key: crypto.randomUUID() },
    });
}
function dialog(kind: string) {
    if (!selected.value || locked.value) return;
    opener.value = document.activeElement as HTMLElement;
    const isFullPay = kind === 'pay_full';
    modal.value = isFullPay ? 'payment' : kind;
    number.value = selected.value.basket_number || String(selected.value.id);
    if (isFullPay) {
        const pendingNum = Number(selected.value.pending_total || 0);
        amount.value = pendingNum > 0 ? pendingNum.toFixed(2) : '';
    } else {
        amount.value = '';
    }
    deliverNow.value = false;
    const c = selected.value.customer_live || selected.value.customer_snapshot;
    contact.value = {
        full_name: c.full_name,
        phone: c.phone || '',
        email: c.email || '',
        address: c.address || '',
        notes: c.notes || '',
        version: selected.value.customer_live?.lock_version || 0,
    };
}
async function perform(kind: string) {
    if (!selected.value) return;
    if (kind === 'pdf') {
        busy.value = true;
        try {
            const r = await api.post(`${root.value}/invoices/${selected.value.invoice!.id}/document`);
            await downloadPrivatePdf(r.data.data.url, `${selected.value.invoice!.invoice_number}.pdf`);
        } catch (e) {
            error.value = errorMessage(e);
        } finally {
            busy.value = false;
        }
        return;
    }
    if (kind === 'whatsapp') {
        await send();
        return;
    }
    dialog(kind);
}
async function send() {
    if (!selected.value?.invoice || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const r = await api.post(
            `${root.value}/invoices/${selected.value.invoice.id}/whatsapp`,
            { request_key: crypto.randomUUID() },
            { timeout: 90000 },
        );
        notice.value =
            r.data.data.status === 'accepted'
                ? 'WhatsApp aceptó el mensaje; se espera confirmación de envío/entrega.'
                : r.data.data.status === 'failed'
                  ? 'El envío falló. La factura se conserva.'
                  : 'Estado del envío actualizado.';
        apply((await api.get(`${root.value}/sales/orders/${selected.value.id}`)).data.data);
        await load(page.value);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
async function confirm() {
    if (!selected.value) return;
    const kind = modal.value;
    if (kind === 'close' && dirty.value) {
        if (!(await save())) return;
    }
    if (!selected.value) return;
    const id = selected.value.id;
    const key = crypto.randomUUID();
    if (kind === 'number') {
        await commit({
            path: `${root.value}/baskets/${id}/number`,
            namespace: 'baskets',
            operation: 'number',
            target: id,
            payload: { request_key: key, version: selected.value.lock_version, number: number.value },
        });
        return;
    }
    if (kind === 'customer') {
        await commit({
            path: `${root.value}/customers/${selected.value.customer_id}`,
            method: 'patch',
            namespace: 'customers',
            operation: 'update',
            target: id,
            payload: { ...contact.value, request_key: key },
        });
        return;
    }
    const action = kind === 'close' ? 'invoice' : kind;
    const path =
        kind === 'dispatch' ? `${root.value}/baskets/${id}/dispatch` : `${root.value}/sales/orders/${id}/${action}`;
    const data = {
        request_key: key,
        version: selected.value.lock_version,
        ...(kind === 'payment' ? { amount: amount.value, payment_method: paymentMethod.value } : {}),
        ...(kind === 'close' ? { deliver_now: deliverNow.value } : {}),
    };
    const ok = await commit({
        path,
        target: id,
        namespace: kind === 'dispatch' ? 'baskets' : 'sales',
        operation: action,
        payload: data,
    });
    if (ok && kind === 'close' && whatsapp.value.ready) await send();
}
async function create() {
    if (!newCustomer.value || !warehouse.value) return;
    await commit({
        path: `${root.value}/baskets`,
        namespace: 'baskets',
        operation: 'create',
        payload: { request_key: crypto.randomUUID(), customer_id: newCustomer.value.id, warehouse_id: warehouse.value },
    });
}
onMounted(async () => {
    await load();
    try {
        whatsapp.value = (await api.get('/business/integrations/whatsapp/status')).data.data;
    } catch {
        /* Optional integration remains unavailable. */
    }
    const recovery = readPending(journal);
    if (!recovery) return;
    pending.value = recovery;
    busy.value = true;
    try {
        const key = (recovery.payload as any).request_key;
        const url =
            recovery.namespace === 'baskets'
                ? `${root.value}/baskets/requests/${key}?operation=${recovery.operation}`
                : recovery.namespace === 'customers'
                  ? `${root.value}/customers/requests/${key}?action=update`
                  : `${root.value}/sales/requests/${key}?action=${recovery.operation}`;
        const r = await api.get(url);
        apply(r.data.data.order || (await api.get(`${root.value}/sales/orders/${recovery.target}`)).data.data);
        pending.value = null;
        clearPending(journal);
        notice.value = 'Operación recuperada sin duplicados.';
        await load();
    } catch (e) {
        error.value =
            axios.isAxiosError(e) && e.response?.status === 404
                ? 'Operación sin confirmación. Reintenta la misma petición.'
                : errorMessage(e);
    } finally {
        busy.value = false;
    }
});
onActivated(() => {
    if (props.active) emit('contextLocked', locked.value);
});
onUnmounted(() => {
    ++listSequence;
    ++selectSequence;
    ++previewSequence;
    listController?.abort();
    selectController?.abort();
    previewController?.abort();
    clearTimeout(timer);
    clearTimeout(previewTimer);
});
</script>
<template>
    <div class="basket-board">
        <header class="basket-board-header">
            <div>
                <span class="eyebrow">{{ store?.name || business.name }}</span>
                <h1>Tablero de Apartados</h1>
                <p class="muted">Selecciona una tarjeta para trabajar su detalle aquí mismo.</p>
            </div>
            <div class="actions" style="flex-wrap: wrap;">
                <button
                    type="button"
                    class="button secondary"
                    :disabled="locked || !store"
                    @click="
                        whatsappOpener = $event.currentTarget as HTMLElement;
                        whatsappOpen = true;
                    "
                >
                    WhatsApp masivo
                </button>
                <button
                    type="button"
                    class="button primary basket-add-client-btn"
                    :disabled="locked || !warehouse || !permissions.includes('sales.create')"
                    @click="
                        opener = $event.currentTarget as HTMLElement;
                        clientOpen = true;
                    "
                >
                    + Cliente
                </button>
            </div>
        </header>
        <BasketWhatsAppDispatch
            :open="whatsappOpen"
            :store="store"
            :can-manage="permissions.includes('sales.create')"
            :opener="whatsappOpener"
            @close="whatsappOpen = false"
        />
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="pending && !busy" class="notice error" role="alert">
            Hay una operación sin confirmación.
            <button type="button" class="button secondary" @click="commit(pending!)">Reintentar operación</button>
        </p>
        <section class="basket-toolbar">
            <label
                >Buscar apartado o cliente<input
                    v-model="query"
                    type="search"
                    placeholder="Nombre, teléfono o número…"
                    maxlength="120"
                    :disabled="locked"
            /></label>
            <div class="basket-filter-buttons" aria-label="Estado de apartados">
                <button
                    v-for="[value, label] in filters"
                    :key="value"
                    type="button"
                    class="button"
                    :class="filter === value ? 'primary' : 'secondary'"
                    :aria-pressed="filter === value"
                    :disabled="locked"
                    @click="filter = value"
                >
                    {{ label }}
                </button>
            </div>
            <button type="button" class="button secondary" :disabled="locked || loading" @click="refreshStates">Actualizar estados</button>
        </section>
        <div class="basket-grid-controls">
            <label>Vista de cuadrícula
                <select v-model.number="gridView" aria-label="Vista de cuadrícula" :aria-describedby="gridHelpId" :disabled="locked">
                    <option :value="2">2×2 · 4 apartados</option>
                    <option :value="4">4×4 · 16 apartados</option>
                    <option :value="6">6×6 · 36 apartados</option>
                </select>
            </label>
            <p :id="gridHelpId" class="muted">Hasta {{ gridView * gridView }} tarjetas por página. Las columnas se adaptan al espacio para mantenerlas legibles.</p>
        </div>
        <p v-if="loading" role="status">Consultando apartados…</p>
        <p class="muted">{{ total }} apartados · {{ page }}/{{ lastPage }}</p>
        <BasketGrid :baskets="cards" :selected-id="selectedId" :busy="locked" :columns="gridView" @select="select" />
        <p v-if="!loading && !cards.length" class="panel empty">No hay apartados con estos filtros.</p>
        <div v-if="lastPage > 1" class="catalog-pagination">
            <button class="button secondary" :disabled="page <= 1 || loading || locked" @click="load(page - 1)">
                Anterior</button
            ><span>Página {{ page }} de {{ lastPage }}</span
            ><button class="button secondary" :disabled="page >= lastPage || loading || locked" @click="load(page + 1)">
                Siguiente
            </button>
        </div>
        <p v-if="selecting" role="status">Cargando el apartado seleccionado…</p>
        <div ref="detail" class="basket-detail-anchor">
            <BasketDetailPanel
                v-if="selected && draft"
                :order="selected" :timezone="business.timezone"
                :draft="draft"
                :quote="quote"
                :dirty="dirty"
                :busy="locked"
                :previewing="previewing"
                :error="error"
                :root="root"
                :permissions="permissions"
                :whatsapp="whatsapp"
                @quantity="
                    (id, q) => {
                        const line = draft!.lines.find((l) => l.variant_id === id);
                        if (line) line.quantity = q;
                    }
                "
                @remove="
                    (id) => {
                        draft!.lines = draft!.lines.filter((l) => l.variant_id !== id);
                    }
                "
                @shipping="draft!.shipping = $event"
                @discount="draft!.discount = $event"
                @add="add"
                @save="save"
            @action="perform"
            @refresh="selected && select(selected.id,true)"
            :actor-id="actorId"
            @payment-changed="activityChanged"
            @return-received="activityChanged"
            @credit-applied="activityChanged"
            />
            <p v-else-if="error" class="notice error" role="alert">{{ error }}</p>
            <p v-else class="panel empty">Toca un apartado de la cuadrícula o usa «+ Cliente» para comenzar.</p>
        </div>
    </div>
    <AppDialog
        :open="clientOpen"
        title="Cliente del nuevo apartado"
        :return-focus="opener"
        :busy="busy || !!pending"
        @close="clientOpen = false"
        ><SalesCustomers
            v-if="clientOpen && !newCustomer"
            :root="root"
            :actor-id="actorId"
            :selected="null"
            :manage="permissions.includes('customers.manage')"
            :currency="business.default_currency"
            :can-summary="false"
            @select="newCustomer = $event"
        /><template v-if="newCustomer"
            ><p class="notice">{{ newCustomer.full_name }}</p>
            <label
                >Almacén del apartado<select v-model="warehouse" aria-label="Almacén del apartado">
                    <option v-for="w in warehouses.filter((w) => w.is_active)" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select></label
            >
            <p class="muted">Se crea un apartado abierto sin reservar artículos hasta guardarlos.</p></template
        ><template #footer
            ><button
                class="button secondary"
                :disabled="busy || !!pending"
                @click="
                    clientOpen = false;
                    newCustomer = null;
                "
            >
                Cancelar</button
            ><button
                v-if="newCustomer"
                class="button primary"
                :disabled="busy || !!pending || !warehouse"
                @click="create"
            >
                Abrir apartado de este cliente
            </button></template
        ></AppDialog
    >
    <AppDialog
        :open="!!modal"
        :title="
            (
                {
                    customer: 'Editar cliente',
                    number: 'Cambiar número de apartado',
                    payment: 'Registrar depósito',
                    close: 'Cerrar pedido y generar factura',
                    cancel: 'Cancelar apartado',
                    deliver: 'Registrar entrega',
                    dispatch: 'Marcar mercancía enviada',
                } as Record<string, string>
            )[modal] || 'Confirmar'
        "
        :return-focus="opener"
        :busy="busy || !!pending"
        @close="modal = ''"
        ><form :id="formId" class="form-stack" @submit.prevent="confirm">
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <fieldset class="form-fields" :disabled="busy || !!pending">
                <template v-if="modal === 'customer'"
                    ><label>Nombre del cliente<input v-model="contact.full_name" required maxlength="180" /></label
                    ><label>Teléfono del cliente<input v-model="contact.phone" type="tel" maxlength="40" /></label
                    ><label>Correo del cliente<input v-model="contact.email" type="email" maxlength="190" /></label
                    ><label>Dirección del cliente<input v-model="contact.address" maxlength="255" /></label
                    ><label
                        >Notas del cliente<textarea
                            v-model="contact.notes"
                            maxlength="4000"
                        ></textarea></label></template
                ><label v-if="modal === 'number'"
                    >Número visible<input
                        v-model="number"
                        required
                        maxlength="24"
                        pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,23}" /></label
                ><template v-if="modal === 'payment'"
                    ><label
                        >Importe del pago USD<input
                            v-model="amount"
                            required
                            inputmode="decimal"
                            @input="amount = amount.replace(',', '.')" /></label
                    ><PaymentMethodPicker v-model="paymentMethod" label="Método de pago" /></template
                ><template v-if="modal === 'close'"
                    ><p>Se cerrarán los artículos con sus importes guardados y se generará una sola factura.</p>
                    <label class="check-label"
                        ><input v-model="deliverNow" type="checkbox" /> Entregar artículos ahora</label
                    >
                    <p class="muted">
                        {{
                            whatsapp.ready
                                ? 'Después se enviará el PDF por WhatsApp desde la aplicación.'
                                : 'WhatsApp está desactivado o incompleto; la factura estará disponible para descargar.'
                        }}
                    </p></template
                >
                <p v-if="modal === 'cancel'">
                    Al cancelar este apartado, todos los artículos reservados volverán automáticamente al inventario disponible y se conservará el historial.
                </p>
                <p v-if="modal === 'deliver'">Se entregará todo lo reservado pendiente, sin descontarlo dos veces.</p>
                <p v-if="modal === 'dispatch'">Confirma que la mercancía ya fue enviada o entregada a la clienta.</p>
            </fieldset>
        </form>
        <template #footer
            ><button class="button secondary" :disabled="busy || !!pending" @click="modal = ''">Cancelar</button
            ><button class="button primary" type="submit" :form="formId" :disabled="busy || !!pending">
                Confirmar operación
            </button></template
        ></AppDialog
    >
</template>

<style scoped>
.basket-add-client-btn {
    white-space: nowrap;
    flex-shrink: 0;
}
@media (max-width: 640px) {
    .basket-board-header {
        align-items: center;
        gap: 10px;
    }
    .basket-board-header .eyebrow,
    .basket-board-header > div > p,
    .basket-grid-controls > p {
        display: none;
    }
    .basket-board-header h1 {
        font-size: 1.35rem;
        margin: 0;
    }
    .basket-filter-buttons {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: 6px;
        padding-bottom: 2px;
    }
    .basket-filter-buttons .button {
        flex: 0 0 auto;
        min-height: 38px;
        padding: 6px 12px;
        font-size: 0.84rem;
        white-space: nowrap;
    }
}
</style>
