<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';
import { api, errorMessage } from '../api';

const enabled = ref(false); const hours = ref('24'); const previous = ref<number | null>(null);
const loading = ref(true); const busy = ref(false); const loaded = ref(false); const error = ref(''); const notice = ref('');
const controller = new AbortController();
async function load() {
    loading.value = true; error.value = ''; notice.value = '';
    try {
        const {data} = await api.get('/business/reservations/settings', {signal:controller.signal});
        previous.value = data.data.hours; enabled.value = previous.value !== null; hours.value = String(previous.value || 24); loaded.value = true;
    } catch (e) { if (!axios.isCancel(e)) error.value = errorMessage(e); }
    finally { loading.value = false; }
}
async function save() {
    if (busy.value || !loaded.value) return;
    if (enabled.value && (!/^\d+$/.test(hours.value) || Number(hours.value) < 1 || Number(hours.value) > 8760)) { error.value = 'Indica un plazo de 1 a 8760 horas.'; return; }
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const {data} = await api.patch('/business/reservations/settings', {hours:enabled.value ? Number(hours.value) : null, previous_hours:previous.value}, {signal:controller.signal});
        previous.value = data.data.hours; notice.value = 'Plazo de reservas guardado.';
    } catch (e) { if (!axios.isCancel(e)) error.value = errorMessage(e); }
    finally { busy.value = false; }
}
onMounted(load); onUnmounted(() => controller.abort());
</script>
<template>
    <section class="panel reservation-settings" aria-label="Plazo de reservas">
        <h2>Plazo de reservas</h2>
        <p class="muted">Al vencer, se cancelan únicamente los apartados sin abonos, factura ni entregas y se libera su inventario.</p>
        <p class="muted">Los cambios se aplican a nuevas reservas. Los plazos existentes se conservan, incluso al desactivar esta opción.</p>
        <p v-if="loading" role="status">Cargando plazo…</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <form v-if="loaded" class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="loading || busy">
                <label class="check-label"><input v-model="enabled" type="checkbox">Limitar duración de nuevas reservas</label>
                <label v-if="enabled">Plazo en horas<input v-model="hours" type="number" min="1" max="8760" step="1" inputmode="numeric" required></label>
            </fieldset>
            <div class="actions"><button type="button" class="button secondary" :disabled="loading || busy" @click="load">Actualizar plazo</button><button type="submit" class="button primary" :disabled="loading || busy">{{ busy ? 'Guardando…' : 'Guardar plazo de reservas' }}</button></div>
        </form>
        <button v-else-if="!loading" type="button" class="button secondary" @click="load">Reintentar carga del plazo</button>
    </section>
</template>
<style scoped>
.reservation-settings { margin-top:24px; }
.button { min-height:48px; }
.check-label { min-height:48px; }
</style>
