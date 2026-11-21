<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Store } from '../types';

interface InvoiceSettingsData {
    store_id: number;
    has_logo: boolean;
    logo_data_uri: string | null;
    logo_width_px: number;
    accent_color: string;
    document_title: string;
    brand_subtitle: string | null;
    show_logo: boolean;
    show_product_images: boolean;
    show_sku: boolean;
    show_store_contact: boolean;
    show_customer_contact: boolean;
    show_payment_summary: boolean;
    show_payment_methods: boolean;
    show_notes: boolean;
    footer_text: string;
    terms_text: string | null;
}

const props = defineProps<{ store: Store | null }>();

const loading = ref(false);
const saving = ref(false);
const uploadingLogo = ref(false);
const notice = ref('');
const error = ref('');

const form = reactive<InvoiceSettingsData>({
    store_id: 0,
    has_logo: false,
    logo_data_uri: null,
    logo_width_px: 52,
    accent_color: '#4f46e5',
    document_title: 'FACTURA',
    brand_subtitle: '',
    show_logo: true,
    show_product_images: true,
    show_sku: true,
    show_store_contact: true,
    show_customer_contact: true,
    show_payment_summary: true,
    show_payment_methods: true,
    show_notes: true,
    footer_text: 'Gracias por su preferencia · Documento histórico de la operación.',
    terms_text: '',
});

const COLOR_PRESETS = [
    { name: 'Índigo', hex: '#4f46e5' },
    { name: 'Morado BShop', hex: '#6435ed' },
    { name: 'Negro Ejecutivo', hex: '#18181b' },
    { name: 'Azul Marino', hex: '#1e3a8a' },
    { name: 'Esmeralda', hex: '#047857' },
    { name: 'Vino / Rosa', hex: '#be185d' },
];

const TOGGLE_ITEMS: Array<{ key: keyof InvoiceSettingsData; label: string; hint: string }> = [
    { key: 'show_product_images', label: 'Imágenes de productos', hint: 'Muestra la foto miniatura de cada producto en la tabla.' },
    { key: 'show_logo', label: 'Logo de la tienda', hint: 'Muestra tu logo en el encabezado (o la inicial si no hay logo).' },
    { key: 'show_sku', label: 'Código SKU', hint: 'Muestra el SKU debajo del nombre de cada producto.' },
    { key: 'show_store_contact', label: 'Contacto de la tienda', hint: 'Incluye teléfono y dirección de la sucursal.' },
    { key: 'show_customer_contact', label: 'Contacto del cliente', hint: 'Incluye teléfono, correo y dirección del cliente.' },
    { key: 'show_payment_summary', label: 'Resumen de pago', hint: 'Muestra estado, monto abonado y saldo pendiente.' },
    { key: 'show_payment_methods', label: 'Métodos de pago', hint: 'Muestra el método usado (Zelle, Efectivo, Tarjeta, etc.).' },
    { key: 'show_notes', label: 'Notas de la factura', hint: 'Muestra las observaciones escritas en la venta o apartado.' },
];

function applyData(data: InvoiceSettingsData) {
    Object.assign(form, {
        ...data,
        brand_subtitle: data.brand_subtitle ?? '',
        terms_text: data.terms_text ?? '',
    });
}

async function load() {
    if (!props.store?.id) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get<{ data: InvoiceSettingsData }>(
            `/business/stores/${props.store.id}/invoice-settings`,
        );
        applyData(data.data);
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudo cargar la configuración de la factura.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.store?.id, () => {
    notice.value = '';
    error.value = '';
    load();
}, { immediate: true });

async function save() {
    if (!props.store?.id) return;
    saving.value = true;
    notice.value = '';
    error.value = '';
    try {
        const { data } = await api.patch<{ data: InvoiceSettingsData }>(
            `/business/stores/${props.store.id}/invoice-settings`,
            {
                logo_width_px: form.logo_width_px,
                accent_color: form.accent_color,
                document_title: form.document_title.trim() || 'FACTURA',
                brand_subtitle: (form.brand_subtitle || '').trim() || null,
                show_logo: form.show_logo,
                show_product_images: form.show_product_images,
                show_sku: form.show_sku,
                show_store_contact: form.show_store_contact,
                show_customer_contact: form.show_customer_contact,
                show_payment_summary: form.show_payment_summary,
                show_payment_methods: form.show_payment_methods,
                show_notes: form.show_notes,
                footer_text: form.footer_text.trim() || null,
                terms_text: (form.terms_text || '').trim() || null,
            },
        );
        applyData(data.data);
        notice.value = `Diseño de factura guardado para ${props.store.name}. Se aplicará a todas las nuevas facturas.`;
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudo guardar la configuración de la factura.';
    } finally {
        saving.value = false;
    }
}

async function onLogoSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !props.store?.id) return;
    uploadingLogo.value = true;
    error.value = '';
    notice.value = '';
    try {
        const body = new FormData();
        body.append('logo', file);
        const { data } = await api.post<{ data: InvoiceSettingsData }>(
            `/business/stores/${props.store.id}/invoice-settings/logo`,
            body,
        );
        applyData(data.data);
        notice.value = 'Logo cargado y activado en la factura.';
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudo subir el logo.';
    } finally {
        uploadingLogo.value = false;
        input.value = '';
    }
}

async function removeLogo() {
    if (!props.store?.id) return;
    uploadingLogo.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.delete<{ data: InvoiceSettingsData }>(
            `/business/stores/${props.store.id}/invoice-settings/logo`,
        );
        applyData(data.data);
        notice.value = 'Logo eliminado.';
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudo eliminar el logo.';
    } finally {
        uploadingLogo.value = false;
    }
}

const previewUrl = computed(() =>
    props.store?.id ? `/api/v1/business/stores/${props.store.id}/invoice-settings/preview?t=${Date.now()}` : '#',
);

function openPdfPreview() {
    if (!props.store?.id) return;
    window.open(previewUrl.value, '_blank', 'noopener');
}
</script>

<template>
    <section v-if="store" class="panel inv-settings">
        <div class="panel-heading inv-header">
            <div>
                <h2>Diseño de factura PDF</h2>
                <p>
                    Personaliza la factura de <strong>{{ store.name }}</strong>: sube tu logo, elige si se muestran
                    imágenes de productos, colores y campos visibles.
                </p>
            </div>
            <div class="inv-header-actions">
                <button type="button" class="button secondary" :disabled="loading || saving" @click="openPdfPreview">
                    Ver PDF de prueba
                </button>
                <button type="button" class="button primary" :disabled="loading || saving" @click="save">
                    {{ saving ? 'Guardando...' : 'Guardar diseño' }}
                </button>
            </div>
        </div>

        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>

        <div v-if="loading" class="muted">Cargando configuración de factura...</div>

        <div v-else class="inv-layout">
            <div class="inv-controls">
                <!-- Logo block -->
                <div class="inv-box">
                    <h3>Logo de la tienda</h3>
                    <div class="inv-logo-row">
                        <div class="inv-logo-preview" :style="{ borderColor: form.accent_color }">
                            <img
                                v-if="form.has_logo && form.logo_data_uri && form.show_logo"
                                :src="form.logo_data_uri"
                                alt="Logo de factura"
                            >
                            <span
                                v-else
                                class="inv-logo-fallback"
                                :style="{ background: form.accent_color }"
                            >
                                {{ (store.name || 'B').slice(0, 1).toUpperCase() }}
                            </span>
                        </div>
                        <div class="inv-logo-actions">
                            <label class="button secondary inv-upload-btn" :class="{ disabled: uploadingLogo }">
                                {{ uploadingLogo ? 'Subiendo...' : (form.has_logo ? 'Cambiar logo' : 'Subir logo') }}
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    hidden
                                    :disabled="uploadingLogo"
                                    @change="onLogoSelected"
                                >
                            </label>
                            <button
                                v-if="form.has_logo"
                                type="button"
                                class="button secondary"
                                :disabled="uploadingLogo"
                                @click="removeLogo"
                            >
                                Quitar
                            </button>
                            <small class="muted">PNG, JPG o WebP (máx. 2 MB). Fondo transparente recomendado.</small>
                        </div>
                    </div>

                    <label v-if="form.has_logo && form.show_logo" class="inv-range-label">
                        <span>Tamaño del logo en la factura: <strong>{{ form.logo_width_px }} px</strong></span>
                        <input v-model.number="form.logo_width_px" type="range" min="28" max="100" step="2">
                    </label>
                </div>

                <!-- Branding & Texts -->
                <div class="inv-box form-stack">
                    <h3>Identidad y textos</h3>
                    <div class="inv-two-col">
                        <label>
                            Título del documento
                            <input v-model="form.document_title" type="text" maxlength="40" placeholder="FACTURA">
                        </label>
                        <label>
                            Color principal
                            <div class="inv-color-pick">
                                <input v-model="form.accent_color" type="color">
                                <span>{{ form.accent_color }}</span>
                            </div>
                        </label>
                    </div>

                    <div class="inv-presets">
                        <button
                            v-for="c in COLOR_PRESETS"
                            :key="c.hex"
                            type="button"
                            class="inv-color-dot"
                            :class="{ active: form.accent_color.toLowerCase() === c.hex }"
                            :style="{ background: c.hex }"
                            :title="c.name"
                            @click="form.accent_color = c.hex"
                        ></button>
                    </div>

                    <label>
                        Subtítulo / RNC / Slogan (opcional)
                        <input
                            v-model="form.brand_subtitle"
                            type="text"
                            maxlength="120"
                            placeholder="Ej. RNC 131-00000-1 · Moda & Accesorios"
                        >
                    </label>

                    <label>
                        Mensaje al pie de la factura
                        <input
                            v-model="form.footer_text"
                            type="text"
                            maxlength="240"
                            placeholder="Gracias por su preferencia · Documento histórico de la operación."
                        >
                    </label>

                    <label>
                        Términos, política de cambios o datos bancarios (opcional)
                        <textarea
                            v-model="form.terms_text"
                            rows="2"
                            maxlength="1000"
                            placeholder="Ej. Cambios dentro de 7 días con etiqueta. Cuenta Zelle: pagos@tutienda.com"
                        ></textarea>
                    </label>
                </div>

                <!-- Toggles -->
                <div class="inv-box">
                    <h3>Elementos visibles en la factura</h3>
                    <div class="inv-toggles">
                        <label v-for="item in TOGGLE_ITEMS" :key="item.key" class="inv-toggle-row">
                            <div>
                                <strong>{{ item.label }}</strong>
                                <small>{{ item.hint }}</small>
                            </div>
                            <span class="inv-switch">
                                <input v-model="form[item.key]" type="checkbox">
                                <span class="inv-slider"></span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Live Mini Preview -->
            <aside class="inv-preview-col">
                <div class="inv-preview-paper">
                    <div class="inv-pv-header" :style="{ borderBottomColor: form.accent_color }">
                        <div class="inv-pv-brand">
                            <img
                                v-if="form.has_logo && form.logo_data_uri && form.show_logo"
                                :src="form.logo_data_uri"
                                alt=""
                                class="inv-pv-logo"
                                :style="{ maxWidth: `${Math.min(form.logo_width_px, 56)}px`, maxHeight: `${Math.min(form.logo_width_px, 56)}px` }"
                            >
                            <span v-else class="inv-pv-mark" :style="{ background: form.accent_color }">
                                {{ (store.name || 'B').slice(0, 1).toUpperCase() }}
                            </span>
                            <div>
                                <strong>{{ store.name }}</strong>
                                <small v-if="form.brand_subtitle">{{ form.brand_subtitle }}</small>
                                <small v-if="form.show_store_contact && (store.address || store.phone)">
                                    {{ [store.address, store.phone].filter(Boolean).join(' · ') }}
                                </small>
                            </div>
                        </div>
                        <div class="inv-pv-doc">
                            <span :style="{ color: form.accent_color }">{{ form.document_title || 'FACTURA' }}</span>
                            <strong>{{ store.code || 'FAC' }}-2026-0001</strong>
                        </div>
                    </div>

                    <div class="inv-pv-cards">
                        <div>
                            <span :style="{ color: form.accent_color }">CLIENTES</span>
                            <strong>Cliente de Prueba</strong>
                            <small v-if="form.show_customer_contact">+1 809-555-0100</small>
                        </div>
                        <div>
                            <span :style="{ color: form.accent_color }">DETALLES</span>
                            <strong>Hoy · {{ store.name }}</strong>
                            <small>Moneda: USD</small>
                        </div>
                    </div>

                    <div class="inv-pv-table">
                        <div class="inv-pv-thead" :style="{ color: form.accent_color, borderColor: form.accent_color }">
                            <span>Producto</span>
                            <span>Importe</span>
                        </div>
                        <div class="inv-pv-row">
                            <div class="inv-pv-item">
                                <span v-if="form.show_product_images" class="inv-pv-thumb" aria-hidden="true">IMG</span>
                                <div>
                                    <strong>Producto ejemplo × 1</strong>
                                    <small v-if="form.show_sku">SKU: PROD-001</small>
                                </div>
                            </div>
                            <strong class="inv-pv-price">US$ 125.00</strong>
                        </div>
                    </div>

                    <div class="inv-pv-bottom">
                        <div v-if="form.show_payment_summary" class="inv-pv-pay">
                            <span :style="{ color: form.accent_color }">RESUMEN</span>
                            <small>Abonado: US$ 125.00</small>
                            <small v-if="form.show_payment_methods">Método: Zelle</small>
                        </div>
                        <div class="inv-pv-total" :style="{ background: form.accent_color }">
                            <span>TOTAL</span>
                            <strong>US$ 125.00</strong>
                        </div>
                    </div>

                    <div v-if="form.show_notes" class="inv-pv-notes">
                        Nota: entrega coordinada con el cliente.
                    </div>
                    <div class="inv-pv-footer">
                        <div v-if="form.terms_text">{{ form.terms_text }}</div>
                        <div v-if="form.footer_text">{{ form.footer_text }}</div>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</template>

<style scoped>
.inv-settings { display: grid; gap: 14px; }
.inv-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; }
.inv-header-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.inv-layout { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.95fr); gap: 16px; align-items: start; }
.inv-controls { display: grid; gap: 14px; min-width: 0; }
.inv-box { border: 1px solid #e7e4f3; border-radius: 12px; padding: 14px; background: #fff; display: grid; gap: 10px; }
.inv-box h3 { margin: 0; font-size: 14px; color: #1f1b38; }

.inv-logo-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.inv-logo-preview {
    width: 72px;
    height: 72px;
    border-radius: 12px;
    border: 2px dashed #d5d0ea;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #faf8ff;
    flex-shrink: 0;
}
.inv-logo-preview img { max-width: 64px; max-height: 64px; object-fit: contain; }
.inv-logo-fallback {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    color: #fff;
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}
.inv-logo-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; flex: 1; min-width: 180px; }
.inv-logo-actions small { flex-basis: 100%; }
.inv-upload-btn { cursor: pointer; }
.inv-range-label { display: grid; gap: 4px; font-size: 12.5px; color: #4b4668; }

.inv-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.inv-color-pick { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.inv-color-pick input[type="color"] { width: 38px; height: 34px; padding: 2px; border-radius: 8px; cursor: pointer; }
.inv-presets { display: flex; flex-wrap: wrap; gap: 8px; }
.inv-color-dot {
    width: 24px;
    height: 24px;
    border-radius: 999px;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.16);
    cursor: pointer;
}
.inv-color-dot.active { box-shadow: 0 0 0 2px #1f1b38; transform: scale(1.08); }

.inv-toggles { display: grid; gap: 8px; }
.inv-toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 10px;
    border-radius: 10px;
    background: #faf9fd;
    border: 1px solid #eeebf7;
    cursor: pointer;
}
.inv-toggle-row div { display: grid; gap: 2px; min-width: 0; }
.inv-toggle-row strong { font-size: 13px; color: #1f1b38; }
.inv-toggle-row small { font-size: 11.5px; color: #6b648a; }

.inv-switch { position: relative; display: inline-block; width: 38px; height: 22px; flex-shrink: 0; }
.inv-switch input { opacity: 0; width: 0; height: 0; }
.inv-slider { position: absolute; inset: 0; border-radius: 999px; background: #d1d5db; transition: background .15s ease; }
.inv-slider::before {
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
.inv-switch input:checked + .inv-slider { background: #6435ed; }
.inv-switch input:checked + .inv-slider::before { transform: translateX(16px); }

/* Live Mini Preview */
.inv-preview-col { position: sticky; top: 16px; }
.inv-preview-paper {
    background: #fff;
    border: 1px solid #e1deef;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 8px 24px rgba(31, 27, 56, 0.06);
    display: grid;
    gap: 12px;
    font-size: 11.5px;
    color: #18192b;
}
.inv-pv-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; padding-bottom: 10px; border-bottom: 2px solid #4f46e5; }
.inv-pv-brand { display: flex; align-items: center; gap: 8px; min-width: 0; }
.inv-pv-brand div { display: grid; gap: 1px; min-width: 0; }
.inv-pv-brand small { font-size: 9.5px; color: #646982; }
.inv-pv-logo { border-radius: 6px; object-fit: contain; }
.inv-pv-mark { width: 32px; height: 32px; border-radius: 7px; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
.inv-pv-doc { text-align: right; display: grid; gap: 2px; flex-shrink: 0; }
.inv-pv-doc span { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; }
.inv-pv-doc strong { font-size: 12px; white-space: nowrap; }

.inv-pv-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.inv-pv-cards > div { background: #f8f8fc; border: 1px solid #e5e7f0; border-radius: 8px; padding: 8px; display: grid; gap: 2px; }
.inv-pv-cards span { font-size: 8.5px; font-weight: 700; letter-spacing: 0.6px; }
.inv-pv-cards small { font-size: 9.5px; color: #646982; }

.inv-pv-table { display: grid; gap: 6px; }
.inv-pv-thead {
    display: flex;
    justify-content: space-between;
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 6px 8px;
    background: #eef2ff;
    border-top: 1px solid;
    border-bottom: 1px solid;
}
.inv-pv-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 8px; border-bottom: 1px solid #eceef6; }
.inv-pv-item { display: flex; align-items: center; gap: 8px; min-width: 0; }
.inv-pv-item div { display: grid; gap: 1px; }
.inv-pv-item small { font-size: 9.5px; color: #646982; }
.inv-pv-thumb {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #64748b;
    font-size: 8.5px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.inv-pv-price { white-space: nowrap; }

.inv-pv-bottom { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.inv-pv-pay { background: #f8f8fc; border: 1px solid #e5e7f0; border-radius: 8px; padding: 7px 9px; display: grid; gap: 2px; flex: 1; }
.inv-pv-pay span { font-size: 8.5px; font-weight: 700; }
.inv-pv-pay small { font-size: 9.5px; color: #475569; }
.inv-pv-total {
    color: #fff;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 135px;
    white-space: nowrap;
}
.inv-pv-notes { font-size: 9.5px; color: #475569; background: #f8f8fc; border-radius: 6px; padding: 6px 8px; }
.inv-pv-footer { border-top: 1px solid #e5e7f0; padding-top: 8px; text-align: center; font-size: 9.5px; color: #646982; display: grid; gap: 3px; }

@media (max-width: 960px) {
    .inv-layout { grid-template-columns: 1fr; }
    .inv-preview-col { position: static; }
}
@media (max-width: 560px) {
    .inv-two-col { grid-template-columns: 1fr; }
    .inv-header-actions { width: 100%; }
    .inv-header-actions .button { flex: 1; }
}
</style>
