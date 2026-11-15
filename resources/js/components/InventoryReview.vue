<script setup lang="ts">
import axios from 'axios';
import { onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { quantity } from '../inventory';
import type { Store, Warehouse } from '../types';
import AppDialog from './AppDialog.vue';

interface Row { variant_id: number; sku: string; product_name: string; physical: string; reserved: string; ledger_physical: string; ledger_reserved: string; active_reserved: string; differences: {code:string;message:string}[] }
interface Report { data: Row[]; summary: {checked:number;consistent:number;with_differences:number;differences:number;movements:number}; generated_at:string; meta:{current_page:number;last_page:number;total:number} }
const props = defineProps<{ store?: Store; warehouse?: Warehouse }>();
const open = ref(false); const opener = ref<HTMLElement | null>(null);
const loading = ref(false); const error = ref(''); const onlyIssues = ref(true); const report = ref<Report | null>(null);
let sequence = 0; let controller: AbortController | undefined;
async function load(page = 1) {
    if (!props.store || !props.warehouse || !open.value) return;
    const token = ++sequence; controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    try {
        const response = await api.get(`/business/stores/${props.store.id}/warehouses/${props.warehouse.id}/reconciliation`, {signal:controller.signal, params:{page,only_issues:onlyIssues.value ? 1 : 0}});
        if (token === sequence) report.value = response.data;
    } catch (e) { if (token === sequence && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (token === sequence) loading.value = false; }
}
function show(event: Event) { opener.value = event.currentTarget as HTMLElement; report.value = null; open.value = true; onlyIssues.value = true; load(); }
function close() { ++sequence; controller?.abort(); open.value = false; loading.value = false; }
watch(() => props.warehouse?.id, close);
onUnmounted(close);
</script>
<template>
    <button type="button" class="button secondary" :disabled="!warehouse" @click="show">Revisar inventario</button>
    <AppDialog :open="open" title="Revisión de inventario" :return-focus="opener" @close="close">
        <p class="muted">{{ store?.name }} · {{ warehouse?.name }}</p>
        <p class="notice">Compara existencias, reservas y movimientos. No modifica el inventario.</p>
        <label class="check-label"><input v-model="onlyIssues" type="checkbox" :disabled="loading" @change="load()">Mostrar solo diferencias</label>
        <p v-if="loading" role="status">Revisando saldos, reservas y movimientos…</p>
        <p v-else-if="error" class="notice error" role="alert">{{ error }}</p>
        <template v-else-if="report">
            <div class="review-summary" aria-label="Resultado de revisión"><strong>{{ report.summary.checked }} variantes revisadas</strong><span>{{ report.summary.consistent }} sin diferencias</span><span :class="{'review-warning':report.summary.with_differences > 0}">{{ report.summary.with_differences }} con diferencias</span></div>
            <small class="muted">Consulta: {{ new Date(report.generated_at).toLocaleString('es') }} · {{ report.summary.movements }} movimientos</small>
            <article v-for="row in report.data" :key="row.variant_id" class="review-row">
                <strong>{{ row.product_name }}</strong><p class="muted">{{ row.sku }}</p>
                <dl><div><dt>Físico actual</dt><dd>{{ quantity(row.physical) }}</dd></div><div><dt>Físico según movimientos</dt><dd>{{ quantity(row.ledger_physical) }}</dd></div><div><dt>Reservado actual</dt><dd>{{ quantity(row.reserved) }}</dd></div><div><dt>Según reservas activas</dt><dd>{{ quantity(row.active_reserved) }}</dd></div><div><dt>Reservado según movimientos</dt><dd>{{ quantity(row.ledger_reserved) }}</dd></div></dl>
                <ul v-if="row.differences.length" class="review-warning"><li v-for="issue in row.differences" :key="issue.code">{{ issue.message }}</li></ul><p v-else class="muted">Sin diferencias.</p>
            </article>
            <p v-if="!report.data.length" class="empty">{{ onlyIssues ? 'No se detectaron diferencias en este almacén.' : 'No hay variantes para revisar.' }}</p>
            <div v-if="report.meta.last_page > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="report.meta.current_page <= 1" @click="load(report.meta.current_page - 1)">Anterior</button><span>{{ report.meta.current_page }} / {{ report.meta.last_page }}</span><button type="button" class="button secondary" :disabled="report.meta.current_page >= report.meta.last_page" @click="load(report.meta.current_page + 1)">Siguiente</button></div>
        </template>
        <template #footer><button type="button" class="button secondary" :disabled="loading" @click="load()">Actualizar revisión</button><button type="button" class="button primary" @click="close">Cerrar revisión</button></template>
    </AppDialog>
</template>
<style scoped>
.button { min-height:48px; }
.review-summary { display:flex; flex-wrap:wrap; gap:12px; margin:16px 0; }
.review-row { padding:18px 0; border-bottom:1px solid var(--line); overflow-wrap:anywhere; }
.review-row dl { display:grid; gap:6px; margin:12px 0; }
.review-row dl > div { display:flex; justify-content:space-between; gap:12px; }
.review-row dd { margin:0; flex-shrink:0; font-variant-numeric:tabular-nums; }
.review-warning { color:#93440d; }
.review-row ul { padding-left:20px; }
.check-label { display:flex; align-items:center; gap:12px; min-height:48px; }
.check-label > input { width:24px; height:24px; }
</style>
