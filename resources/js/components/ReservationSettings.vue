<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';
import { api, errorMessage } from '../api';

const enabled = ref(false);
const hours = ref('24');
const previous = ref<number | null>(null);
const requireDeposit = ref(true);
const depositMode = ref<'fixed' | 'percent'>('fixed');
const depositValue = ref('10.00');
const depositInstructions = ref('');
const loading = ref(true);
const busy = ref(false);
const loaded = ref(false);
const error = ref('');
const notice = ref('');
const controller = new AbortController();

async function load() {
    loading.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.get('/business/reservations/settings', { signal: controller.signal });
        previous.value = data.data.hours;
        enabled.value = previous.value !== null;
        hours.value = String(previous.value || 24);
        const dep = data.data.deposit || {};
        requireDeposit.value = dep.require_deposit_for_new !== undefined ? Boolean(dep.require_deposit_for_new) : true;
        depositMode.value = dep.deposit_mode === 'percent' ? 'percent' : 'fixed';
        depositValue.value = String(dep.deposit_value ?? '10.00');
        depositInstructions.value = String(dep.deposit_instructions ?? '');
        loaded.value = true;
    } catch (e) {
        if (!axios.isCancel(e)) error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (busy.value || !loaded.value) return;
    if (enabled.value && (!/^\d+$/.test(hours.value) || Number(hours.value) < 1 || Number(hours.value) > 8760)) {
        error.value = 'Indica un plazo de 1 a 8760 horas.';
        return;
    }
    const parsedDep = Number(depositValue.value);
    if (requireDeposit.value && (!Number.isFinite(parsedDep) || parsedDep < 0 || (depositMode.value === 'percent' && parsedDep > 100))) {
        error.value = depositMode.value === 'percent' ? 'Indica un porcentaje de depósito entre 0 y 100.' : 'Indica un monto de depósito válido.';
        return;
    }
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.patch(
            '/business/reservations/settings',
            {
                hours: enabled.value ? Number(hours.value) : null,
                previous_hours: previous.value,
                require_deposit_for_new: requireDeposit.value,
                deposit_mode: depositMode.value,
                deposit_value: parsedDep.toFixed(2),
                deposit_instructions: depositInstructions.value.trim(),
            },
            { signal: controller.signal },
        );
        previous.value = data.data.hours;
        const dep = data.data.deposit || {};
        requireDeposit.value = Boolean(dep.require_deposit_for_new);
        depositMode.value = dep.deposit_mode === 'percent' ? 'percent' : 'fixed';
        depositValue.value = String(dep.deposit_value ?? '10.00');
        depositInstructions.value = String(dep.deposit_instructions ?? '');
        notice.value = 'Configuración de reservas y depósito guardada.';
    } catch (e) {
        if (!axios.isCancel(e)) error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

onMounted(load);
onUnmounted(() => controller.abort());
</script>
<template>
    <section class="panel reservation-settings" aria-label="Plazo y depósito de reservas">
        <h2>Plazo y depósito de reservas</h2>
        <p class="muted">Controla cuánto duran los apartados sin abono y si las clientas nuevas necesitan confirmar depósito antes de descontar inventario.</p>
        <p v-if="loading" role="status">Cargando configuración…</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <form v-if="loaded" class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="loading || busy">
                <div class="deposit-box">
                    <h3>Depósito para clientas nuevas (WhatsApp y catálogo)</h3>
                    <p class="muted">Mientras el depósito no sea confirmado, la solicitud queda pendiente y la mercancía sigue disponible para otras compradoras. Puedes eximir a clientas de confianza desde su ficha en <strong>Clientes</strong>.</p>
                    <label class="check-label">
                        <input v-model="requireDeposit" type="checkbox">
                        Requerir depósito confirmado antes de apartar a clientas nuevas
                    </label>
                    <div v-if="requireDeposit" class="deposit-grid">
                        <label>
                            Tipo de depósito
                            <select v-model="depositMode">
                                <option value="fixed">Monto fijo por solicitud (US$)</option>
                                <option value="percent">Porcentaje del precio (%)</option>
                            </select>
                        </label>
                        <label>
                            {{ depositMode === 'percent' ? 'Porcentaje (%)' : 'Monto del depósito (US$)' }}
                            <input
                                v-model="depositValue"
                                type="number"
                                min="0"
                                :max="depositMode === 'percent' ? 100 : 100000"
                                step="0.01"
                                inputmode="decimal"
                                required
                            >
                        </label>
                    </div>
                    <label v-if="requireDeposit">
                        Instrucciones de pago (Zelle, cuenta bancaria, CashApp, etc.)
                        <textarea
                            v-model="depositInstructions"
                            rows="3"
                            maxlength="600"
                            placeholder="Ej. Zelle a keylashop@correo.com o transferencia. Envía tu comprobante aquí para confirmar tu apartado."
                        ></textarea>
                    </label>
                </div>

                <hr class="settings-divider">

                <label class="check-label"><input v-model="enabled" type="checkbox">Limitar duración de nuevas reservas</label>
                <label v-if="enabled">Plazo en horas<input v-model="hours" type="number" min="1" max="8760" step="1" inputmode="numeric" required></label>
                <p class="muted">Al vencer el plazo, se cancelan únicamente los apartados sin abonos, factura ni entregas y se libera su inventario.</p>
            </fieldset>
            <div class="actions">
                <button type="button" class="button secondary" :disabled="loading || busy" @click="load">Actualizar</button>
                <button type="submit" class="button primary" :disabled="loading || busy">{{ busy ? 'Guardando…' : 'Guardar configuración' }}</button>
            </div>
        </form>
        <button v-else-if="!loading" type="button" class="button secondary" @click="load">Reintentar carga</button>
    </section>
</template>
<style scoped>
.reservation-settings { margin-top:24px; }
.button { min-height:48px; }
.check-label { min-height:44px; display:flex; align-items:center; gap:10px; }
.deposit-box { display:grid; gap:12px; padding:16px; border:1px solid #e7e1f7; border-radius:14px; background:#faf8ff; }
.deposit-box h3 { margin:0; font-size:15px; color:#2b2342; }
.deposit-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.settings-divider { border:0; border-top:1px solid #ece8f5; margin:6px 0; }
</style>
