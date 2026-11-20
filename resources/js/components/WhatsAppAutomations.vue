<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store } from '../types';

type RuleConfig = {
    enabled: boolean;
    template: string;
    hours_before?: number;
    threshold?: number;
};

type LogEntry = {
    id: number;
    event_type: string;
    destination: string;
    template_name: string;
    status: string;
    error_code: string | null;
    created_at: string;
};

const props = defineProps<{ store?: Store }>();

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const error = ref('');
const notice = ref('');
const whatsappReady = ref(false);
const logs = ref<LogEntry[]>([]);

const form = reactive<{
    owner_phone: string;
    daily_summary_time: string;
    rules: Record<string, RuleConfig>;
}>({
    owner_phone: '',
    daily_summary_time: '21:00',
    rules: {
        payment_receipt: { enabled: false, template: 'recibo_abono' },
        order_dispatched: { enabled: false, template: 'pedido_despachado' },
        receivables_reminder: { enabled: false, template: 'recordatorio_saldo' },
        expiry_warning: { enabled: false, template: 'apartado_por_vencer', hours_before: 24 },
        weekly_close_pdf: { enabled: false, template: 'cuenta_semanal_pdf' },
        catalog_link: { enabled: false, template: 'enlace_catalogo_cliente' },
        owner_daily_summary: { enabled: false, template: 'resumen_diario_dueno' },
        low_stock_alert: { enabled: false, template: 'alerta_stock_bajo', threshold: 2 },
    },
});

const catalog = [
    { key: 'payment_receipt', title: 'Recibo automático de abono / pago', desc: 'Envía confirmación al cliente cada vez que registras un abono.' },
    { key: 'order_dispatched', title: 'Aviso de pedido listo / despachado', desc: 'Notifica al cliente al marcar un pedido o apartado como entregado.' },
    { key: 'receivables_reminder', title: 'Recordatorio de saldo pendiente (CxC)', desc: 'Habilita envío con plantilla oficial desde Cuentas por cobrar.' },
    { key: 'expiry_warning', title: 'Aviso de apartado por vencer', desc: 'Avisa automáticamente antes de que expire un apartado abierto.', extra: 'hours_before' as const },
    { key: 'weekly_close_pdf', title: 'Cierre de cuenta semanal (PDF)', desc: 'Envía el PDF consolidado al cerrar la cuenta semanal del cliente.' },
    { key: 'catalog_link', title: 'Enlace de Vista del Cliente (/s/...)', desc: 'Envía el catálogo personalizado directo al WhatsApp del cliente.' },
    { key: 'owner_daily_summary', title: 'Resumen diario para el dueño', desc: 'Envía ventas, cobros, apartados y CxC al teléfono del dueño.' },
    { key: 'low_stock_alert', title: 'Alerta de stock bajo para el dueño', desc: 'Avisa al dueño cuando una variante baja del umbral configurado.', extra: 'threshold' as const },
];

const root = computed(() => `/business/stores/${props.store?.id}/whatsapp`);

async function load() {
    if (!props.store?.id) return;
    loading.value = true;
    error.value = '';
    try {
        const res = (await api.get(`${root.value}/automations`)).data.data;
        whatsappReady.value = !!res.whatsapp?.ready;
        form.owner_phone = res.owner_phone || '';
        form.daily_summary_time = res.daily_summary_time || '21:00';
        for (const key of Object.keys(form.rules)) {
            if (res.rules?.[key]) {
                form.rules[key] = { ...form.rules[key], ...res.rules[key] };
            }
        }
        logs.value = res.logs || [];
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (saving.value || !props.store?.id) return;
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = (await api.patch(`${root.value}/automations`, {
            owner_phone: form.owner_phone.trim() || null,
            daily_summary_time: form.daily_summary_time,
            rules: form.rules,
        })).data.data;
        logs.value = res.logs || [];
        notice.value = `Automatizaciones de WhatsApp guardadas para ${props.store.name}.`;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function testSummary() {
    if (testing.value || !props.store?.id) return;
    testing.value = true;
    error.value = '';
    notice.value = '';
    try {
        await api.post(`${root.value}/owner-summary/test`);
        notice.value = 'Resumen diario de prueba enviado al WhatsApp del dueño.';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        testing.value = false;
    }
}

watch(() => props.store?.id, () => load(), { immediate: true });
</script>

<template>
    <section v-if="store" class="panel wa-auto-panel" aria-label="Automatizaciones de WhatsApp por tienda">
        <div class="panel-heading">
            <div>
                <h2>Automatizaciones WhatsApp · {{ store.name }}</h2>
                <p class="muted">Activa cada plantilla una vez Meta la apruebe. Todas las reglas están aisladas para esta tienda.</p>
            </div>
            <span class="badge" :class="{ inactive: !whatsappReady }">
                {{ whatsappReady ? 'WhatsApp API lista ✓' : 'WhatsApp API pendiente' }}
            </span>
        </div>

        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="loading" class="muted" role="status">Cargando automatizaciones…</p>

        <form v-else class="form-stack" @submit.prevent="save">
            <div class="wa-auto-owner-grid">
                <label>
                    WhatsApp del dueño (para resumen diario y alertas de stock)
                    <input v-model="form.owner_phone" type="tel" placeholder="+18092374444" maxlength="40" />
                </label>
                <label>
                    Hora del resumen diario
                    <input v-model="form.daily_summary_time" type="time" />
                </label>
            </div>

            <div class="wa-auto-grid">
                <article v-for="item in catalog" :key="item.key" class="wa-auto-card" :class="{ active: form.rules[item.key].enabled }">
                    <label class="wa-auto-switch">
                        <div>
                            <strong>{{ item.title }}</strong>
                            <small class="muted">{{ item.desc }}</small>
                        </div>
                        <input v-model="form.rules[item.key].enabled" type="checkbox" />
                    </label>
                    <div class="wa-auto-fields">
                        <label>
                            Plantilla Meta
                            <input v-model="form.rules[item.key].template" maxlength="120" pattern="[a-z0-9_]+" required />
                        </label>
                        <label v-if="item.extra === 'hours_before'">
                            Horas antes
                            <input v-model.number="form.rules[item.key].hours_before" type="number" min="1" max="168" />
                        </label>
                        <label v-if="item.extra === 'threshold'">
                            Avisar si stock ≤
                            <input v-model.number="form.rules[item.key].threshold" type="number" min="0" max="1000" />
                        </label>
                    </div>
                </article>
            </div>

            <div class="actions" style="flex-wrap: wrap;">
                <button type="submit" class="button primary" :disabled="saving">
                    {{ saving ? 'Guardando…' : 'Guardar automatizaciones' }}
                </button>
                <button
                    type="button"
                    class="button secondary"
                    :disabled="testing || !whatsappReady || !form.owner_phone"
                    @click="testSummary"
                >
                    {{ testing ? 'Enviando…' : 'Probar resumen diario ahora' }}
                </button>
            </div>
        </form>

        <div v-if="logs.length" class="wa-auto-logs">
            <h3>Últimas notificaciones enviadas</h3>
            <div class="wa-log-list">
                <div v-for="log in logs.slice(0, 10)" :key="log.id" class="wa-log-row">
                    <span><strong>{{ log.template_name }}</strong> → {{ log.destination }}</span>
                    <span class="badge" :class="{ inactive: log.status !== 'sent' }">{{ log.status }}{{ log.error_code ? ` (${log.error_code})` : '' }}</span>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.wa-auto-panel { display: grid; gap: 14px; }
.wa-auto-owner-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(150px, 0.6fr); gap: 12px; }
.wa-auto-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.wa-auto-card { border: 1px solid #e4e0f2; border-radius: 13px; padding: 12px; background: #fcfbfe; display: grid; gap: 10px; }
.wa-auto-card.active { border-color: #6435ed; background: #faf8ff; }
.wa-auto-switch { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; cursor: pointer; }
.wa-auto-switch strong { display: block; font-size: 13.5px; }
.wa-auto-switch small { display: block; font-size: 11.5px; margin-top: 2px; }
.wa-auto-switch input[type="checkbox"] { width: 20px; height: 20px; accent-color: #6435ed; flex-shrink: 0; margin-top: 2px; }
.wa-auto-fields { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
.wa-auto-fields label { font-size: 12px; }
.wa-auto-logs { border-top: 1px solid #e6e3f3; padding-top: 12px; display: grid; gap: 8px; }
.wa-auto-logs h3 { font-size: 14px; margin: 0; }
.wa-log-list { display: grid; gap: 6px; font-size: 12.5px; }
.wa-log-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; padding: 6px 8px; border-radius: 8px; background: #f8f7fc; }
@media (max-width: 767px) {
    .wa-auto-owner-grid, .wa-auto-grid, .wa-auto-fields { grid-template-columns: 1fr; }
}
</style>
