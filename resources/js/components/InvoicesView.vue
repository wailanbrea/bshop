<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { downloadPrivatePdf } from '../lib/downloadPdf';
import { amount } from '../catalog';
import type { Store } from '../types';
const props = defineProps<{ store?: Store }>(); const emit = defineEmits<{ openOrder: [number] }>();
const rows = ref<{ id: number; invoice_number: string; currency: string; grand_total: string; status: string; issued_at: string; order_id: number | null; customer: { full_name: string; phone?: string | null } | null;net_total?:string;return_credit_total?:string;paid_total?:string;pending_total?:string;return_pending?:boolean }[]>([]);
const query = ref(''); const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref(''); const page = ref(1); const lastPage = ref(1); const root = computed(() => `/business/stores/${props.store?.id}`);
let token = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
async function load(target = 1) {
    if (!props.store) return; const version = ++token; controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    try { const result = await api.get(`${root.value}/invoices`, { signal: controller.signal, params: { q: query.value, page: target } }); if (version === token) { rows.value = result.data.data; page.value = result.data.meta.current_page; lastPage.value = result.data.meta.last_page; } }
    catch (e) { if (version === token && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (version === token) loading.value = false; }
}
async function download(id: number) {
    if (busy.value) return; busy.value = true; error.value = '';
    try { const result = await api.post(`${root.value}/invoices/${id}/document`); await downloadPrivatePdf(result.data.data.url, `${rows.value.find(row => row.id === id)?.invoice_number || 'factura'}.pdf`); }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
function summaryText(invoice: typeof rows.value[number]) {
    const brand = props.store?.name || 'BShop';
    const estado = invoice.status === 'paid' ? 'Pagada' : invoice.status === 'partially_paid' ? 'Pago parcial' : 'Pendiente';
    const lines = [
        `*${brand} · Factura ${invoice.invoice_number}*`,
        `Cliente: ${invoice.customer?.full_name || 'Cliente'}`,
        `Estado: ${estado}`,
        `Total: ${amount(invoice.net_total || invoice.grand_total, invoice.currency)}`,
    ];
    if (invoice.paid_total !== undefined) lines.push(`Abonado: ${amount(invoice.paid_total, invoice.currency)}`);
    if (invoice.pending_total !== undefined) lines.push(`Pendiente: ${amount(invoice.pending_total, invoice.currency)}`);
    return lines.join('\n');
}
function sendWhatsApp(invoice: typeof rows.value[number]) {
    const digits = (invoice.customer?.phone || '').replace(/\D+/g, '');
    const url = `https://wa.me/${digits}?text=${encodeURIComponent(summaryText(invoice))}`;
    window.open(url, '_blank', 'noopener,noreferrer');
}
async function copySummary(invoice: typeof rows.value[number]) {
    notice.value = '';
    try {
        await navigator.clipboard.writeText(summaryText(invoice));
        notice.value = `Resumen de ${invoice.invoice_number} copiado al portapapeles.`;
    } catch {
        error.value = 'No se pudo copiar al portapapeles.';
    }
}
watch(() => props.store?.id, () => load(), { immediate: true }); watch(query, () => { clearTimeout(timer); timer = setTimeout(() => load(), 200); });
onUnmounted(() => { ++token; controller?.abort(); clearTimeout(timer); });
</script>
<template>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice ok" role="status">{{ notice }}</p>
    <section class="panel">
        <div class="panel-heading"><div><h2>Facturas de {{ store?.name || 'la sucursal' }}</h2><p class="muted">Documentos históricos y PDF privados. Reintentar una descarga conserva la misma factura.</p></div><button class="button secondary" :disabled="loading" @click="load(page)">Actualizar facturas</button></div>
        <label>Buscar factura<input v-model="query" type="search" maxlength="80" placeholder="Número de factura"></label><p v-if="loading" role="status">Cargando facturas…</p>
        <article v-for="invoice in rows" :key="invoice.id" class="record invoice-record"><div class="record-main"><strong>{{ invoice.invoice_number }} · {{ invoice.customer?.full_name || 'Cliente histórico' }}</strong><p>Original {{ amount(invoice.grand_total, invoice.currency) }} · {{ invoice.status === 'paid' ? 'Saldo saldado' : invoice.status === 'partially_paid' ? 'Pago parcial' : 'Emitida' }} · {{ invoice.issued_at }}</p>
            <p v-if="invoice.return_pending" class="notice">Devolución recibida por conciliar.</p><p v-if="invoice.return_credit_total&&invoice.return_credit_total!=='0.0000'" class="notice">Ajuste {{ amount(invoice.return_credit_total,invoice.currency) }} · Neto {{ amount(invoice.net_total,invoice.currency) }} · Aplicado {{ amount(invoice.paid_total,invoice.currency) }} · Pendiente {{ amount(invoice.pending_total,invoice.currency) }}</p>
        </div><div class="actions"><button v-if="invoice.order_id" class="button secondary" :disabled="busy" :aria-label="`Abrir pedido de ${invoice.invoice_number}`" @click="emit('openOrder', invoice.order_id!)">Ver pedido</button><button type="button" class="button secondary" :disabled="busy" @click="sendWhatsApp(invoice)">WhatsApp</button><button type="button" class="button secondary" :disabled="busy" @click="copySummary(invoice)">Copiar</button><button class="button primary" :disabled="busy" :aria-label="`Descargar ${invoice.invoice_number}`" @click="download(invoice.id)">PDF</button></div></article>
        <p v-if="!loading && !rows.length" class="empty">Todavía no hay facturas con estos filtros.</p><div v-if="lastPage > 1" class="catalog-pagination"><button class="button secondary" :disabled="page <= 1 || loading" @click="load(page - 1)">Anterior</button><span>{{ page }}/{{ lastPage }}</span><button class="button secondary" :disabled="page >= lastPage || loading" @click="load(page + 1)">Siguiente</button></div>
    </section>
</template>
<style scoped>.invoice-record { flex-wrap: wrap; } .invoice-record .actions { flex-wrap: wrap; }</style>
