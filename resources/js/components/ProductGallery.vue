<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogProduct, CatalogVariant, ProductImage } from '../catalog';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ open: boolean; product: CatalogProduct | null; variants: CatalogVariant[]; manage: boolean; opener: HTMLElement | null }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const images = ref<ProductImage[]>([]); const primary = ref<number | null>(null); const active = ref(0); const version = ref(0);
const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref(''); const pending = ref(0);
const quota = ref<{ max_bytes: number; ready_bytes: number; pending_bytes: number; retained_bytes: number; max_images_per_product: number }>();
const file = ref<File | null>(null); const preview = ref(''); const key = ref(''); const alt = ref(''); const variant = ref<number | ''>('');
const current = computed(() => images.value[active.value]);
const uploadAllowed = computed(() => props.manage && props.product?.status === 'active' && images.value.length + pending.value < (quota.value?.max_images_per_product ?? 5));
const used = computed(() => quota.value ? ((quota.value.ready_bytes + quota.value.pending_bytes + quota.value.retained_bytes) / 1048576).toFixed(1) : '0');
let touchX = 0; let sequence = 0; let lastProduct = 0;
function resetFile() { if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; file.value = null; key.value = ''; }
function choose(event: Event) {
    const input = event.target as HTMLInputElement; const chosen = input.files?.[0]; input.value = '';
    if (!chosen || busy.value) return;
    error.value = ''; notice.value = '';
    if (chosen.size > 10485760) { error.value = 'La fotografía supera 10 MiB. Reduce su tamaño.'; return; }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(chosen.type)) { error.value = 'Usa JPEG, PNG o WebP. Para HEIC, exporta primero como JPEG.'; return; }
    resetFile(); file.value = chosen; preview.value = URL.createObjectURL(chosen); key.value = crypto.randomUUID();
}
async function load() {
    if (!props.product) return; const token = ++sequence; loading.value = true;
    try {
        const data = (await api.get(`/business/products/${props.product.id}/images`)).data.data;
        if (token !== sequence) return;
        images.value = data.images; version.value = data.version; quota.value = data.quota; pending.value = data.pending_uploads || 0;
        primary.value = images.value.find(image => image.is_primary)?.id ?? null; active.value = Math.max(0, Math.min(active.value, images.value.length - 1));
    } catch (e) { if (token === sequence) error.value = errorMessage(e); }
    finally { if (token === sequence) loading.value = false; }
}
async function upload() {
    if (!file.value || !props.product || busy.value || !uploadAllowed.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    const body = new FormData(); body.append('file', file.value); body.append('request_key', key.value); body.append('alt_text', alt.value);
    if (variant.value !== '') body.append('variant_id', String(variant.value));
    try { await api.post(`/business/products/${props.product.id}/images`, body, { timeout: 90000 }); resetFile(); alt.value = ''; variant.value = ''; await load(); emit('changed'); notice.value = 'Foto publicada correctamente.'; }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
async function checkStatus() {
    if (!key.value || !props.product || busy.value) return; busy.value = true; error.value = '';
    try {
        const data = (await api.get(`/business/products/${props.product.id}/uploads/${key.value}`)).data.data;
        if (data.status === 'verified') { resetFile(); await load(); emit('changed'); notice.value = 'La foto ya está publicada.'; }
        else notice.value = data.retryable ? 'La carga falló y puedes reintentar el mismo archivo.' : `Estado: ${data.status}. Espera antes de reintentar; si continúa pendiente, requiere recuperación del servidor.`;
    } catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
async function save() {
    if (!props.product || busy.value) return; busy.value = true; error.value = ''; notice.value = '';
    try {
        await api.patch(`/business/products/${props.product.id}/images`, { version: version.value, primary_id: primary.value, images: images.value.map(image => ({ id: image.id, variant_id: image.variant_id, alt_text: image.alt_text })) });
        await load(); emit('changed'); notice.value = 'Galería guardada.';
    } catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
const removing = ref<number | null>(null);
async function remove() {
    if (!props.product || removing.value === null || busy.value) return; busy.value = true; error.value = ''; notice.value = '';
    try { await api.delete(`/business/products/${props.product.id}/images/${removing.value}`, { data: { version: version.value } }); removing.value = null; await load(); emit('changed'); notice.value = 'Foto retirada. Su espacio se libera tras la limpieza diferida.'; }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
function move(index: number, delta: number) { const target = index + delta; if (target < 0 || target >= images.value.length) return; const item = images.value.splice(index, 1)[0]; images.value.splice(target, 0, item); active.value = target; }
function step(delta: number) { if (images.value.length) active.value = (active.value + delta + images.value.length) % images.value.length; }
watch(() => props.open, open => {
    if (open && props.product) { if (lastProduct !== props.product.id) { resetFile(); alt.value = ''; variant.value = ''; active.value = 0; lastProduct = props.product.id; } error.value = ''; notice.value = ''; removing.value = null; load(); }
    else ++sequence;
});
onUnmounted(() => { ++sequence; resetFile(); });
</script>

<template>
    <AppDialog :open="open" :title="`Fotos de ${product?.name || 'producto'}`" :busy="busy || loading" :return-focus="opener" @close="emit('close')">
        <p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="notice" class="notice success" role="status">{{ notice }}</p>
        <p v-if="loading" role="status">Cargando galería…</p>
        <fieldset :disabled="busy || loading" class="photo-fieldset">
            <p class="muted">{{ images.length }} de {{ quota?.max_images_per_product ?? 5 }} fotos · JPEG, PNG o WebP · máximo 10 MiB y 20 MP.</p>
            <p v-if="quota" class="muted">Almacenamiento del negocio: {{ used }} / {{ (quota.max_bytes / 1048576).toFixed(0) }} MiB, incluidos pendientes y retenidos.</p>
            <div v-if="current" class="photo-viewer" @touchstart.passive="touchX = $event.touches[0].clientX" @touchend.passive="Math.abs($event.changedTouches[0].clientX - touchX) > 60 && step($event.changedTouches[0].clientX < touchX ? 1 : -1)">
                <img :src="current.renditions.detail.url || undefined" :alt="current.alt_text || product?.name" decoding="async">
                <div class="actions"><button type="button" class="button secondary" :disabled="images.length < 2" aria-label="Foto anterior" @click="step(-1)">←</button><span>{{ active + 1 }} / {{ images.length }}</span><button type="button" class="button secondary" :disabled="images.length < 2" aria-label="Foto siguiente" @click="step(1)">→</button></div>
            </div>
            <p v-else class="empty">Este producto todavía no tiene fotos.</p>
            <div class="photo-records">
                <article v-for="(image, index) in images" :key="image.id" class="photo-record">
                    <button type="button" class="photo-thumb" :aria-label="`Ver foto ${index + 1}`" :aria-pressed="active === index" @click="active = index"><img :src="image.renditions.thumb.url || undefined" :alt="image.alt_text || `Foto ${index + 1}`" loading="lazy"></button>
                    <template v-if="manage">
                        <label><input v-model="primary" type="radio" name="primary-photo" :value="image.id"> Principal {{ index + 1 }}</label>
                        <label>Descripción foto {{ index + 1 }}<input v-model="image.alt_text" maxlength="255"></label>
                        <label>Variante foto {{ index + 1 }}<select v-model="image.variant_id"><option :value="null">Todo el producto</option><option v-if="image.variant_id && !variants.some(v => v.id === image.variant_id)" :value="image.variant_id">Variante #{{ image.variant_id }} (otra página/inactiva)</option><option v-for="item in variants.filter(v => v.is_active)" :key="item.id" :value="item.id">{{ item.sku }}</option></select></label>
                        <div class="actions"><button type="button" class="button secondary" :disabled="index === 0" :aria-label="`Subir foto ${index + 1}`" @click="move(index, -1)">↑</button><button type="button" class="button secondary" :disabled="index === images.length - 1" :aria-label="`Bajar foto ${index + 1}`" @click="move(index, 1)">↓</button><button type="button" class="button danger" :aria-label="`Retirar foto ${index + 1}`" @click="removing = image.id">Retirar</button></div>
                    </template>
                </article>
            </div>
            <section v-if="removing !== null" class="notice" role="alert"><p>¿Retirar esta foto del catálogo? Los archivos se conservan temporalmente antes de liberar el espacio.</p><div class="actions"><button type="button" class="button danger" @click="remove">Confirmar retiro</button><button type="button" class="button secondary" @click="removing = null">Conservar foto</button></div></section>
            <section v-if="manage" class="photo-upload">
                <h3>Añadir fotografía</h3><p v-if="!uploadAllowed" class="muted">{{ product?.status !== 'active' ? 'Reactiva el producto para cargar fotos.' : 'Se alcanzó el máximo de fotos, incluidas las cargas pendientes.' }}</p>
                <label>Seleccionar archivo<input type="file" accept="image/jpeg,image/png,image/webp" :disabled="!uploadAllowed" @change="choose"></label>
                <label>Tomar foto con cámara<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" :disabled="!uploadAllowed" @change="choose"></label>
                <template v-if="file"><img class="photo-preview" :src="preview" alt="Vista previa del archivo seleccionado"><p>{{ file.name }} · {{ (file.size / 1048576).toFixed(2) }} MiB</p><label>Descripción de la nueva foto<input v-model="alt" maxlength="255"></label><label>Variante de la nueva foto<select v-model="variant"><option value="">Todo el producto</option><option v-for="item in variants.filter(v => v.is_active)" :key="item.id" :value="item.id">{{ item.sku }}</option></select></label><div class="actions"><button type="button" class="button primary" :disabled="!uploadAllowed" @click="upload">Publicar foto</button><button type="button" class="button secondary" @click="checkStatus">Consultar carga</button><button type="button" class="button secondary" @click="resetFile">Descartar archivo</button></div></template>
            </section>
        </fieldset>
        <template #footer><button type="button" class="button secondary" :disabled="busy || loading" @click="load">Recargar galería</button><button v-if="manage && images.length" type="button" class="button primary" :disabled="busy || loading" @click="save">Guardar galería</button><button type="button" class="button secondary" :disabled="busy || loading" @click="emit('close')">Cerrar</button></template>
    </AppDialog>
</template>

<style scoped>
.photo-fieldset { border: 0; padding: 0; min-width: 0; }
.photo-viewer { display: grid; justify-items: center; gap: .75rem; margin: 1rem 0; }
.photo-viewer > img { max-width: 100%; width: auto; height: min(40dvh, 350px); object-fit: contain; background: #f3f4f6; border-radius: 12px; }
.photo-records { display: grid; gap: 1rem; }
.photo-record { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: .75rem; padding: 1rem; border: 1px solid #dce2eb; border-radius: 12px; }
.photo-record > label, .photo-record > .actions { grid-column: 2; }
.photo-thumb { grid-row: 1 / span 3; border: 2px solid transparent; border-radius: 8px; padding: 0; min-height: 88px; background: #f3f4f6; align-self: start; }
.photo-thumb[aria-pressed="true"] { border-color: #4f46e5; }
.photo-thumb img { width: 84px; height: 84px; object-fit: contain; }
.photo-upload { display: grid; gap: 1rem; margin-top: 1.5rem; }
.photo-preview { max-width: 100%; height: 160px; object-fit: contain; }
.photo-record label:has(input[type="radio"]) { display: flex; align-items: center; min-height: 48px; gap: .75rem; }
.photo-record input[type="radio"] { width: 24px; height: 24px; }
.photo-fieldset input[type="file"] { max-width: 100%; min-height: 48px; }
@media (max-width: 480px) { .photo-record { grid-template-columns: 1fr; } .photo-record > label, .photo-record > .actions { grid-column: 1; } .photo-thumb { grid-row: auto; width: 88px; } }
</style>
