<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref, watch, useId } from 'vue';
import { api, errorMessage } from '../api';
import type { SalesCustomer, CustomerSummary } from '../sales';
import { amount } from '../catalog';
import AppDialog from './AppDialog.vue';
import CustomerContactImportModal from './CustomerContactImportModal.vue';
import { readPending, savePending, clearPending } from '../lib/pendingRequests';

const props = withDefaults(defineProps<{ root: string; selected: SalesCustomer | null; manage: boolean; currency: string; actorId: number; refresh?: number; canSummary?: boolean; allowArchived?: boolean }>(), { canSummary: true });
const emit = defineEmits<{ select: [SalesCustomer]; openOrder: [number]; summary:[number,CustomerSummary|null] }>();
const componentId = useId(); const searchId = `${componentId}-customer-search`; const formId = `${componentId}-customer-form`;
const journalKey = `bshop:pending-customer:${props.actorId}:${props.root}`;
const filter = ref('active');
const query = ref(''); const customers = ref<SalesCustomer[]>([]); const loading = ref(false); const error = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0); const summary = ref<CustomerSummary | null>(null);
const listError = ref(''); const requestedPage = ref(1);
const open = ref(false); const importOpen = ref(false); const importOpener = ref<HTMLElement | null>(null); const busy = ref(false); const fullName = ref(''); const phone = ref(''); const email = ref(''); const address = ref(''); const notes = ref(''); const opener = ref<HTMLElement | null>(null); const pending = ref<Record<string, unknown> | null>(null);
let token = 0; let summaryToken = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
async function load(target = 1) {
    clearTimeout(timer); requestedPage.value = target;
    const version = ++token; controller?.abort(); controller = new AbortController(); loading.value = true; listError.value = '';
    try { const result = await api.get(`${props.root}/customers`, { signal: controller.signal, params: { q: query.value, page: target, ...(props.allowArchived ? {status:filter.value} : {}) } }); if (version === token) { if (result.data.meta.current_page > result.data.meta.last_page) { await load(Math.max(1,result.data.meta.last_page)); return; } customers.value = result.data.data; total.value = result.data.meta.total; page.value = result.data.meta.current_page; lastPage.value = result.data.meta.last_page; } }
    catch (e) { if (version === token && !axios.isCancel(e)) listError.value = errorMessage(e); }
    finally { if (version === token) loading.value = false; }
}
watch(filter, () => load());
watch(() => props.refresh, () => load(page.value));
watch(query, () => { ++token; controller?.abort(); loading.value = true; listError.value = ''; requestedPage.value = 1; clearTimeout(timer); timer = setTimeout(() => load(), 200); });
watch(() => props.root, () => load(), { immediate: true });
watch(() => [props.selected?.id, props.refresh] as const, async ([id]) => {
    const version = ++summaryToken; summary.value = null; if (id) emit('summary',id,null); if (!id || !props.canSummary) return;
    try { const result = await api.get(`${props.root}/customers/${id}/summary`); if (version === summaryToken) { summary.value = result.data.data; emit('summary',id,summary.value); } }
    catch (e) { if (version === summaryToken) error.value = errorMessage(e); }
}, { immediate: true });
function newCustomer(event: Event) { opener.value = event.currentTarget as HTMLElement; open.value = true; fullName.value = ''; phone.value = ''; email.value = ''; address.value = ''; notes.value = ''; pending.value = null; error.value = ''; }
async function create() {
    if (busy.value) return; busy.value = true; error.value = '';
    pending.value ??= { request_key: crypto.randomUUID(), full_name: fullName.value, phone: phone.value || null, email: email.value || null, address: address.value || null, notes: notes.value || null };
    try { savePending(journalKey, pending.value); } catch { pending.value = null; busy.value = false; error.value = 'No se pudo conservar la recuperación. No se envió el alta.'; return; }
    try { const result = await api.post(`${props.root}/customers`, pending.value); emit('select', result.data.data.customer); pending.value = null; clearPending(journalKey); open.value = false; await load(); }
    catch (e) { error.value = errorMessage(e); if (axios.isAxiosError(e) && e.response && [409, 422].includes(e.response.status)) { pending.value = null; clearPending(journalKey); } }
    finally { busy.value = false; }
}
function customerChanged() { if (!props.allowArchived) load(page.value); }
onMounted(async () => {
    window.addEventListener('bshop:customer-changed',customerChanged);
    const recovery = readPending(journalKey); if (!recovery || typeof recovery.request_key !== 'string') return;
    pending.value = recovery; busy.value = true;
    try { const result = (await api.get(`${props.root}/customers/requests/${recovery.request_key}`)).data.data; pending.value = null; clearPending(journalKey); emit('select', result.customer); }
    catch (e) {
        if (axios.isAxiosError(e) && e.response?.status === 404) { fullName.value = String(recovery.full_name || ''); phone.value = String(recovery.phone || ''); email.value = String(recovery.email || ''); address.value = String(recovery.address || ''); notes.value = String(recovery.notes || ''); open.value = true; error.value = 'Hay un alta sin confirmación. Reintenta el mismo registro.'; }
        else error.value = errorMessage(e);
    } finally { busy.value = false; }
});
onUnmounted(() => { window.removeEventListener('bshop:customer-changed',customerChanged); ++token; ++summaryToken; controller?.abort(); clearTimeout(timer); });
</script>
<template>
    <section class="sales-customers sales-surface" :class="{'customer-directory':allowArchived}" aria-label="Seleccionar cliente">
        <div class="directory-heading"><h2>{{ allowArchived ? 'Directorio' : 'Seleccionar cliente' }}</h2><span v-if="allowArchived" class="directory-count">{{ total }}</span></div><label class="sr-only" :for="searchId">Buscar cliente</label><input :id="searchId" v-model="query" type="search" maxlength="120" placeholder="Nombre o teléfono…" autocomplete="off">
        <label v-if="allowArchived">Estado de clientes<select v-model="filter"><option value="active">Activos</option><option value="archived">Archivados</option><option value="all">Todos</option></select></label>
        <p v-if="error && !open" class="notice error" role="alert">{{ error }}</p><p v-if="loading" class="muted" role="status">Buscando clientes…</p>
        <div v-if="listError" class="notice error" role="alert"><p>{{ listError }}</p><button type="button" class="button secondary" :disabled="loading" @click="load(requestedPage)">Reintentar búsqueda de clientes</button></div>
        <div class="sales-customer-list" :aria-busy="loading"><button v-for="customer in customers" :key="customer.id" type="button" class="sales-customer" :class="{ selected: selected?.id === customer.id }" :aria-pressed="selected?.id === customer.id" :disabled="busy || !!pending || loading || !!listError" @click="emit('select', customer)"><span class="customer-initials">{{ customer.full_name.split(' ').slice(0,2).map(v => v[0]).join('') }}</span><span><strong>{{ customer.full_name }}</strong><small>{{ customer.phone || 'Sin teléfono' }}</small><small v-if="customer.status === 'archived'">Archivado</small></span><span v-if="selected?.id === customer.id" class="selected-check">✓</span></button></div>
        <p v-if="!loading && !listError && !customers.length" class="muted">No hay clientes con esa búsqueda.</p>
        <div v-if="lastPage > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1 || loading || !!listError" @click="load(page - 1)">Anterior</button><span>{{ page }}/{{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage || loading || !!listError" @click="load(page + 1)">Siguiente</button></div>
        <article v-if="selected && !allowArchived" class="customer-summary"><h3>{{ selected.full_name }}</h3><p>{{ selected.phone || 'Sin teléfono' }}</p><template v-if="summary"><dl><dt>Compras</dt><dd>{{ summary.purchase_count }}</dd><dt>Saldo pendiente en esta sucursal</dt><dd>{{ amount(summary.pending_total, currency) }}</dd></dl><div v-if="summary.open_baskets.length"><h4>Apartados abiertos</h4><button v-for="basket in summary.open_baskets" :key="basket.id" type="button" class="text-button" @click="emit('openOrder', basket.id)">Abrir #{{ basket.id }} · {{ amount(basket.total, currency) }}</button></div></template><p v-if="summary?.customer?.notes || selected.notes" class="customer-notes">{{ summary?.customer?.notes || selected.notes }}</p></article>
        <div v-if="manage" class="customer-action-stack">
            <button type="button" class="button secondary full" :disabled="busy || !!pending" @click="newCustomer">+ Nuevo cliente</button>
            <button type="button" class="button secondary full" :disabled="busy || !!pending" @click="(e) => { importOpener = e.currentTarget as HTMLElement; importOpen = true; }">Importar WhatsApp</button>
        </div>
    </section>
    <CustomerContactImportModal :open="importOpen" :root="root" :opener="importOpener" @close="importOpen = false" @imported="() => load(1)" />
    <AppDialog :open="open" title="Nuevo cliente" :busy="busy || !!pending" :return-focus="opener" @close="open = false">
        <p v-if="error" class="notice error" role="alert">{{ error }}</p><form :id="formId" @submit.prevent="create"><fieldset class="form-fields" :disabled="busy || !!pending"><label>Nombre completo<input v-model="fullName" required maxlength="180" autocomplete="name"></label><label>Teléfono<input v-model="phone" type="tel" maxlength="40" autocomplete="tel"></label><label>Correo electrónico<input v-model="email" type="email" maxlength="190" autocomplete="email"></label><label>Dirección<input v-model="address" maxlength="255" autocomplete="street-address"></label><label>Notas del cliente<textarea v-model="notes" rows="3" maxlength="4000"></textarea></label></fieldset></form>
        <template #footer><button class="button secondary" :disabled="busy || !!pending" @click="open = false">Cancelar</button><button class="button primary" type="submit" :form="formId" :disabled="busy">{{ pending ? 'Reintentar alta' : 'Crear y seleccionar' }}</button></template>
    </AppDialog>
</template>

<style scoped>
.customer-directory { background:white; border:1px solid #e7e3f1; border-radius:20px; padding:20px; box-shadow:0 6px 24px #29204604; }
.customer-directory .directory-heading { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.customer-directory .directory-heading h2 { font-size:18px; margin:0; font-weight:700; color:#3d3253; }
.directory-count { display:flex; align-items:center; justify-content:center; min-width:28px; height:28px; padding:0 8px; color:#947bb6; background:#f3effa; border-radius:8px; font-size:12px; font-weight:650; }
.customer-action-stack { display:grid; gap:8px; margin-top:10px; }
.customer-directory > input { background:#faf9fd; border-color:#e6e0ef; border-radius:12px; }
.customer-directory > label:not(.sr-only) { display:grid; gap:8px; margin-top:16px; font-size:12px; color:#796b87; font-weight:550; }
.customer-directory select { border-color:#e6e0ef; border-radius:12px; color:#5d4e72; }
.customer-directory .sales-customer-list { display:block; max-height:440px; margin:16px 0 20px; overflow-x:hidden; }
.customer-directory .sales-customer { min-width:0; min-height:76px; border:1px solid transparent; border-radius:13px; padding:12px 10px; margin-bottom:4px; }
.customer-directory .sales-customer.selected { border-color:#e5d8fa; background:#f3edff; }
.customer-directory .sales-customer:hover:not(.selected) { background:#faf8fd; }
.customer-directory .sales-customer strong { font-size:14px; color:#493d5f; }
.customer-directory .sales-customer small { color:#7c6f88; font-size:12px; overflow-wrap:anywhere; }
.customer-directory .customer-initials { width:40px; height:40px; border-radius:13px; font-size:13px; color:#9a79c5; background:#f1eafa; }
.customer-directory .sales-customer.selected .customer-initials { background:white; color:#8963bd; }
.customer-directory > .button { font-size:14px; border-color:#ded3ef; border-radius:12px; background:#faf7ff; color:#8160af; }
@media(max-width:767px) { .customer-directory .sales-customer-list { max-height:240px; } }
</style>
