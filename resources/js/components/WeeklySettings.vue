<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';
import { api, errorMessage } from '../api';

const weekdays = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const current = ref(4); const previous = ref<number | null>(null);
const loading = ref(true); const busy = ref(false); const error = ref(''); const notice = ref('');
const controller = new AbortController();
async function load() {
    loading.value = true; error.value = ''; notice.value = '';
    try { const { data } = await api.get('/business/weekly/settings', { signal: controller.signal }); current.value = previous.value = data.data.close_weekday; }
    catch (e) { if (!axios.isCancel(e)) error.value = errorMessage(e); }
    finally { loading.value = false; }
}
async function save() {
    if (busy.value || previous.value === null) return;
    busy.value = true; error.value = ''; notice.value = '';
    try { const { data } = await api.patch('/business/weekly/settings', { close_weekday: current.value, previous_weekday: previous.value }, { signal: controller.signal }); current.value = previous.value = data.data.close_weekday; notice.value = 'Día de corte guardado.'; }
    catch (e) { if (!axios.isCancel(e)) error.value = errorMessage(e); }
    finally { busy.value = false; }
}
onMounted(load); onUnmounted(() => controller.abort());
</script>
<template>
    <section class="panel weekly-settings" aria-label="Corte semanal">
        <h2>Corte semanal</h2>
        <p class="muted">El día seleccionado termina el período sugerido de siete días. Las cuentas existentes conservan sus fechas y el cierre sigue siendo manual.</p>
        <p v-if="loading" role="status">Cargando día de corte…</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <form v-if="previous !== null" class="form-stack" @submit.prevent="save">
            <label>Día de corte<select v-model.number="current" :disabled="loading || busy"><option v-for="(day, index) in weekdays" :key="day" :value="index + 1">{{ day }}</option></select></label>
            <div class="actions"><button type="button" class="button secondary" :disabled="loading || busy" @click="load">Actualizar día de corte</button><button type="submit" class="button primary" :disabled="loading || busy">{{ busy ? 'Guardando…' : 'Guardar día de corte' }}</button></div>
        </form>
        <button v-else-if="!loading" type="button" class="button secondary" @click="load">Reintentar día de corte</button>
    </section>
</template>
<style scoped>.weekly-settings { margin-top: 24px; } .button { min-height: 48px; }</style>
