<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount as money, decimalInput } from '../catalog';
import type { Store, Warehouse } from '../types';
import { movementNames, quantity, type InventoryValuation, type Movement, type StockRow } from '../inventory';
import AppDialog from './AppDialog.vue';
import InventoryReview from './InventoryReview.vue';

const props = defineProps<{ store?: Store; warehouses: Warehouse[]; permissions: string[] }>();
const manage = computed(() => props.permissions.includes('inventory.manage'));
const canEditPrices = computed(() => props.permissions.includes('catalog.manage'));
const canEditCosts = computed(() => props.permissions.includes('catalog.costs'));
const warehouseId = ref<number | ''>(''); const query = ref(''); const filter = ref('all');
const rows = ref<StockRow[]>([]); const valuation = ref<InventoryValuation | null>(null); const loading = ref(false); const page = ref(1); const lastPage = ref(1); const total = ref(0); const error = ref(''); const notice = ref('');
const history = ref<Movement[]>([]); const historyOpen = ref(false); const historyVariant = ref<StockRow | null>(null); const historyLoading = ref(false); const historyPage = ref(1); const historyLastPage = ref(1);
const selected = ref<StockRow | null>(null); const open = ref(false); const opener = ref<HTMLElement | null>(null); const operation = ref('receipt'); const amount = ref(''); const note = ref(''); const destination = ref<number | ''>('');
const destinations = ref<{ id: number; name: string; store_name: string }[]>([]); const busy = ref(false); const formError = ref(''); const pending = ref<Record<string, unknown> | null>(null);
const priceOpen = ref(false); const priceTarget = ref<StockRow | null>(null); const editCostPrice = ref(''); const editSalePrice = ref(''); const priceBusy = ref(false); const priceError = ref('');
const exporting = ref(false);
const viewMode = ref<'list' | 'cards'>('list');
const failedImages = ref<Set<number>>(new Set());
function stockTone(available: string): 'out' | 'low' | 'ok' {
    const n = Number(available);
    if (!Number.isFinite(n) || n <= 0) return 'out';
    if (n <= 2) return 'low';
    return 'ok';
}
function imageFailed(id: number) { failedImages.value = new Set([...failedImages.value, id]); }
async function exportInventoryCsv() {
    if (!props.store || !warehouseId.value || exporting.value) return;
    exporting.value = true;
    error.value = '';
    try {
        const response = await api.get(`${root.value}/warehouses/${warehouseId.value}/stock/export`, {
            params: { q: query.value, filter: filter.value },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'inventario-valorado.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        exporting.value = false;
    }
}
const warehouse = computed(() => props.warehouses.find(w => w.id === warehouseId.value));
let sequence = 0; let historySequence = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
const root = computed(() => `/business/stores/${props.store?.id}`);
async function load(target = 1) {
    if (!props.store || !warehouseId.value) return; const token = ++sequence; controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    try {
        const response = await api.get(`${root.value}/warehouses/${warehouseId.value}/stock`, { signal: controller.signal, params: { q: query.value, filter: filter.value, page: target } });
        if (token !== sequence) return;
        rows.value = response.data.data; valuation.value = response.data.meta.valuation || null; failedImages.value = new Set(); page.value = response.data.meta.current_page; lastPage.value = response.data.meta.last_page; total.value = response.data.meta.total;
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
function openPriceEditor(row: StockRow, event: Event) {
    priceTarget.value = { ...row };
    opener.value = event.currentTarget as HTMLElement;
    editCostPrice.value = row.unit_cost ? quantity(row.unit_cost) : '';
    editSalePrice.value = row.sale_price ? quantity(row.sale_price) : '';
    priceError.value = '';
    priceOpen.value = true;
}
async function submitPriceUpdate() {
    if (!priceTarget.value || priceBusy.value) return;
    priceBusy.value = true;
    priceError.value = '';
    try {
        await api.patch(`/business/products/${priceTarget.value.product_id}/variants/${priceTarget.value.variant_id}`, {
            sku: priceTarget.value.sku,
            sale_price: decimalInput(editSalePrice.value),
            ...(canEditCosts.value ? { cost_price: editCostPrice.value.trim() ? decimalInput(editCostPrice.value) : null } : {}),
            is_active: true,
            version: priceTarget.value.variant_version ?? 0,
        });
        notice.value = 'Precios y valoración de inventario actualizados.';
        priceOpen.value = false;
        await load(page.value);
    } catch (e) {
        priceError.value = errorMessage(e);
    } finally {
        priceBusy.value = false;
    }
}
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
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice success" role="status">{{ notice }}</p>

    <div class="inv-top-bar">
        <div>
            <h2>{{ warehouse?.name || 'Selecciona un almacén' }}</h2>
            <p class="muted">{{ store?.name }} · {{ total }} {{ total === 1 ? 'variante' : 'variantes' }}</p>
        </div>
        <div class="inv-top-actions">
            <InventoryReview :store="store" :warehouse="warehouse" />
            <button type="button" class="button secondary inv-btn-sm" :disabled="!warehouseId || exporting" @click="exportInventoryCsv">Exportar CSV</button>
            <button type="button" class="button secondary inv-btn-sm" :disabled="!warehouseId" @click="viewHistory(null)">Kardex</button>
            <button type="button" class="button primary inv-btn-sm" :disabled="loading || !warehouseId" @click="load(page); historyOpen && loadHistory()">Actualizar</button>
        </div>
    </div>

    <section v-if="valuation" class="inventory-valuation-grid" aria-label="Valor del inventario">
        <article v-if="valuation.invested_cost !== null" class="valuation-card highlight">
            <span>Capital invertido (costo)</span>
            <strong>{{ money(valuation.invested_cost, valuation.currency) }}</strong>
            <small>Costo en almacén</small>
        </article>
        <article class="valuation-card">
            <span>Valor a precio de venta</span>
            <strong>{{ money(valuation.sale_value, valuation.currency) }}</strong>
            <small>A precio actual</small>
        </article>
        <article v-if="valuation.estimated_profit !== null" class="valuation-card">
            <span>Ganancia estimada</span>
            <strong>{{ money(valuation.estimated_profit, valuation.currency) }}</strong>
            <small>Venta − costo</small>
        </article>
        <article class="valuation-card">
            <span>Unidades físicas</span>
            <strong>{{ quantity(valuation.units_on_hand) }}</strong>
            <small>Disponibles: {{ quantity(valuation.units_available) }}</small>
        </article>
        <button
            v-if="(valuation.low_stock_count || 0) > 0 || (valuation.out_of_stock_count || 0) > 0"
            type="button"
            class="valuation-card alert-card valuation-card-btn"
            @click="filter = (valuation.low_stock_count || 0) > 0 ? 'low' : 'empty'"
        >
            <span>Alertas de reposición</span>
            <strong>{{ (valuation.low_stock_count || 0) + (valuation.out_of_stock_count || 0) }}</strong>
            <small>{{ valuation.low_stock_count || 0 }} por agotarse (≤ 2) · {{ valuation.out_of_stock_count || 0 }} agotadas</small>
        </button>
    </section>

    <section class="panel inv-toolbar" aria-label="Filtros de inventario">
        <div class="inv-toolbar-row">
            <label class="inv-wh-select">
                <span class="sr-only">Almacén</span>
                <select v-model="warehouseId" aria-label="Almacén">
                    <option v-for="item in warehouses" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactivo' }}</option>
                    <option v-if="!warehouses.length" value="">Sin almacenes</option>
                </select>
            </label>
            <label class="inv-search-box">
                <span class="sr-only">Buscar existencias</span>
                <input v-model="query" type="search" maxlength="120" placeholder="Buscar producto, color, talla o SKU…" autocomplete="off" aria-label="Buscar existencias">
            </label>
            <div class="inv-view-toggle" role="group" aria-label="Modo de vista">
                <button type="button" :class="{ active: viewMode === 'list' }" :aria-pressed="viewMode === 'list'" @click="viewMode = 'list'">Lista compacta</button>
                <button type="button" :class="{ active: viewMode === 'cards' }" :aria-pressed="viewMode === 'cards'" @click="viewMode = 'cards'">Tarjetas</button>
            </div>
        </div>
        <div class="inv-chips" role="group" aria-label="Disponibilidad">
            <button type="button" class="inv-chip" :class="{ active: filter === 'all' }" @click="filter = 'all'">Todos</button>
            <button type="button" class="inv-chip" :class="{ active: filter === 'available' }" @click="filter = 'available'">Con stock</button>
            <button type="button" class="inv-chip amber" :class="{ active: filter === 'low' }" @click="filter = 'low'">Por agotarse ≤ 2<template v-if="valuation?.low_stock_count"> ({{ valuation.low_stock_count }})</template></button>
            <button type="button" class="inv-chip red" :class="{ active: filter === 'empty' }" @click="filter = 'empty'">Agotados<template v-if="valuation?.out_of_stock_count"> ({{ valuation.out_of_stock_count }})</template></button>
        </div>
    </section>

    <p v-if="loading" class="notice" role="status">Consultando existencias…</p>
    <div class="inventory-layout" :class="{ 'with-history': historyOpen }">
        <section class="panel inv-list-panel" :class="`mode-${viewMode}`" aria-label="Existencias por variante" :aria-busy="loading">
            <div v-if="viewMode === 'list' && rows.length" class="inv-table-head" aria-hidden="true">
                <div>Producto / Variante</div>
                <div>Precios e inversión</div>
                <div>Disponibilidad</div>
                <div style="text-align:right">Acciones</div>
            </div>
            <div class="inv-records" :class="`mode-${viewMode}`">
                <article v-for="row in rows" :key="row.variant_id" class="inventory-record" :class="`mode-${viewMode}`">
                    <div class="inventory-identity">
                        <div class="inventory-thumbnail">
                            <img v-if="row.image_url && !failedImages.has(row.variant_id)" :src="row.image_url" :alt="`Foto de ${row.product_name}`" loading="lazy" @error="imageFailed(row.variant_id)">
                            <span v-else role="img" aria-label="Sin fotografía">Sin foto</span>
                        </div>
                        <div class="inv-ident-text">
                            <strong class="inv-prod-name">{{ row.product_name }}</strong>
                            <p class="inv-prod-meta">
                                <span>{{ row.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') || 'Variante general' }}</span>
                                <span class="inv-sku">· {{ row.sku }}</span>
                            </p>
                            <span v-if="!row.active" class="badge inactive">Inactivo</span>
                        </div>
                    </div>

                    <div class="inventory-price-line">
                        <span v-if="row.sale_price && valuation" class="inv-sale">Venta: {{ money(row.sale_price, valuation.currency) }}</span>
                        <span v-if="row.unit_cost && valuation" class="inv-cost">Costo: {{ money(row.unit_cost, valuation.currency) }}</span>
                        <strong v-if="row.invested_total && valuation" class="inv-invested">Invertido: {{ money(row.invested_total, valuation.currency) }}</strong>
                    </div>

                    <div class="inv-stock-cell">
                        <span class="inv-stock-pill" :class="stockTone(row.available)">
                            <span class="sr-only">Disponible </span>{{ quantity(row.available) }} {{ stockTone(row.available) === 'out' ? 'agot.' : 'disp.' }}
                        </span>
                        <span class="inv-stock-sub">Físico {{ quantity(row.on_hand) }} · Reservado {{ quantity(row.reserved) }}</span>
                    </div>

                    <div class="actions inv-row-actions">
                        <button type="button" class="button secondary inv-btn-sm" :aria-label="`Movimientos de ${row.sku}`" @click="viewHistory(row)">Kardex</button>
                        <button v-if="canEditPrices" type="button" class="button secondary inv-btn-sm" :disabled="!row.active || loading" :aria-label="`Precios de ${row.sku}`" @click="openPriceEditor(row, $event)">Precios</button>
                        <button v-if="manage" type="button" class="button primary inv-btn-sm" :disabled="!row.active || !warehouse?.is_active || !store?.is_active || loading" :aria-label="`Ajustar ${row.sku}`" @click="edit(row, $event)">Ajustar</button>
                    </div>
                </article>
            </div>
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
        <div v-if="selected" class="notice inventory-identity"><div class="inventory-thumbnail"><img v-if="selected.image_url && !failedImages.has(selected.variant_id)" :src="selected.image_url" :alt="`Foto de ${selected.product_name}`" @error="imageFailed(selected.variant_id)"><span v-else role="img" aria-label="Sin fotografía">Sin foto</span></div><div><strong>{{ selected.product_name }} · {{ selected.sku }}</strong><p>{{ selected.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') || 'Variante general' }}</p><p>{{ store?.name }} / {{ warehouse?.name }}</p><p>Físico {{ quantity(selected.on_hand) }} · Reservado {{ quantity(selected.reserved) }} · Disponible {{ quantity(selected.available) }}</p></div></div>
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
    <AppDialog :open="priceOpen" title="Actualizar precios de variante" :busy="priceBusy" :return-focus="opener" @close="priceOpen = false">
        <p v-if="priceError" class="notice error" role="alert">{{ priceError }}</p>
        <div v-if="priceTarget" class="notice"><strong>{{ priceTarget.product_name }} · {{ priceTarget.sku }}</strong><p class="muted">{{ priceTarget.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') || 'Variante general' }} · Físico: {{ quantity(priceTarget.on_hand) }}</p></div>
        <form id="inventory-price-form" class="form-stack" @submit.prevent="submitPriceUpdate">
            <fieldset class="form-fields" :disabled="priceBusy">
                <label v-if="canEditCosts">Precio de compra (costo unitario)<input :value="editCostPrice" inputmode="decimal" autocomplete="off" maxlength="20" placeholder="0.00" @input="editCostPrice = ($event.target as HTMLInputElement).value.replace(',', '.')"></label>
                <label>Precio de venta<input :value="editSalePrice" inputmode="decimal" autocomplete="off" required maxlength="20" placeholder="0.00" @input="editSalePrice = ($event.target as HTMLInputElement).value.replace(',', '.')"></label>
            </fieldset>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="priceBusy" @click="priceOpen = false">Cancelar</button><button type="submit" form="inventory-price-form" class="button primary" :disabled="priceBusy">{{ priceBusy ? 'Guardando…' : 'Guardar precios' }}</button></template>
    </AppDialog>
</template>

<style scoped>
.inv-top-bar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; }
.inv-top-bar h2 { margin: 0; font-size: 1.35rem; }
.inv-top-bar p { margin: 2px 0 0; font-size: 0.88rem; }
.inv-top-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.inv-btn-sm { min-height: 38px; padding: 6px 12px; font-size: 0.84rem; white-space: nowrap; }

.inventory-valuation-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 10px; margin-bottom: 14px; }
.valuation-card { display: grid; gap: 2px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; min-width: 0; text-align: left; }
.valuation-card.highlight { background: #f4effc; border-color: #d4c4f0; }
.valuation-card.alert-card { background: #fff8f1; border-color: #f0cfb0; }
.valuation-card-btn { cursor: pointer; font: inherit; color: inherit; }
.valuation-card-btn:hover { border-color: #d49a5a; }
.valuation-card span { font-size: 12px; font-weight: 600; color: var(--muted); }
.valuation-card strong { font-size: 20px; font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; }
.valuation-card small { font-size: 11.5px; color: var(--muted); }

.inv-toolbar { display: grid; gap: 10px; padding: 12px 14px; margin-bottom: 14px; }
.inv-toolbar-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.inv-wh-select { min-width: 175px; flex: 0 1 220px; }
.inv-search-box { flex: 1 1 220px; min-width: 0; }
.inv-wh-select select, .inv-search-box input { width: 100%; min-width: 0; }
.inv-view-toggle { display: inline-flex; background: #f3f0fa; padding: 3px; border-radius: 10px; gap: 3px; }
.inv-view-toggle button { border: 0; background: transparent; padding: 7px 11px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; color: var(--muted); cursor: pointer; white-space: nowrap; }
.inv-view-toggle button.active { background: #fff; color: #6a46d8; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06); }

.inv-chips { display: flex; flex-wrap: wrap; gap: 7px; }
.inv-chip { border: 1px solid var(--line); background: #fff; color: inherit; padding: 6px 12px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
.inv-chip.amber { background: #fff6e5; border-color: #f2d49b; color: #a85c00; }
.inv-chip.red { background: #ffefef; border-color: #f3c4c4; color: #b42318; }
.inv-chip.active { background: #6a46d8; border-color: #6a46d8; color: #fff; }

.inventory-layout { display: grid; gap: 16px; }
.inv-list-panel.mode-list { padding: 0; overflow: hidden; }
.inv-table-head {
    display: grid;
    grid-template-columns: minmax(220px, 2.1fr) minmax(170px, 1.3fr) minmax(145px, 1fr) auto;
    gap: 12px;
    padding: 10px 16px;
    background: #faf8fe;
    border-bottom: 1px solid var(--line);
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
}
.inv-records.mode-list { display: grid; }
.inv-records.mode-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }

.inventory-record.mode-list {
    display: grid;
    grid-template-columns: minmax(220px, 2.1fr) minmax(170px, 1.3fr) minmax(145px, 1fr) auto;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid #f0ecf7;
}
.inventory-record.mode-list:last-child { border-bottom: 0; }
.inventory-record.mode-cards {
    display: grid;
    gap: 10px;
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
}

.inventory-identity { display: flex; align-items: center; gap: 11px; min-width: 0; overflow-wrap: anywhere; }
.inv-ident-text { min-width: 0; }
.inv-prod-name { display: block; font-size: 0.95rem; font-weight: 700; }
.inv-prod-meta { margin: 2px 0 0; font-size: 0.8rem; color: var(--muted); }
.inv-sku { opacity: 0.8; }
.inventory-thumbnail { width: 48px; height: 48px; flex: 0 0 48px; display: grid; place-items: center; overflow: hidden; border-radius: 10px; background: #f0edf7; color: #625a72; font-size: 11px; text-align: center; }
.inventory-thumbnail img { width: 100%; height: 100%; object-fit: contain; }

.inventory-price-line { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; font-size: 0.84rem; color: #4c3d63; }
.inventory-price-line span, .inventory-price-line strong { white-space: nowrap; }
.inv-sale { font-weight: 800; color: #1f1730; }
.inv-cost { color: var(--muted); font-size: 0.8rem; }
.inv-invested { color: #5336ad; font-size: 0.8rem; }

.inv-stock-cell { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
.inv-stock-pill { padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 0.84rem; white-space: nowrap; }
.inv-stock-pill.ok { background: #eaf9f0; color: #127345; }
.inv-stock-pill.low { background: #fff6e5; color: #a85c00; }
.inv-stock-pill.out { background: #ffefef; color: #b42318; }
.inv-stock-sub { font-size: 0.76rem; color: var(--muted); white-space: nowrap; }

.inv-row-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
.kardex-record { display: grid; gap: 8px; padding: 14px 0; border-top: 1px solid var(--line); overflow-wrap: anywhere; font-size: 14px; }
.kardex-record .badge { justify-self: start; }
.kardex-record small { color: var(--muted); }

@media (min-width: 1280px) { .inventory-layout.with-history { grid-template-columns: minmax(0, 1.6fr) minmax(300px, 1fr); align-items: start; } }
@media (max-width: 980px) {
    .inv-table-head { display: none; }
    .inventory-record.mode-list { grid-template-columns: minmax(0, 1fr); gap: 8px; padding: 12px 14px; }
    .inv-row-actions { justify-content: stretch; }
    .inv-row-actions .button { flex: 1 1 auto; }
}
@media (max-width: 600px) {
    .inventory-valuation-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .inv-wh-select, .inv-search-box, .inv-view-toggle { flex: 1 1 100%; width: 100%; }
    .inv-view-toggle button { flex: 1; }
}
</style>
