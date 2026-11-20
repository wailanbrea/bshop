<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import type { Store } from '../types';
import AppDialog from './AppDialog.vue';

type Recipient = {
    customer_id: number;
    customer_name: string;
    phone: string | null;
    phone_valid: boolean;
    currency: string;
    orders_count: number;
    order_refs: string[];
    invoice_ids: number[];
    invoice_numbers: string[];
    has_invoice: boolean;
    grand_total: string;
    paid_total: string;
    pending_total: string;
    wa_me_url: string;
    preview_message: string;
};

type Batch = {
    id: number;
    trigger_type: 'manual' | 'scheduled';
    channel: 'meta_api' | 'wa_me';
    total_targets: number;
    sent_count: number;
    failed_count: number;
    skipped_count: number;
    created_at: string;
};

const props = defineProps<{
    open: boolean;
    store?: Store;
    canManage: boolean;
    opener: HTMLElement | null;
}>();
const emit = defineEmits<{ close: [] }>();

const loading = ref(false);
const saving = ref(false);
const sending = ref(false);
const error = ref('');
const notice = ref('');

const whatsappReady = ref(false);
const whatsappReason = ref('');
const recipients = ref<Recipient[]>([]);
const batches = ref<Batch[]>([]);
const selectedIds = ref<number[]>([]);
const nextRunAt = ref<string | null>(null);
const timezone = ref('America/Santo_Domingo');

const form = reactive({
    enabled: false,
    weekday: 4,
    time: '18:00',
    scope: 'open_and_invoiced_pending',
    message: '',
});

const weekdays = [
    { value: 0, label: 'Todos los días' },
    { value: 1, label: 'Lunes' },
    { value: 2, label: 'Martes' },
    { value: 3, label: 'Miércoles' },
    { value: 4, label: 'Jueves' },
    { value: 5, label: 'Viernes' },
    { value: 6, label: 'Sábado' },
    { value: 7, label: 'Domingo' },
];

const scopes = [
    { value: 'open_and_invoiced_pending', label: 'Apartados abiertos o facturados con saldo pendiente' },
    { value: 'invoiced_pending_only', label: 'Solo apartados facturados con saldo pendiente' },
    { value: 'all_open_baskets', label: 'Todos los apartados abiertos' },
];

const root = computed(() => `/business/stores/${props.store?.id}/baskets/whatsapp-dispatch`);
const selectedCount = computed(() => selectedIds.value.length);
const validRecipientsCount = computed(() => recipients.value.filter(r => r.phone_valid).length);

const scheduleLabel = computed(() => {
    if (!form.enabled) return 'Envío manual';
    const day = weekdays.find(w => w.value === form.weekday)?.label || 'Semanal';
    return `Automático: ${day} · ${form.time}`;
});

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat('es-DO', {
            weekday: 'short',
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone.value || 'UTC',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function toggleCustomer(id: number) {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter(x => x !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
}

async function load() {
    if (!props.store?.id) return;
    loading.value = true;
    error.value = '';
    try {
        const res = (await api.get(root.value)).data.data;
        whatsappReady.value = !!res.whatsapp?.ready;
        whatsappReason.value = res.whatsapp?.reason || '';
        recipients.value = res.recipients || [];
        batches.value = res.batches || [];
        Object.assign(form, {
            enabled: !!res.schedule?.enabled,
            weekday: Number(res.schedule?.weekday ?? 4),
            time: res.schedule?.time || '18:00',
            scope: res.schedule?.scope || 'open_and_invoiced_pending',
            message: res.schedule?.message || '',
        });
        nextRunAt.value = res.schedule?.next_run_at || null;
        timezone.value = res.schedule?.timezone || 'America/Santo_Domingo';
        selectedIds.value = recipients.value.filter(r => r.phone_valid).map(r => r.customer_id);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function saveSchedule() {
    if (saving.value || !props.store?.id) return;
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = (await api.patch(`${root.value}/schedule`, {
            enabled: form.enabled,
            weekday: Number(form.weekday),
            time: form.time,
            scope: form.scope,
            message: form.message,
        })).data.data.schedule;
        nextRunAt.value = res.next_run_at || null;
        notice.value = 'Programación guardada correctamente.';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function sendBulkNow() {
    if (sending.value || !selectedIds.value.length || !props.store?.id) return;
    sending.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = (await api.post(`${root.value}/send`, {
            customer_ids: selectedIds.value,
        })).data.data.batch as Batch;
        notice.value = `Lote completado: ${res.sent_count} enviado(s), ${res.skipped_count} omitido(s), ${res.failed_count} fallido(s).`;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        sending.value = false;
    }
}

async function openWaMe(recipient: Recipient) {
    window.open(recipient.wa_me_url, '_blank', 'noopener,noreferrer');
    if (!props.store?.id) return;
    try {
        await api.post(`${root.value}/log-manual`, {
            customer_id: recipient.customer_id,
            customer_name: recipient.customer_name,
        });
        await load();
    } catch {
        /* Non-blocking log */
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        notice.value = '';
        error.value = '';
        load();
    }
});
</script>

<template>
    <AppDialog
        :open="open"
        title="Envío de Facturas de Apartados por WhatsApp"
        :busy="saving || sending"
        :wide="true"
        :return-focus="opener"
        @close="emit('close')"
    >
        <div class="bwa-shell">
            <div class="bwa-top">
                <span class="muted">{{ store?.name || 'Sucursal' }} · Credenciales y programación aisladas por tienda</span>
                <span class="bwa-pill" :class="form.enabled ? 'green' : 'neutral'">{{ scheduleLabel }}</span>
            </div>

            <p v-if="error" class="notice error" role="alert">{{ error }}</p>
            <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
            <p v-if="loading" class="muted" role="status">Cargando clientes y programación…</p>

            <div v-else class="bwa-grid">
                <!-- Programación automática -->
                <section class="bwa-card">
                    <div class="bwa-card-head">
                        <h3>Programación automática</h3>
                        <small class="muted">Se ejecuta en segundo plano cada semana según la configuración de esta tienda.</small>
                    </div>

                    <label class="bwa-toggle">
                        <div>
                            <strong>Activar envío automático</strong>
                            <small class="muted">Requiere WhatsApp Business API configurada en Configuración → WhatsApp</small>
                        </div>
                        <input v-model="form.enabled" type="checkbox" :disabled="!canManage || saving" />
                    </label>

                    <div class="bwa-row-2">
                        <label>
                            Día de envío
                            <select v-model.number="form.weekday" :disabled="!canManage || saving">
                                <option v-for="w in weekdays" :key="w.value" :value="w.value">{{ w.label }}</option>
                            </select>
                        </label>
                        <label>
                            Hora ({{ timezone }})
                            <input v-model="form.time" type="time" :disabled="!canManage || saving" />
                        </label>
                    </div>

                    <label>
                        ¿Qué apartados incluir?
                        <select v-model="form.scope" :disabled="!canManage || saving">
                            <option v-for="s in scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </label>

                    <label>
                        Mensaje para WhatsApp manual (<code>{cliente}</code>, <code>{tienda}</code>, <code>{factura}</code>, <code>{total}</code>, <code>{pendiente}</code>)
                        <textarea v-model="form.message" rows="3" maxlength="1000" :disabled="!canManage || saving"></textarea>
                    </label>

                    <div class="bwa-status-bar" :class="whatsappReady ? 'ok' : 'warn'">
                        <div>
                            <strong>{{ whatsappReady ? 'WhatsApp API conectada ✓' : 'WhatsApp API pendiente de configurar' }}</strong>
                            <small v-if="form.enabled && nextRunAt">Próximo envío: {{ formatDateTime(nextRunAt) }}</small>
                            <small v-else>{{ whatsappReason || 'Puedes usar el botón wa.me manual mientras conectas Meta API.' }}</small>
                        </div>
                    </div>

                    <button type="button" class="button primary full" :disabled="!canManage || saving" @click="saveSchedule">
                        {{ saving ? 'Guardando…' : 'Guardar programación' }}
                    </button>
                </section>

                <!-- Envío manual ahora -->
                <section class="bwa-card">
                    <div class="bwa-card-head-row">
                        <div>
                            <h3>Envío manual ahora</h3>
                            <small class="muted">Revisa los clientes con apartados pendientes y envía con 1 clic.</small>
                        </div>
                        <span class="bwa-pill purple">{{ validRecipientsCount }} listos</span>
                    </div>

                    <div v-if="!recipients.length" class="empty">
                        No hay clientes con apartados pendientes para este filtro.
                    </div>

                    <div v-else class="bwa-recipients">
                        <div
                            v-for="r in recipients"
                            :key="r.customer_id"
                            class="bwa-recipient"
                            :class="{ disabled: !r.phone_valid }"
                        >
                            <input
                                type="checkbox"
                                :checked="selectedIds.includes(r.customer_id)"
                                :disabled="!r.phone_valid || sending"
                                :aria-label="`Seleccionar a ${r.customer_name}`"
                                @change="toggleCustomer(r.customer_id)"
                            />
                            <div class="bwa-rec-info">
                                <strong>{{ r.customer_name }}</strong>
                                <small>
                                    {{ r.phone || 'Sin teléfono internacional' }} · {{ r.orders_count }} {{ r.orders_count === 1 ? 'apartado' : 'apartados' }}
                                    ({{ r.order_refs.slice(0, 3).join(', ') }})
                                </small>
                                <span v-if="r.has_invoice" class="bwa-tag ok">Factura: {{ r.invoice_numbers.join(', ') }}</span>
                                <span v-else class="bwa-tag neutral">Apartado abierto (sin factura PDF aún)</span>
                            </div>
                            <div class="bwa-rec-right">
                                <b class="bwa-money">{{ amount(r.pending_total, r.currency) }}</b>
                                <span class="bwa-phone-state" :class="r.phone_valid ? 'ok' : 'warn'">
                                    {{ r.phone_valid ? 'WhatsApp válido ✓' : 'Falta +código ⚠' }}
                                </span>
                                <button
                                    type="button"
                                    class="button secondary bwa-wame-btn"
                                    :disabled="!r.phone"
                                    @click="openWaMe(r)"
                                >
                                    Abrir wa.me
                                </button>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="button bwa-send-btn full"
                        :disabled="!canManage || sending || !selectedCount || !whatsappReady"
                        @click="sendBulkNow"
                    >
                        {{ sending ? 'Enviando facturas…' : `Enviar facturas PDF ahora (${selectedCount} ${selectedCount === 1 ? 'cliente' : 'clientes'})` }}
                    </button>
                    <p v-if="!whatsappReady && recipients.length" class="muted bwa-hint">
                        El envío masivo automático con PDF se habilita al completar <strong>Configuración → WhatsApp</strong>. Mientras tanto, usa <strong>Abrir wa.me</strong> en cada cliente.
                    </p>

                    <div v-if="batches.length" class="bwa-history">
                        <strong>Últimos envíos</strong>
                        <div class="bwa-history-list">
                            <div v-for="b in batches" :key="b.id" class="bwa-history-row">
                                <span>{{ formatDateTime(b.created_at) }}</span>
                                <span>{{ b.trigger_type === 'scheduled' ? 'Automático' : (b.channel === 'wa_me' ? 'Manual (wa.me)' : 'Manual (API)') }}</span>
                                <strong :class="b.failed_count > 0 ? 'warn' : 'ok'">
                                    {{ b.sent_count }}/{{ b.total_targets }} enviados
                                </strong>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <template #footer>
            <button type="button" class="button secondary" :disabled="saving || sending" @click="emit('close')">Cerrar</button>
        </template>
    </AppDialog>
</template>

<style scoped>
.bwa-shell { display: grid; gap: 12px; }
.bwa-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.bwa-pill { display: inline-flex; align-items: center; padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
.bwa-pill.green { background: #dcfce7; color: #166534; }
.bwa-pill.purple { background: #ede9fe; color: #5b21b6; }
.bwa-pill.neutral { background: #f1f5f9; color: #475569; }
.bwa-grid { display: grid; grid-template-columns: 1fr 1.15fr; gap: 14px; align-items: start; }
.bwa-card { background: #fff; border: 1px solid #e3e0f2; border-radius: 14px; padding: 14px; display: grid; gap: 12px; }
.bwa-card h3 { font-size: 15px; font-weight: 750; margin: 0; }
.bwa-card-head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.bwa-toggle { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 11px; background: #faf8ff; border: 1px solid #e3dcfb; cursor: pointer; }
.bwa-toggle strong { display: block; font-size: 13.5px; }
.bwa-toggle input[type="checkbox"] { width: 20px; height: 20px; accent-color: #6435ed; flex-shrink: 0; }
.bwa-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bwa-status-bar { padding: 10px 12px; border-radius: 10px; font-size: 12.5px; }
.bwa-status-bar.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.bwa-status-bar.warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.bwa-status-bar strong, .bwa-status-bar small { display: block; }
.bwa-recipients { display: grid; gap: 8px; max-height: 320px; overflow-y: auto; }
.bwa-recipient { display: grid; grid-template-columns: 20px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px; border-radius: 11px; border: 1px solid #e6e3f3; background: #fcfbfe; }
.bwa-recipient.disabled { opacity: 0.72; }
.bwa-recipient input[type="checkbox"] { width: 18px; height: 18px; accent-color: #6435ed; }
.bwa-rec-info { min-width: 0; display: grid; gap: 2px; }
.bwa-rec-info strong { font-size: 13.5px; overflow-wrap: anywhere; }
.bwa-rec-info small { font-size: 11.5px; color: #63607d; overflow-wrap: anywhere; }
.bwa-tag { display: inline-block; justify-self: start; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 6px; margin-top: 2px; }
.bwa-tag.ok { background: #dcfce7; color: #166534; }
.bwa-tag.neutral { background: #f1f5f9; color: #475569; }
.bwa-rec-right { display: grid; gap: 4px; justify-items: end; text-align: right; }
.bwa-money { font-size: 14px; white-space: nowrap; color: #191731; }
.bwa-phone-state { font-size: 11px; font-weight: 650; white-space: nowrap; }
.bwa-phone-state.ok, .ok { color: #15803d; }
.bwa-phone-state.warn, .warn { color: #b45309; }
.bwa-wame-btn { min-height: 32px; padding: 4px 10px; font-size: 12px; white-space: nowrap; }
.bwa-send-btn { background: #16a34a; color: #fff; border: 0; }
.bwa-send-btn:disabled { opacity: 0.5; }
.bwa-hint { font-size: 12px; margin: 0; }
.bwa-history { border-top: 1px solid #e6e3f3; padding-top: 10px; display: grid; gap: 6px; font-size: 12px; }
.bwa-history-list { display: grid; gap: 5px; }
.bwa-history-row { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 5px 0; border-bottom: 1px solid #f2f0f9; }
@media (max-width: 820px) {
    .bwa-grid { grid-template-columns: 1fr; }
    .bwa-row-2 { grid-template-columns: 1fr; }
    .bwa-recipient { grid-template-columns: 20px minmax(0, 1fr); }
    .bwa-rec-right { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; padding-top: 6px; border-top: 1px solid #eeeaf8; }
}
</style>
