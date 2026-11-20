<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import type { Store } from '../types';

interface Row { order_id: number; customer_id: number; customer_name: string; phone: string | null; reference: string; invoiced: boolean; date: string; age_days: number; currency: string; total: string; paid: string; pending: string; return_pending?:boolean;original_total?:string;return_credit_total?:string;credit_available?:string;refunded_total?:string }
interface Summary { currency: string; total: string; paid: string; pending: string; documents: number; customers: number }
const props = defineProps<{ store?: Store; permissions: string[] }>();
const emit = defineEmits<{ openOrder: [number] }>();
const rows = ref<Row[]>([]); const summary = ref<Summary[]>([]);
const query = ref(''); const type = ref('all'); const state = ref('all'); const minDays = ref('0');
const loading = ref(false); const exporting = ref(false); const filtersOpen = ref(false);
const notice = ref('');
const remindingId = ref<number | null>(null);
async function remindWhatsApp(row: Row) {
    if (!props.store?.id || remindingId.value !== null) return;
    remindingId.value = row.order_id;
    notice.value = '';
    error.value = '';
    try {
        await api.post(`/business/stores/${props.store.id}/whatsapp/receivables-reminder`, {
            customer_id: row.customer_id,
            pending_total: row.pending,
            reference: row.reference,
        });
        notice.value = `Recordatorio enviado por WhatsApp API a ${row.customer_name}.`;
    } catch {
        const digits = (row.phone || '').replace(/\D+/g, '');
        const text = `Hola ${row.customer_name}, te recordamos amablemente que tienes un saldo pendiente de ${amount(row.pending, row.currency)} en ${props.store.name} correspondiente a ${row.reference}.`;
        window.open(`https://wa.me/${digits}?text=${encodeURIComponent(text)}`, '_blank', 'noopener,noreferrer');
    } finally {
        remindingId.value = null;
    }
} const error = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const root = computed(() => `/business/stores/${props.store?.id}/receivables`);
const canPay = computed(() => props.permissions.includes('payments.record'));
const filters = () => ({ q: query.value, type: type.value, state: state.value, min_days: minDays.value });
let sequence = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
async function load(target = 1) {
    const token = ++sequence; controller?.abort();
    if (!props.store) { rows.value = []; summary.value = []; loading.value = false; return; }
    controller = new AbortController(); loading.value = true; error.value = '';
    try {
        const result = await api.get(root.value, { params: { ...filters(), page: target }, signal: controller.signal });
        if (token !== sequence) return;
        rows.value = result.data.data; summary.value = result.data.summary;
        page.value = result.data.meta.current_page; lastPage.value = result.data.meta.last_page; total.value = result.data.meta.total;
    } catch (e) { if (token === sequence && !axios.isCancel(e)) { error.value = errorMessage(e); rows.value = []; summary.value = []; } }
    finally { if (token === sequence) loading.value = false; }
}
async function exportCsv() {
    if (!props.store || exporting.value || loading.value) return;
    exporting.value = true; error.value = '';
    try {
        const response = await api.get(`${root.value}/export`, { params: filters(), responseType: 'blob' });
        const url = URL.createObjectURL(response.data); const link = document.createElement('a');
        link.href = url; link.download = 'cuentas-por-cobrar.csv'; document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) { error.value = errorMessage(e); }
    finally { exporting.value = false; }
}
watch(() => props.store?.id, () => load(), { immediate: true });
watch([query, type, state, minDays], () => { clearTimeout(timer); ++sequence; controller?.abort(); loading.value = true; timer = setTimeout(() => load(), 250); });
onUnmounted(() => { ++sequence; controller?.abort(); clearTimeout(timer); });
</script>
<template>
    <section class="receivables" aria-label="Cuentas por cobrar">
        <div class="panel-heading"><div><h2>Saldos de {{ store?.name || 'la sucursal' }}</h2><p class="muted">Facturas y apartados con saldo pendiente.</p></div><div class="actions"><button type="button" class="button secondary" :disabled="loading || !store" @click="load(page)">Actualizar saldos</button><button type="button" class="button primary" :disabled="loading || exporting || !store || !!error || !rows.length" @click="exportCsv">{{ exporting ? 'Exportando…' : 'Exportar CSV' }}</button></div></div>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <div class="cxc-filters panel">
            <div class="cxc-search-row">
                <label class="cxc-search-label">Buscar cliente o documento<input v-model="query" type="search" maxlength="120" placeholder="Nombre, teléfono, factura o apartado"></label>
                <button type="button" class="button secondary cxc-filter-toggle" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">Filtros {{ filtersOpen ? '▴' : '▾' }}</button>
            </div>
            <div class="cxc-adv-filters" :class="{ open: filtersOpen }">
                <label>Documento<select v-model="type"><option value="all">Todos</option><option value="invoiced">Facturas</option><option value="unbilled">Apartados sin facturar</option></select></label>
                <label>Abonos<select v-model="state"><option value="all">Todos los pendientes</option><option value="partial">Con abonos</option><option value="unpaid">Sin abonos</option></select></label>
                <label>Antigüedad<select v-model="minDays"><option value="0">Cualquier antigüedad</option><option value="30">30 días o más</option><option value="60">60 días o más</option><option value="90">90 días o más</option></select></label>
            </div>
        </div>
        <p class="muted cxc-note">Antigüedad desde la factura o la creación del apartado. No indica vencimiento. Los resúmenes corresponden a todos los resultados filtrados y se separan por moneda.</p>
        <p v-if="loading" role="status">Actualizando cuentas por cobrar…</p>
        <template v-else-if="!error">
            <section v-for="item in summary" :key="item.currency" class="cxc-summary" :aria-label="`Resumen ${item.currency}`">
                <article class="stat-card"><span>Por cobrar · {{ item.currency }}</span><strong>{{ amount(item.pending, item.currency) }}</strong><p>{{ item.customers }} clientes · {{ item.documents }} documentos</p></article>
                <article class="stat-card"><span>Abonos de estos documentos</span><strong>{{ amount(item.paid, item.currency) }}</strong></article>
                <article class="stat-card"><span>Total de estos documentos</span><strong>{{ amount(item.total, item.currency) }}</strong></article>
            </section>
            <div class="panel"><p class="muted">{{ total }} documentos pendientes · Los más antiguos primero</p>
                <article v-for="row in rows" :key="row.order_id" class="cxc-row">
                    <div><strong>{{ row.customer_name }}</strong><p class="muted">{{ row.phone || 'Sin teléfono' }}</p><span class="badge">{{ row.invoiced ? 'Factura' : 'Apartado' }}</span></div>
                    <div><strong>{{ row.reference }}</strong><p class="muted">{{ row.date }} · {{ row.age_days }} días</p><p v-if="row.return_pending" class="notice">Devolución recibida: importes originales pendientes de ajuste. Cobro bloqueado.</p><p v-if="row.return_credit_total&&row.return_credit_total!=='0.0000'" class="muted">Original {{ amount(row.original_total,row.currency) }} · Ajuste {{ amount(row.return_credit_total,row.currency) }} · Total neto {{ amount(row.total,row.currency) }}</p></div>
                    <dl><div><dt>Total</dt><dd>{{ amount(row.total, row.currency) }}</dd></div><div><dt>Abonado</dt><dd>{{ amount(row.paid, row.currency) }}</dd></div><div class="cxc-due"><dt>Pendiente</dt><dd>{{ amount(row.pending, row.currency) }}</dd></div></dl>
                    <div class="actions" style="flex-wrap: wrap;">
                        <button type="button" class="button secondary" :disabled="remindingId === row.order_id" @click="remindWhatsApp(row)">{{ remindingId === row.order_id ? 'Enviando…' : 'Recordar WS' }}</button>
                        <button type="button" class="button secondary" :aria-label="`${row.return_pending ? 'Ver devolución' : canPay ? 'Ver pedido y abonar' : 'Ver pedido'} ${row.reference}`" @click="emit('openOrder', row.order_id)">{{ row.return_pending ? 'Ver devolución' : canPay ? 'Ver pedido y abonar' : 'Ver pedido' }}</button>
                    </div>
                </article>
                <p v-if="!rows.length" class="empty">No hay saldos pendientes con estos filtros.</p>
                <div v-if="lastPage > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1" @click="load(page - 1)">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage" @click="load(page + 1)">Siguiente</button></div>
            </div>
        </template>
    </section>
</template>
<style scoped>
.cxc-filters { display:grid; grid-template-columns:2fr 3fr; gap:14px; align-items:end; }
.cxc-search-row { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; align-items:end; }
.cxc-search-label { min-width:0; }
.cxc-adv-filters { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
.cxc-filters label { min-width:0; }
.cxc-filter-toggle { min-height:44px; white-space:nowrap; }
@media(min-width:768px) { .cxc-filter-toggle { display:none; } }
@media(max-width:767px) { .cxc-filters { grid-template-columns:1fr; } .cxc-adv-filters { grid-template-columns:1fr; } .cxc-adv-filters:not(.open) { display:none; } }
.cxc-note { margin:14px 0; font-size:13px; }
.cxc-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin:18px 0; }
.cxc-summary strong { display:block; font-size:clamp(20px,2.2vw,28px); white-space:nowrap; max-width:100%; overflow-x:auto; }
.cxc-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(170px,1fr) auto; gap:18px; padding:20px 0; border-bottom:1px solid var(--line); align-items:center; }
.cxc-row > div { min-width:0; overflow-wrap:anywhere; }
.cxc-row dl { margin:0; min-width:0; }
.cxc-row dl > div { display:flex; justify-content:space-between; gap:10px; }
.cxc-row dd { margin:0; white-space:nowrap; max-width:100%; overflow-x:auto; }
.cxc-due { font-weight:750; color:#5630d8; }
.receivables > .panel-heading, .receivables .actions { flex-wrap:wrap; }
@media(max-width:1100px) { .cxc-filters { grid-template-columns:1fr 1fr; } .cxc-row { grid-template-columns:minmax(0,1fr) minmax(0,1fr); } .cxc-row > button { justify-self:end; } }
@media(max-width:600px) { .cxc-filters,.cxc-summary { grid-template-columns:1fr; } .cxc-row { grid-template-columns:1fr; gap:12px; } .cxc-row > button { justify-self:stretch; } }
</style>
