<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref, useId } from 'vue';
import { api, errorMessage } from '../api';
import type { SalesCustomer, CustomerSummary } from '../sales';
import { clearPending, readPending, savePending } from '../lib/pendingRequests';
import AppDialog from './AppDialog.vue';
import AppIcon from './AppIcon.vue';
import { amount } from '../catalog';
import { notifyCustomerChanged } from '../lib/customerEvents';
import OrderRequestLinkManager from './OrderRequestLinkManager.vue';

type Operation = {kind:'update'|'status'; customer:SalesCustomer; payload:Record<string,unknown> & {request_key:string}};
const props = defineProps<{root:string; actorId:number; customer:SalesCustomer|null; manage:boolean; summary?:CustomerSummary|null; canSummary?:boolean; currency?:string; storeName?:string; canRequestOrder?:boolean}>();
const emit = defineEmits<{changed:[SalesCustomer]; openOrder:[number]}>();
const initials = computed(() => props.customer?.full_name.trim().split(/\s+/).filter(Boolean).slice(0,2).map(n => n[0]).join('').toLocaleUpperCase('es') || '');
const journal = `bshop:pending-customer-management:${props.actorId}:${props.root}`;
const formId = `${useId()}-customer-edit`;
const modal = ref<'update'|'status'|null>(null); const target = ref<SalesCustomer|null>(null);
const busy = ref(false); const pending = ref<Operation|null>(null); const error = ref(''); const notice = ref('');
const opener = ref<HTMLElement|null>(null); const errorNotice = ref<HTMLElement|null>(null);
const form = ref({full_name:'',phone:'',email:'',address:'',notes:''});
const status = computed(() => target.value?.status === 'archived' ? 'active' : 'archived');
const title = computed(() => modal.value === 'update' ? 'Editar cliente' : status.value === 'active' ? 'Reactivar cliente' : 'Archivar cliente');
const canChange = computed(() => props.manage && !busy.value && !pending.value && Number.isInteger(props.customer?.lock_version));
function fill(customer:SalesCustomer) {
    target.value = {...customer};
    form.value = {full_name:customer.full_name,phone:customer.phone || '',email:customer.email || '',address:customer.address || '',notes:customer.notes || ''};
}
function open(kind:'update'|'status', event:Event) {
    if (!props.customer || !canChange.value) return;
    opener.value = event.currentTarget as HTMLElement; fill(props.customer); modal.value = kind; error.value = ''; notice.value = '';
}
function complete(customer:SalesCustomer, recovered = false) {
    const kind = pending.value?.kind;
    clearPending(journal); pending.value = null; modal.value = null; error.value = '';
    notice.value = recovered ? 'Operación recuperada. Se muestra la ficha actual.' : kind === 'update' ? 'Ficha del cliente actualizada.' : customer.status === 'archived' ? 'Cliente archivado.' : 'Cliente reactivado.';
    emit('changed',customer); notifyCustomerChanged(customer);
}
async function send() {
    if (busy.value || !props.manage) return;
    if (!pending.value) {
        if (!target.value || !modal.value || !Number.isInteger(target.value.lock_version)) return;
        const payload = modal.value === 'update' ? {...form.value,phone:form.value.phone || null,email:form.value.email || null,address:form.value.address || null,notes:form.value.notes || null} : {status:status.value};
        const operation:Operation = {kind:modal.value,customer:{...target.value},payload:{...payload,version:target.value.lock_version,request_key:crypto.randomUUID()}};
        try { savePending(journal, operation as unknown as Record<string,unknown>); pending.value = operation; }
        catch { error.value = 'No se pudo conservar la recuperación. No se enviaron los cambios.'; return; }
    }
    busy.value = true; error.value = '';
    const operation = pending.value;
    try {
        const result = await api.patch(`${props.root}/customers/${operation.customer.id}${operation.kind === 'status' ? '/status' : ''}`, operation.payload);
        complete(result.data.data.customer);
    } catch (e) {
        error.value = errorMessage(e);
        if (axios.isAxiosError(e) && e.response && [409,422].includes(e.response.status)) { pending.value = null; clearPending(journal); }
        await nextTick(); errorNotice.value?.focus();
    } finally { busy.value = false; }
}
async function refresh() {
    if (!props.customer || busy.value || pending.value) return;
    const id = props.customer.id; busy.value = true; error.value = ''; notice.value = '';
    try { const result = await api.get(`${props.root}/customers/${id}`); if (props.customer?.id === id) emit('changed',result.data.data); notifyCustomerChanged(result.data.data); }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
async function discardAndRefresh() { modal.value = null; await refresh(); }
async function recover() {
    const operation = pending.value; if (!operation || busy.value) return;
    fill(operation.customer);
    if (operation.kind === 'update') {
        for (const key of ['full_name','phone','email','address','notes'] as const) form.value[key] = String(operation.payload[key] || '');
    }
    modal.value = operation.kind; busy.value = true; error.value = '';
    try {
        const result = await api.get(`${props.root}/customers/requests/${operation.payload.request_key}`, {params:{action:operation.kind}});
        complete(result.data.data.customer, true);
    } catch (e) {
        error.value = axios.isAxiosError(e) && e.response?.status === 404 ? 'No se pudo confirmar la operación. Reintenta los mismos cambios.' : errorMessage(e);
    } finally { busy.value = false; }
}
onMounted(() => {
    const stored = readPending(journal) as unknown as Operation|null;
    if (!stored) return;
    if (!['status','update'].includes(stored.kind) || !Number.isInteger(stored.customer?.id) || typeof stored.payload?.request_key !== 'string') { error.value = 'No se pudo leer la recuperación del cliente. Revisa su ficha antes de modificarla.'; return; }
    pending.value = stored; recover();
});
</script>
<template>
    <section class="panel customer-profile" aria-label="Ficha del cliente">
        <div class="profile-toolbar"><h2>Ficha del cliente</h2><button v-if="customer" type="button" class="profile-refresh" :disabled="busy || !!pending" aria-label="Actualizar ficha" title="Actualizar ficha" @click="refresh"><AppIcon name="refresh" :size="20" /></button></div>
        <div v-if="notice || (error && !modal) || (pending && !modal)" class="profile-messages">
            <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
            <p v-if="error && !modal" class="notice error" role="alert">{{ error }}</p>
            <p v-if="pending && !modal" class="notice">Hay cambios sin confirmación. <button type="button" class="text-button" :disabled="busy" @click="recover">Revisar operación pendiente</button></p>
        </div>
        <template v-if="customer">
            <header class="profile-hero">
                <span class="profile-avatar" aria-hidden="true">{{ initials }}</span>
                <div class="profile-identity"><span class="profile-eyebrow">CLIENTE</span><h3>{{ customer.full_name }}</h3><span class="profile-status" :class="{'is-archived':customer.status === 'archived'}"><i aria-hidden="true"></i>{{ customer.status === 'archived' ? 'Archivado' : 'Activo' }}</span></div>
                <button v-if="manage" type="button" class="button primary profile-edit" :disabled="!canChange" @click="open('update',$event)"><AppIcon name="edit" :size="18" />Editar cliente</button>
            </header>
            <div class="profile-body">
                <OrderRequestLinkManager v-if="canRequestOrder" :key="`${root}:${customer.id}`" :root="root" :customer-id="customer.id" :customer-active="customer.status!=='archived'" :currency="currency || 'USD'" />
                <section v-if="canSummary" class="customer-summary profile-activity" aria-label="Actividad del cliente" :aria-busy="!summary">
                    <div class="profile-section-heading"><h4>Resumen comercial</h4><span>{{ storeName || 'Esta sucursal' }}</span></div>
                    <div class="profile-metrics">
                        <div class="profile-metric balance-metric" :class="{'metric-wide':summary && amount(summary.pending_total, summary.currency || currency || 'USD').length > 16}"><span>Saldo pendiente</span><strong class="profile-money">{{ summary ? amount(summary.pending_total, summary.currency || currency || 'USD') : '—' }}</strong></div>
                        <div class="profile-metric"><span>Compras</span><strong>{{ summary ? summary.purchase_count : '—' }}</strong></div>
                        <div class="profile-metric"><span>Apartados abiertos</span><strong>{{ summary ? summary.open_baskets.length : '—' }}</strong></div>
                    </div>
                </section>
                <section class="profile-contact" aria-label="Datos de contacto">
                    <p v-if="summary?.credit_available&&summary.credit_available!=='0.0000'" class="notice">Saldo a favor disponible en esta sucursal: {{ amount(summary.credit_available,currency||'USD') }}. Puede aplicarse desde otro pedido facturado del mismo cliente mediante Saldo a favor del cliente.</p>
                    <p v-if="summary?.pending_returns" class="notice">Hay {{ summary.pending_returns }} recepciones de mercancía devuelta pendientes de ajuste económico. Los saldos originales están por conciliar.</p>
                    <div class="profile-section-heading"><h4>Datos de contacto</h4></div>
                    <dl class="profile-contact-grid">
                        <div class="contact-item"><span class="contact-icon" aria-hidden="true"><AppIcon name="phone" :size="20" /></span><div><dt>Teléfono</dt><dd>{{ customer.phone || 'Sin teléfono' }}</dd></div></div>
                        <div class="contact-item"><span class="contact-icon" aria-hidden="true"><AppIcon name="mail" :size="20" /></span><div><dt>Correo electrónico</dt><dd>{{ customer.email || 'Sin correo' }}</dd></div></div>
                        <div class="contact-item contact-address"><span class="contact-icon" aria-hidden="true"><AppIcon name="pin" :size="20" /></span><div><dt>Dirección</dt><dd>{{ customer.address || 'Sin dirección' }}</dd></div></div>
                    </dl>
                </section>
                <section class="profile-notes" aria-label="Notas del cliente"><span class="notes-icon" aria-hidden="true"><AppIcon name="note" :size="20" /></span><div><h4>Notas</h4><p class="customer-notes" :class="{'no-notes':!customer.notes}">{{ customer.notes || 'Sin notas registradas.' }}</p></div></section>
                <section v-if="canSummary && summary?.open_baskets.length" class="profile-orders" aria-label="Apartados abiertos del cliente">
                    <div class="profile-section-heading"><h4>Apartados abiertos</h4><span class="profile-order-count">{{ summary.open_baskets.length }}</span></div>
                    <button v-for="basket in summary.open_baskets" :key="basket.id" type="button" class="profile-order" :aria-label="'Abrir apartado #' + basket.id" @click="emit('openOrder',basket.id)"><span class="order-icon" aria-hidden="true"><AppIcon name="bag" :size="20" /></span><span class="order-description"><strong>Apartado #{{ basket.id }}</strong><small>{{ basket.status === 'confirmed' ? 'Facturado' : basket.status === 'fulfilled' ? 'Entregado' : 'Reserva activa' }}</small></span><span class="order-total"><small>Total</small><strong>{{ amount(basket.total, summary.currency || currency || 'USD') }}</strong></span><AppIcon name="arrow" :size="18" /></button>
                </section>
            </div>
            <footer v-if="manage" class="profile-footer"><button type="button" class="profile-archive" :disabled="!canChange" @click="open('status',$event)"><AppIcon :name="customer.status === 'archived' ? 'refresh' : 'archive'" :size="18" />{{ customer.status === 'archived' ? 'Reactivar cliente' : 'Archivar cliente' }}</button></footer>
        </template>
        <div v-else class="profile-empty"><span><AppIcon name="users" :size="32" /></span><h3>Selecciona un cliente</h3><p>Consulta sus datos de contacto y su actividad.</p></div>
    </section>
    <AppDialog :open="modal !== null" :title="title" :return-focus="opener" :busy="busy" @close="modal = null">
        <p v-if="error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        <p v-if="pending" class="notice" role="status">Los cambios están pendientes de confirmación. Reintentar conserva la misma operación.</p>
        <form :id="formId" class="form-stack" @submit.prevent="send">
            <fieldset class="form-fields" :disabled="busy || !!pending || !manage">
                <template v-if="modal === 'update'"><label>Nombre completo<input v-model="form.full_name" required maxlength="180" autocomplete="name"></label><label>Teléfono<input v-model="form.phone" type="tel" maxlength="40" autocomplete="tel"></label><label>Correo electrónico<input v-model="form.email" type="email" maxlength="190" autocomplete="email"></label><label>Dirección<input v-model="form.address" maxlength="255" autocomplete="street-address"></label><label>Notas del cliente<textarea v-model="form.notes" rows="3" maxlength="4000"></textarea></label></template>
                <template v-else><p><strong>{{ target?.full_name }}</strong></p><p v-if="status === 'archived'">Se retirará de la selección para nuevas ventas en todas las sucursales. Sus deudas, pedidos y documentos se conservan, y se podrán seguir registrando abonos en pedidos existentes.</p><p v-else>Volverá a estar disponible para nuevas ventas en todas las sucursales. Sus saldos y documentos se conservan.</p></template>
            </fieldset>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="modal = null">Cerrar</button><button v-if="error && !pending" type="button" class="button secondary" :disabled="busy" @click="discardAndRefresh">Descartar cambios y actualizar</button><button type="submit" :form="formId" class="button primary" :disabled="busy || !manage">{{ busy ? 'Guardando…' : pending ? 'Reintentar cambios' : modal === 'update' ? 'Guardar ficha' : status === 'archived' ? 'Confirmar archivo' : 'Confirmar reactivación' }}</button></template>
    </AppDialog>
</template>
<style scoped>
.customer-profile { --profile-accent:#6941d9; padding:0; min-width:0; overflow:hidden; border:1px solid #e7e3f1; border-radius:22px; box-shadow:0 8px 30px #29204606; container:customer-profile / inline-size; }
.profile-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 24px; border-bottom:1px solid #efedf5; }
.profile-toolbar h2 { font-size:14px; font-weight:650; letter-spacing:0; color:#686579; }
.profile-refresh { display:flex; align-items:center; justify-content:center; width:48px; height:48px; border:1px solid #ebe7f3; border-radius:12px; background:white; color:#777086; flex-shrink:0; }
.profile-refresh:hover { color:var(--profile-accent); background:#f7f4ff; }
.profile-messages { padding:16px 24px 0; }
.profile-hero { display:flex; align-items:center; gap:18px; padding:26px 24px; background:linear-gradient(115deg,#faf8ff,white 75%); }
.profile-avatar { display:flex; align-items:center; justify-content:center; width:76px; height:76px; flex-shrink:0; border:4px solid white; border-radius:24px; background:linear-gradient(145deg,#e4d9ff,#f3eeff); color:#6941ce; font-size:26px; font-weight:750; letter-spacing:-1px; box-shadow:0 3px 12px #53349c0b; }
.profile-identity { min-width:0; flex:1; }
.profile-eyebrow { font-size:10px; letter-spacing:1.6px; font-weight:700; color:#756b84; }
.profile-identity h3 { font-size:28px; line-height:1.2; margin:4px 0 10px; color:#252038; font-weight:750; overflow-wrap:anywhere; }
.profile-status { display:inline-flex; align-items:center; gap:6px; border:1px solid #cfebdf; background:#f0faf5; padding:3px 9px; border-radius:20px; font-size:12px; font-weight:600; color:#27734e; }
.profile-status i { width:6px; height:6px; border-radius:50%; background:#3e9b6b; }
.profile-status.is-archived { color:#7b6570; background:#f5f1f3; border-color:#e6dde2; }
.profile-status.is-archived i { background:#9b8490; }
.profile-edit { flex-shrink:0; border-radius:12px; font-size:14px; padding:12px 16px; background:var(--profile-accent); box-shadow:0 3px 7px #6941d91a; }
.profile-edit:hover { background:#5632c0; }
.profile-body { padding:0 24px 24px; display:grid; gap:24px; }
.profile-section-heading { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.profile-section-heading h4 { margin:0; font-size:14px; font-weight:650; color:#393449; }
.profile-section-heading > span { color:#766c83; font-size:12px; }
.profile-activity.customer-summary { margin:0; padding:0; border:0; }
.profile-metrics { display:grid; grid-template-columns:minmax(0,1.45fr) repeat(2,minmax(0,1fr)); gap:10px; }
.profile-metric { min-width:0; display:flex; flex-direction:column; justify-content:space-between; gap:12px; padding:16px; background:#faf9fc; border:1px solid #eeebf3; border-radius:14px; }
.profile-metric > span { color:#777081; font-size:12px; line-height:1.4; }
.profile-metric > strong { font-size:28px; line-height:1.1; letter-spacing:-.8px; color:#302b3f; font-weight:720; font-variant-numeric:tabular-nums; }
.balance-metric { background:#fff9ee; border-color:#f2e7cf; }
.balance-metric > span { color:#90713f; }
.profile-metric > .profile-money { font-size:clamp(20px,4cqi,30px); white-space:nowrap; color:#815b24; }
.metric-wide { grid-column:1 / -1; }
.metric-wide > .profile-money { font-size:clamp(17px,4cqi,30px); }
.profile-contact-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:0; }
.contact-item { display:flex; align-items:flex-start; gap:12px; min-width:0; padding:14px; border:1px solid #edeaf2; border-radius:14px; }
.contact-item > div { min-width:0; }
.contact-icon { display:flex; align-items:center; justify-content:center; background:#f5f2fb; color:#8a77ae; width:36px; height:36px; border-radius:10px; flex-shrink:0; }
.contact-item dt { color:#776d84; font-size:12px; margin-bottom:4px; }
.contact-item dd { margin:0; color:#3e384e; font-size:14px; line-height:1.55; overflow-wrap:anywhere; }
.contact-address { grid-column:1 / -1; }
.profile-notes { display:flex; gap:12px; padding:16px; background:#f8f7fb; border:1px solid #efedf5; border-radius:14px; }
.profile-notes > div { min-width:0; }
.notes-icon { color:#9a8aaf; flex-shrink:0; padding-top:1px; }
.profile-notes h4 { margin:0 0 6px; color:#5e536e; font-size:13px; font-weight:650; }
.customer-notes { margin:0; padding:0; border:0; color:#70667e; font-size:14px; line-height:1.65; white-space:pre-wrap; overflow-wrap:anywhere; }
.customer-notes.no-notes { color:#776a84; }
.profile-section-heading .profile-order-count { display:flex; align-items:center; justify-content:center; min-width:24px; height:24px; padding:0 6px; border-radius:7px; background:#f0ebfb; color:#7856b6; font-weight:650; }
.profile-order { width:100%; display:flex; align-items:center; gap:12px; text-align:left; border:1px solid #ede9f4; border-radius:13px; background:white; padding:13px 14px; color:#80718e; margin-top:8px; min-height:64px; }
.profile-order:hover { background:#faf8ff; border-color:#d8caef; }
.order-icon { display:flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:11px; background:#f4efff; color:#9679bf; flex-shrink:0; }
.order-description { min-width:0; display:grid; gap:3px; flex:1; }
.order-description strong { color:#4a3f5e; font-size:14px; font-weight:650; overflow-wrap:anywhere; }
.order-description small { color:#7a6d87; font-size:12px; }
.order-total { min-width:0; display:grid; gap:3px; text-align:right; }
.order-total small { color:#7a6d87; font-size:11px; }
.order-total strong { color:#665674; font-size:14px; font-weight:650; overflow-wrap:anywhere; }
.profile-order > svg { flex-shrink:0; }
.profile-footer { display:flex; align-items:center; justify-content:flex-end; border-top:1px solid #efedf5; padding:10px 24px; }
.profile-archive { display:inline-flex; align-items:center; gap:8px; min-height:48px; padding:10px 12px; border:0; border-radius:10px; background:transparent; color:#86788c; font-size:13px; font-weight:550; }
.profile-archive:hover { background:#f6f2f5; color:#825b71; }
.profile-empty { min-height:360px; padding:40px 24px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
.profile-empty > span { display:flex; align-items:center; justify-content:center; width:80px; height:80px; border-radius:24px; background:#f4efff; color:#a38ac9; margin-bottom:20px; }
.profile-empty h3 { font-size:20px; color:#524560; }
.profile-empty p { font-size:14px; color:#7c6e88; margin-top:8px; max-width:240px; }
@container customer-profile (max-width:560px) {
    .profile-hero { flex-wrap:wrap; gap:14px; padding:22px 20px; }
    .profile-avatar { width:64px; height:64px; border-radius:21px; font-size:23px; }
    .profile-identity h3 { font-size:24px; }
    .profile-edit { margin-left:auto; }
    .profile-body { padding:0 20px 20px; gap:20px; }
    .profile-metric { gap:8px; padding:14px; }
}
@container customer-profile (max-width:460px) {
    .profile-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .balance-metric { grid-column:1 / -1; }
    .profile-contact-grid { grid-template-columns:minmax(0,1fr); }
}
@container customer-profile (max-width:360px) {
    .profile-hero { padding:20px 16px; }
    .profile-edit { width:100%; }
    .profile-body { padding:0 16px 20px; }
    .profile-toolbar { padding:12px 16px; }
    .profile-order { flex-wrap:wrap; }
    .order-total { flex-basis:calc(100% - 64px); margin-left:52px; text-align:left; }
}
</style>
