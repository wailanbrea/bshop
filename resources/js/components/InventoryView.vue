<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store, Warehouse } from '../types';
import { movementNames, quantity, type Movement, type StockRow } from '../inventory';
import AppDialog from './AppDialog.vue';
import InventoryReview from './InventoryReview.vue';

const props = defineProps<{ store?: Store; warehouses: Warehouse[]; permissions: string[] }>();
const manage = computed(() => props.permissions.includes('inventory.manage'));
const warehouseId = ref<number | ''>(''); const query = ref(''); const filter = ref('all');
const rows = ref<StockRow[]>([]); const loading = ref(false); const page = ref(1); const lastPage = ref(1); const total = ref(0); const error = ref(''); const notice = ref('');
const history = ref<Movement[]>([]); const historyOpen = ref(false); const historyVariant = ref<StockRow | null>(null); const historyLoading = ref(false); const historyPage = ref(1); const historyLastPage = ref(1);
const selected = ref<StockRow | null>(null); const open = ref(false); const opener = ref<HTMLElement | null>(null); const operation = ref('receipt'); const amount = ref(''); const note = ref(''); const destination = ref<number | ''>('');
const destinations = ref<{ id: number; name: string; store_name: string }[]>([]); const busy = ref(false); const formError = ref(''); const pending = ref<Record<string, unknown> | null>(null);
const warehouse = computed(() => props.warehouses.find(w => w.id === warehouseId.value));
let sequence = 0; let historySequence = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
const root = computed(() => `/business/stores/${props.store?.id}`);
async function load(target = 1) {
    if (!props.store || !warehouseId.value) return; const token = ++sequence; controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    try {
        const response = await api.get(`${root.value}/warehouses/${warehouseId.value}/stock`, { signal: controller.signal, params: { q: query.value, filter: filter.value, page: target } });
        if (token !== sequence) return;
        rows.value = response.data.data; page.value = response.data.meta.current_page; lastPage.value = response.data.meta.last_page; total.value = response.data.meta.total;
    } catch (e) { if (token === sequence && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (token === sequence) loading.value = false; }
}
async function loadHistory(target = 1) {
    if (!warehouseId.value) return; const token = ++historySequence; historyLoading.value = true;
    try {
        const response = await api.get(`${root.value}/warehouses/${warehouseId.value}/kardex`, { params: { page: target, variant_id: historyVariant.value?.variant_id } });
        if (token !== historySequence) return; history.value = response.data.data; historyPage.value = response.data.meta.current_page; historyLastPage.value = response.data.meta.last_page;
    } catch (e) { if (token === historySequence) error.value = errorMessage(e); }
    finally { if (token === historySequence) historyLoading.value = false; }
}
function viewHistory(row: StockRow | null) { historyVariant.value = row; historyOpen.value = true; loadHistory(); }
async function edit(row: StockRow, event: Event) {
    selected.value = { ...row }; opener.value = event.currentTarget as HTMLElement; operation.value = 'receipt'; amount.value = ''; note.value = ''; destination.value = ''; formError.value = ''; pending.value = null; open.value = true;
    try { destinations.value = (await api.get(`${root.value}/inventory/destinations`)).data.data; }
    catch (e) { formError.value = errorMessage(e); }
}
async function refreshBalance() {
    if (!selected.value || busy.value || pending.value) return; busy.value = true; formError.value = '';
    try {
        const response = await api.get(`${root.value}/warehouses/${warehouseId.value}/stock`, { params: { q: selected.value.sku } });
        const current = (response.data.data as StockRow[]).find(row => row.variant_id === selected.value?.variant_id);
        if (!current) throw new Error('missing'); selected.value = current;
    } catch (e) { formError.value = errorMessage(e); }
    finally { busy.value = false; }
}
async function submit() {
    if (!selected.value || busy.value) return; busy.value = true; formError.value = '';
    pending.value ??= { request_key: crypto.randomUUID(), warehouse_id: warehouseId.value, variant_id: selected.value.variant_id, version: selected.value.version, operation: operation.value, quantity: amount.value.trim(), note: note.value.trim(), ...(operation.value === 'transfer' ? { destination_warehouse_id: destination.value } : {}) };
    try {
        await api.post(`${root.value}/inventory/movements`, pending.value, { timeout: 30000 });
        pending.value = null; notice.value = 'Movimiento registrado. Saldos y kardex actualizados.'; await load(page.value); if (historyOpen.value) await loadHistory(); open.value = false;
    } catch (e) {
        formError.value = errorMessage(e);
        // Uncertain responses retain exactly the same UUID and immutable payload for retry.
        if (axios.isAxiosError(e) && e.response && [403, 404, 409, 419, 422, 429].includes(e.response.status)) pending.value = null;
    } finally { busy.value = false; }
}
watch(() => props.warehouses, list => { if (!list.some(w => w.id === warehouseId.value)) warehouseId.value = list.find(w => w.is_default && w.is_active)?.id || list.find(w => w.is_active)?.id || list[0]?.id || ''; }, { immediate: true });
watch(warehouseId, () => { rows.value = []; historyOpen.value = false; ++historySequence; load(); }, { immediate: true });
watch(query, () => { clearTimeout(timer); timer = setTimeout(() => load(), 250); }); watch(filter, () => load());
onUnmounted(() => { ++sequence; ++historySequence; controller?.abort(); clearTimeout(timer); });
</script>

<template>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    <section class="panel inventory-filters" aria-label="Filtros de inventario">
        <label>Almacén<select v-model="warehouseId"><option v-for="item in warehouses" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactivo' }}</option><option v-if="!warehouses.length" value="">Sin almacenes</option></select></label>
        <label>Buscar existencias<input v-model="query" type="search" maxlength="120" placeholder="Producto, SKU o código de barras" autocomplete="off"></label>
        <label>Disponibilidad<select v-model="filter"><option value="all">Todas</option><option value="available">Con disponible</option><option value="empty">Sin disponible</option></select></label>
        <button type="button" class="button secondary" :disabled="loading || !warehouseId" @click="load(page); historyOpen && loadHistory()">Actualizar inventario</button>
    </section>
    <div class="panel-heading inventory-heading"><div><h2>{{ warehouse?.name || 'Selecciona un almacén' }}</h2><p class="muted">{{ store?.name }} · {{ total }} variantes · Físico − reservado = disponible</p></div><div class="actions"><InventoryReview :store="store" :warehouse="warehouse" /><button type="button" class="button secondary" :disabled="!warehouseId" @click="viewHistory(null)">Ver kardex completo</button></div></div>
    <p v-if="loading" class="notice" role="status">Consultando existencias…</p>
    <div class="inventory-layout" :class="{ 'with-history': historyOpen }">
        <section class="panel" aria-label="Existencias por variante" :aria-busy="loading">
            <article v-for="row in rows" :key="row.variant_id" class="inventory-record">
                <div><strong>{{ row.product_name }}</strong><p class="muted">{{ row.sku }} · {{ row.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') || 'Variante general' }}</p><span v-if="!row.active" class="badge inactive">Inactivo</span></div>
                <dl class="stock-metrics"><div><dt>Físico</dt><dd>{{ quantity(row.on_hand) }}</dd></div><div><dt>Reservado</dt><dd>{{ quantity(row.reserved) }}</dd></div><div><dt>Disponible</dt><dd :class="{ empty: row.available === '0.0000' }">{{ quantity(row.available) }}</dd></div></dl>
                <div class="actions"><button type="button" class="button secondary" :aria-label="`Movimientos de ${row.sku}`" @click="viewHistory(row)">Movimientos</button><button v-if="manage" type="button" class="button primary" :disabled="!row.active || !warehouse?.is_active || !store?.is_active || loading" :aria-label="`Ajustar ${row.sku}`" @click="edit(row, $event)">Ajustar inventario</button></div>
            </article>
            <p v-if="!rows.length && !loading" class="empty">No hay variantes con seguimiento de existencias para estos filtros.</p>
            <div v-if="lastPage > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1 || loading" @click="load(page - 1)">Anterior</button><span>{{ page }} / {{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage || loading" @click="load(page + 1)">Siguiente</button></div>
        </section>
        <section v-if="historyOpen" class="panel history-panel" aria-label="Kardex de inventario">
            <div class="panel-heading"><div><h2>Kardex</h2><p class="muted">{{ historyVariant?.sku || 'Todas las variantes' }}</p></div><button type="button" class="icon-button" aria-label="Cerrar kardex" @click="historyOpen = false">×</button></div>
            <p v-if="historyLoading" role="status">Cargando movimientos…</p>
            <article v-for="item in history" :key="item.id" class="kardex-record"><span class="badge">{{ movementNames[item.movement_type] || item.movement_type }}</span><strong>{{ item.sku }} · {{ quantity(item.on_hand_delta) }}</strong><p>Físico: {{ quantity(item.on_hand_after) }} · Reservado: {{ quantity(item.reserved_after) }}</p><p>{{ item.note }}</p><small>{{ new Date(item.occurred_at).toLocaleString('es') }} · {{ item.actor_name || 'Sistema' }} · #{{ item.id }}</small></article>
            <p v-if="!historyLoading && !history.length" class="empty">Todavía no hay movimientos.</p>
            <div v-if="historyLastPage > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="historyPage <= 1 || historyLoading" @click="loadHistory(historyPage - 1)">Anterior</button><span>{{ historyPage }} / {{ historyLastPage }}</span><button type="button" class="button secondary" :disabled="historyPage >= historyLastPage || historyLoading" @click="loadHistory(historyPage + 1)">Siguiente</button></div>
        </section>
    </div>
    <AppDialog :open="open" title="Registrar movimiento de inventario" :busy="busy || !!pending" :return-focus="opener" @close="open = false">
        <p v-if="formError" class="notice error" role="alert">{{ formError }}</p>
        <p v-if="selected" class="notice">{{ selected.product_name }} · {{ selected.sku }}<br>{{ store?.name }} / {{ warehouse?.name }}<br>Físico {{ quantity(selected.on_hand) }} · Reservado {{ quantity(selected.reserved) }} · Disponible {{ quantity(selected.available) }}</p>
        <p v-if="pending && !busy" class="notice" role="status">La respuesta no se pudo confirmar. Pulsa Reintentar operación para comprobar el resultado.</p>
        <form id="inventory-move" @submit.prevent="submit">
            <fieldset class="form-fields" :disabled="busy || !!pending">
                <div><label for="inventory-operation">Tipo de movimiento</label><select id="inventory-operation" v-model="operation"><option value="receipt">Entrada</option><option value="issue">Salida</option><option value="count">Conteo físico</option><option value="transfer">Transferencia</option></select></div>
                <label>{{ operation === 'count' ? 'Cantidad física contada' : 'Cantidad' }}<input :value="amount" inputmode="decimal" autocomplete="off" required maxlength="20" @input="amount = ($event.target as HTMLInputElement).value.replace(',', '.')"></label>
                <p v-if="operation === 'count'" class="muted">Se registra la diferencia con el saldo mostrado. El conteo no puede ser menor que las unidades reservadas.</p>
                <label v-if="operation === 'transfer'">Almacén de destino<select v-model="destination" required aria-label="Almacén de destino"><option value="">Selecciona destino</option><option v-for="item in destinations.filter(w => w.id !== warehouseId)" :key="item.id" :value="item.id">{{ item.store_name }} · {{ item.name }}</option></select></label>
                <label>Motivo<input v-model="note" required maxlength="255" placeholder="Compra, merma, corrección de conteo…"></label>
            </fieldset>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy || !!pending" @click="refreshBalance">Actualizar saldo</button><button type="button" class="button secondary" :disabled="busy || !!pending" @click="open = false">Cancelar</button><button type="submit" form="inventory-move" class="button primary" :disabled="busy">{{ busy ? 'Registrando…' : pending ? 'Reintentar operación' : 'Registrar movimiento' }}</button></template>
    </AppDialog>
</template>

<style scoped>
.inventory-filters { display: grid; grid-template-columns: minmax(160px, 1fr) minmax(180px, 2fr) minmax(150px, 1fr) auto; gap: 16px; align-items: end; }
.inventory-heading { margin-top: 24px; }
.inventory-layout { display: grid; gap: 20px; }
.inventory-record { display: grid; gap: 16px; padding: 20px 0; border-bottom: 1px solid var(--line); }
.inventory-record:first-child { padding-top: 0; }
.inventory-record:last-child { border-bottom: 0; }
.inventory-record strong { font-size: 17px; }
.stock-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.stock-metrics > div { border: 1px solid var(--line); background: #f9f8fe; padding: 12px; border-radius: 12px; min-width: 0; }
dt { color: var(--muted); font-size: 13px; }
dd { margin: 4px 0 0; font-size: 21px; font-weight: 700; overflow-wrap: anywhere; }
dd.empty { color: #85511b; padding: 0; text-align: left; }
.inventory-record .actions { flex-wrap: wrap; }
.kardex-record { display: grid; gap: 8px; padding: 16px 0; border-top: 1px solid var(--line); overflow-wrap: anywhere; font-size: 14px; }
.kardex-record .badge { justify-self: start; }
.kardex-record small { color: var(--muted); }
@media (min-width: 1280px) { .inventory-layout.with-history { grid-template-columns: minmax(0, 1.5fr) minmax(300px, 1fr); align-items: start; } }
@media (max-width: 1199px) { .inventory-filters { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .inventory-filters { grid-template-columns: 1fr; } .stock-metrics { gap: 6px; } .stock-metrics > div { padding: 8px; } dd { font-size: 18px; } }
</style>
