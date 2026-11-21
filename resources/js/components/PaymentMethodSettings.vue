<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import {
    allPaymentMethods,
    applyStorePaymentMethods,
    loadStorePaymentMethods,
    svgDataUri,
    type PaymentMethodCategory,
    type PaymentMethodDef,
    type StorePaymentMethodRow,
} from '../lib/paymentMethods';
import type { Store } from '../types';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ store: Store | null }>();

const loading = ref(false);
const saving = ref(false);
const notice = ref('');
const error = ref('');
const search = ref('');
const category = ref<'all' | 'active' | PaymentMethodCategory>('all');
const selectedCodes = ref<string[]>([]);
const dirty = ref(false);

// Custom method modal state
const modalOpen = ref(false);
const editingId = ref<number | null>(null);
const customLabel = ref('');
const customBg = ref('#6435ed');
const customFg = ref('#ffffff');
const customSvg = ref('');
const customFileName = ref('');
const customSaving = ref(false);
const customError = ref('');

const CATEGORY_TABS: Array<{ id: 'all' | 'active' | PaymentMethodCategory; label: string }> = [
    { id: 'all', label: 'Todos' },
    { id: 'active', label: 'Activos' },
    { id: 'wallets', label: 'Billeteras / P2P' },
    { id: 'cards', label: 'Tarjetas / POS' },
    { id: 'bnpl', label: 'Cuotas (BNPL)' },
    { id: 'traditional', label: 'Tradicionales' },
    { id: 'crypto', label: 'Cripto' },
    { id: 'custom', label: 'Personalizados' },
];

const COLOR_PRESETS = [
    { bg: '#6435ed', fg: '#ffffff', name: 'Morado' },
    { bg: '#0f766e', fg: '#ffffff', name: 'Verde azulado' },
    { bg: '#1d4ed8', fg: '#ffffff', name: 'Azul' },
    { bg: '#dc2626', fg: '#ffffff', name: 'Rojo' },
    { bg: '#ea580c', fg: '#ffffff', name: 'Naranja' },
    { bg: '#111827', fg: '#ffffff', name: 'Negro' },
    { bg: '#fef3c7', fg: '#92400e', name: 'Ámbar suave' },
    { bg: '#dcfce7', fg: '#166534', name: 'Verde suave' },
];

function syncSelectionFromStore() {
    selectedCodes.value = allPaymentMethods.value.filter((m) => m.isEnabled).map((m) => m.code);
    dirty.value = false;
}

async function load() {
    if (!props.store?.id) return;
    loading.value = true;
    error.value = '';
    try {
        await loadStorePaymentMethods(props.store.id, true);
        syncSelectionFromStore();
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudieron cargar los métodos de pago.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.store?.id, () => {
    notice.value = '';
    error.value = '';
    load();
}, { immediate: true });

const selectedSet = computed(() => new Set(selectedCodes.value));

const filteredMethods = computed<PaymentMethodDef[]>(() => {
    const q = search.value.trim().toLowerCase();
    return allPaymentMethods.value.filter((item) => {
        if (category.value === 'active' && !selectedSet.value.has(item.code)) return false;
        if (category.value !== 'all' && category.value !== 'active' && item.category !== category.value) return false;
        if (!q) return true;
        return item.label.toLowerCase().includes(q) || item.code.toLowerCase().includes(q);
    });
});

function toggleMethod(code: string) {
    notice.value = '';
    error.value = '';
    const idx = selectedCodes.value.indexOf(code);
    if (idx >= 0) {
        if (selectedCodes.value.length <= 1) {
            error.value = 'Debes mantener al menos un método de pago activo.';
            return;
        }
        selectedCodes.value = selectedCodes.value.filter((c) => c !== code);
    } else {
        selectedCodes.value = [...selectedCodes.value, code];
    }
    dirty.value = true;
}

async function saveSelection() {
    if (!props.store?.id || !selectedCodes.value.length) return;
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.put<{ store_id: number; methods: StorePaymentMethodRow[] }>(
            `/business/stores/${props.store.id}/payment-methods`,
            { enabled_codes: selectedCodes.value },
        );
        applyStorePaymentMethods(props.store.id, data.methods);
        syncSelectionFromStore();
        notice.value = `Métodos de pago guardados (${selectedCodes.value.length} activos para ${props.store.name}).`;
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudieron guardar los métodos de pago.';
    } finally {
        saving.value = false;
    }
}

function openCreateModal() {
    editingId.value = null;
    customLabel.value = '';
    customBg.value = '#6435ed';
    customFg.value = '#ffffff';
    customSvg.value = '';
    customFileName.value = '';
    customError.value = '';
    modalOpen.value = true;
}

function openEditModal(item: PaymentMethodDef) {
    if (!item.isCustom || !item.id) return;
    editingId.value = item.id;
    customLabel.value = item.label;
    customBg.value = item.bg || '#6435ed';
    customFg.value = item.fg || '#ffffff';
    customSvg.value = item.iconSvg || '';
    customFileName.value = '';
    customError.value = '';
    modalOpen.value = true;
}

async function handleSvgFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    customError.value = '';
    if (!file.name.toLowerCase().endsWith('.svg') && file.type !== 'image/svg+xml') {
        customError.value = 'Selecciona un archivo de imagen en formato .svg.';
        input.value = '';
        return;
    }
    if (file.size > 32768) {
        customError.value = 'El archivo SVG no puede superar los 32 KB.';
        input.value = '';
        return;
    }
    const text = await file.text();
    customSvg.value = text.trim();
    customFileName.value = file.name;
    input.value = '';
}

const previewSvgUri = computed(() => svgDataUri(customSvg.value, customFg.value));

async function submitCustom() {
    if (!props.store?.id) return;
    const label = customLabel.value.trim();
    if (label.length < 2) {
        customError.value = 'Escribe un nombre de al menos 2 caracteres.';
        return;
    }
    customSaving.value = true;
    customError.value = '';
    notice.value = '';
    try {
        const payload = {
            label,
            bg_color: customBg.value,
            fg_color: customFg.value,
            icon_svg: customSvg.value.trim() || null,
            is_enabled: true,
        };
        const isEdit = editingId.value !== null;
        const url = isEdit
            ? `/business/stores/${props.store.id}/payment-methods/custom/${editingId.value}`
            : `/business/stores/${props.store.id}/payment-methods/custom`;
        const { data } = isEdit
            ? await api.patch<{ methods: StorePaymentMethodRow[]; created_code?: string }>(url, payload)
            : await api.post<{ methods: StorePaymentMethodRow[]; created_code?: string }>(url, payload);
        applyStorePaymentMethods(props.store.id, data.methods);
        syncSelectionFromStore();
        modalOpen.value = false;
        notice.value = isEdit
            ? `Método "${label}" actualizado correctamente.`
            : `Método "${label}" creado y activado para ${props.store.name}.`;
    } catch (e) {
        customError.value = errorMessage(e) || 'No se pudo guardar el método personalizado.';
    } finally {
        customSaving.value = false;
    }
}

async function deleteCustom(item: PaymentMethodDef) {
    if (!props.store?.id || !item.isCustom || !item.id) return;
    if (!window.confirm(`¿Eliminar el método personalizado "${item.label}"? Los pagos históricos conservarán su registro.`)) {
        return;
    }
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.delete<{ methods: StorePaymentMethodRow[] }>(
            `/business/stores/${props.store.id}/payment-methods/custom/${item.id}`,
        );
        applyStorePaymentMethods(props.store.id, data.methods);
        syncSelectionFromStore();
        notice.value = `Método "${item.label}" eliminado.`;
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudo eliminar el método personalizado.';
    }
}
</script>

<template>
    <section v-if="store" class="panel pm-settings">
        <div class="panel-heading pm-header">
            <div>
                <h2>Métodos de pago</h2>
                <p>
                    Activa los métodos disponibles en <strong>{{ store.name }}</strong> para ventas, apartados, abonos y reembolsos.
                    Si falta alguno, puedes crearlo con su nombre e imagen SVG.
                </p>
            </div>
            <div class="pm-header-actions">
                <button type="button" class="button secondary" :disabled="loading || saving" @click="openCreateModal">
                    + Crear método personalizado
                </button>
                <button
                    type="button"
                    class="button primary"
                    :disabled="loading || saving || !dirty"
                    @click="saveSelection"
                >
                    {{ saving ? 'Guardando...' : `Guardar selección (${selectedCodes.length})` }}
                </button>
            </div>
        </div>

        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>

        <div class="pm-toolbar">
            <input
                v-model="search"
                type="search"
                class="pm-search"
                placeholder="Buscar método (ej. Zelle, PayPal, Visa, Google Pay, Bitcoin...)"
                aria-label="Buscar método de pago"
            >
            <div class="pm-tabs" role="tablist" aria-label="Categorías de métodos de pago">
                <button
                    v-for="tab in CATEGORY_TABS"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    class="pm-tab"
                    :class="{ active: category === tab.id }"
                    :aria-selected="category === tab.id"
                    @click="category = tab.id"
                >
                    {{ tab.label }}
                    <span v-if="tab.id === 'active'" class="pm-tab-count">{{ selectedCodes.length }}</span>
                </button>
            </div>
        </div>

        <p v-if="loading" class="muted">Cargando catálogo de métodos de pago...</p>
        <p v-else-if="!filteredMethods.length" class="muted">
            No se encontraron métodos con ese filtro. Puedes crear uno personalizado con el botón <strong>+ Crear método personalizado</strong>.
        </p>

        <div v-else class="pm-catalog-grid">
            <div
                v-for="item in filteredMethods"
                :key="item.code"
                class="pm-card"
                :class="{ enabled: selectedSet.has(item.code) }"
                @click="toggleMethod(item.code)"
            >
                <span class="pm-card-icon" :style="{ background: item.bg, color: item.fg }" aria-hidden="true">
                    <img
                        v-if="item.iconSvg && svgDataUri(item.iconSvg, item.fg)"
                        :src="svgDataUri(item.iconSvg, item.fg) || ''"
                        alt=""
                        class="pm-card-svg"
                    >
                    <svg v-else viewBox="0 0 24 24" class="pm-card-svg" focusable="false">
                        <path :d="item.svgPath" fill="currentColor" />
                    </svg>
                </span>

                <div class="pm-card-meta">
                    <strong class="pm-card-title">{{ item.label }}</strong>
                    <span v-if="item.isCustom" class="pm-custom-badge">Personalizado</span>
                </div>

                <div class="pm-card-controls" @click.stop>
                    <button
                        v-if="item.isCustom"
                        type="button"
                        class="pm-mini-btn"
                        title="Editar método"
                        @click="openEditModal(item)"
                    >
                        Editar
                    </button>
                    <button
                        v-if="item.isCustom"
                        type="button"
                        class="pm-mini-btn danger"
                        title="Eliminar método"
                        @click="deleteCustom(item)"
                    >
                        ×
                    </button>
                    <label class="pm-switch" :aria-label="`Activar ${item.label}`">
                        <input
                            type="checkbox"
                            :checked="selectedSet.has(item.code)"
                            @change="toggleMethod(item.code)"
                        >
                        <span class="pm-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div v-if="dirty" class="pm-dirty-bar">
            <span>Tienes cambios sin guardar ({{ selectedCodes.length }} métodos activos).</span>
            <button type="button" class="button primary" :disabled="saving" @click="saveSelection">
                {{ saving ? 'Guardando...' : 'Guardar selección' }}
            </button>
        </div>

        <AppDialog
            :open="modalOpen"
            :title="editingId ? 'Editar método de pago' : 'Crear método de pago personalizado'"
            :busy="customSaving"
            @close="modalOpen = false"
        >
            <form id="custom-payment-method-form" class="form-stack pm-custom-form" @submit.prevent="submitCustom">
                <p v-if="customError" class="notice error" role="alert">{{ customError }}</p>

                <label>
                    Nombre del método *
                    <input
                        v-model="customLabel"
                        type="text"
                        required
                        minlength="2"
                        maxlength="60"
                        placeholder="Ej. Banreservas, BHD, Pago Móvil, Yappy, Nequi"
                        :disabled="customSaving"
                    >
                </label>

                <div class="pm-preview-box">
                    <span class="pm-preview-caption">Vista previa en el selector</span>
                    <div class="pm-preview-chip">
                        <span class="pm-card-icon" :style="{ background: customBg, color: customFg }">
                            <img v-if="previewSvgUri" :src="previewSvgUri" alt="" class="pm-card-svg">
                            <svg v-else viewBox="0 0 24 24" class="pm-card-svg">
                                <path d="M4 5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4Zm0 4h16v2H4V9Zm2 5h6v2H6v-2Z" fill="currentColor" />
                            </svg>
                        </span>
                        <strong>{{ customLabel.trim() || 'Mi método' }}</strong>
                    </div>
                </div>

                <div class="pm-color-row">
                    <label>
                        Color de fondo
                        <div class="pm-color-input">
                            <input v-model="customBg" type="color" :disabled="customSaving">
                            <span>{{ customBg }}</span>
                        </div>
                    </label>
                    <label>
                        Color del ícono
                        <div class="pm-color-input">
                            <input v-model="customFg" type="color" :disabled="customSaving">
                            <span>{{ customFg }}</span>
                        </div>
                    </label>
                </div>

                <div class="pm-presets">
                    <button
                        v-for="preset in COLOR_PRESETS"
                        :key="preset.name"
                        type="button"
                        class="pm-preset-dot"
                        :style="{ background: preset.bg, color: preset.fg }"
                        :title="preset.name"
                        @click="customBg = preset.bg; customFg = preset.fg"
                    >
                        Aa
                    </button>
                </div>

                <div class="pm-svg-uploader">
                    <div class="pm-svg-head">
                        <span>Imagen SVG (opcional)</span>
                        <label class="button secondary pm-upload-btn">
                            Subir archivo .svg
                            <input type="file" accept=".svg,image/svg+xml" hidden :disabled="customSaving" @change="handleSvgFile">
                        </label>
                    </div>
                    <small v-if="customFileName" class="muted">Archivo cargado: {{ customFileName }}</small>
                    <textarea
                        v-model="customSvg"
                        rows="4"
                        maxlength="32768"
                        placeholder='Pega aquí el código <svg viewBox="0 0 24 24">...</svg> o sube un archivo .svg'
                        :disabled="customSaving"
                    ></textarea>
                    <small class="muted">Se valida y limpia automáticamente para garantizar seguridad (máx. 32 KB).</small>
                </div>
            </form>

            <template #footer>
                <button type="button" class="button secondary" :disabled="customSaving" @click="modalOpen = false">
                    Cancelar
                </button>
                <button type="submit" form="custom-payment-method-form" class="button primary" :disabled="customSaving">
                    {{ customSaving ? 'Guardando...' : (editingId ? 'Guardar cambios' : 'Crear y activar') }}
                </button>
            </template>
        </AppDialog>
    </section>
</template>

<style scoped>
.pm-settings { display: grid; gap: 14px; }
.pm-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; }
.pm-header-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pm-toolbar { display: grid; gap: 10px; }
.pm-search { width: 100%; max-width: 460px; }
.pm-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
.pm-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #e1deef;
    background: #fff;
    color: #4b4668;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.pm-tab.active { background: #6435ed; border-color: #6435ed; color: #fff; }
.pm-tab-count {
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 11px;
    background: rgba(255, 255, 255, 0.22);
}
.pm-tab:not(.active) .pm-tab-count { background: #f3eeff; color: #5b21b6; }

.pm-catalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 10px;
}
.pm-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1.5px solid #e7e4f3;
    background: #fff;
    cursor: pointer;
    transition: border-color .14s ease, background .14s ease, box-shadow .14s ease;
    min-width: 0;
}
.pm-card:hover { border-color: #c4b5fd; background: #faf8ff; }
.pm-card.enabled { border-color: #6435ed; background: #f8f5ff; box-shadow: 0 0 0 2px #6435ed18; }
.pm-card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    padding: 5px;
    flex-shrink: 0;
    overflow: hidden;
}
.pm-card-svg { width: 100%; height: 100%; display: block; object-fit: contain; }
.pm-card-meta { display: grid; gap: 2px; min-width: 0; flex: 1; }
.pm-card-title { font-size: 13.5px; color: #1f1b38; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pm-custom-badge { font-size: 11px; color: #6435ed; font-weight: 600; }
.pm-card-controls { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pm-mini-btn {
    border: 1px solid #ddd6fe;
    background: #fff;
    color: #5b21b6;
    border-radius: 7px;
    padding: 3px 7px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
}
.pm-mini-btn.danger { border-color: #fecaca; color: #b91c1c; }

.pm-switch { position: relative; display: inline-block; width: 38px; height: 22px; flex-shrink: 0; }
.pm-switch input { opacity: 0; width: 0; height: 0; }
.pm-slider {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #d1d5db;
    transition: background .15s ease;
    cursor: pointer;
}
.pm-slider::before {
    content: '';
    position: absolute;
    left: 3px;
    top: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    transition: transform .15s ease;
}
.pm-switch input:checked + .pm-slider { background: #6435ed; }
.pm-switch input:checked + .pm-slider::before { transform: translateX(16px); }

.pm-dirty-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 12px;
    background: #f3eeff;
    border: 1px solid #d8b4fe;
    color: #3b1fa8;
    font-size: 13px;
    font-weight: 600;
}

.pm-custom-form { display: grid; gap: 12px; }
.pm-preview-box {
    display: grid;
    gap: 6px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f8f7fc;
    border: 1px dashed #d5d0ea;
}
.pm-preview-caption { font-size: 11.5px; font-weight: 600; color: #6b648a; }
.pm-preview-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
    padding: 6px 12px 6px 8px;
    border-radius: 10px;
    border: 1.5px solid #6435ed;
    background: #f3eeff;
    color: #3b1fa8;
    font-size: 13px;
}
.pm-color-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pm-color-input { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.pm-color-input input[type="color"] { width: 38px; height: 34px; padding: 2px; border-radius: 8px; cursor: pointer; }
.pm-presets { display: flex; flex-wrap: wrap; gap: 6px; }
.pm-preset-dot {
    width: 30px;
    height: 26px;
    border-radius: 7px;
    border: 1px solid rgba(0, 0, 0, 0.12);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
.pm-svg-uploader { display: grid; gap: 6px; }
.pm-svg-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; font-weight: 600; }
.pm-upload-btn { padding: 5px 10px; font-size: 12px; cursor: pointer; }

@media (max-width: 600px) {
    .pm-catalog-grid { grid-template-columns: 1fr; }
    .pm-color-row { grid-template-columns: 1fr; }
}
</style>
