<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Business } from '../types';
import type { CatalogOptions } from '../catalog';
import type { CatalogImportBatch, CatalogImportHistory } from '../catalogImport';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ business: Business; options: CatalogOptions; costs: boolean }>();
const emit = defineEmits<{ back: []; changed: []; product: [id: number] }>();
const batch = ref<CatalogImportBatch | null>(null); const selectedFile = ref<File | null>(null);
const busy = ref(false); const action = ref(''); const error = ref(''); const notice = ref(''); const historyError = ref('');
const history = ref<CatalogImportHistory[]>([]); const historyPage = ref(1); const historyLast = ref(1); const historyLoading = ref(false);
const revertOpen = ref(false); const opener = ref<HTMLElement | null>(null);
const errorNotice = ref<HTMLElement>(); const revertError = ref<HTMLElement>(); const statusNotice = ref<HTMLElement>(); const reviewHeading = ref<HTMLElement>();
const actionbar = ref<HTMLElement>(); const toolbarHeight = ref(0); const fileInput = ref<HTMLInputElement>();
watch(actionbar, (element, _, cleanup) => {
    if (!element) { toolbarHeight.value = 0; return; }
    const resize = new ResizeObserver(() => { toolbarHeight.value = Math.ceil(element.getBoundingClientRect().height); });
    resize.observe(element); cleanup(() => resize.disconnect());
}, { flush: 'post' });
const base = '/business/catalog/imports';
const validRows = computed(() => batch.value ? batch.value.row_count - batch.value.error_count : 0);
const labels: Record<string, string> = { name: 'Nombre', sku: 'SKU', barcode: 'Barras', sale_price: 'Precio', cost_price: 'Costo', attribute_value_ids: 'Atributos', product_key: 'Grupo', category_id: 'Categoría', brand_id: 'Marca', product_type: 'Tipo', track_stock: 'Stock', row: 'Fila CSV' };
function problem(value: unknown): string {
    if (axios.isAxiosError(value) && [409, 410].includes(value.response?.status || 0)) return value.response?.data?.message || errorMessage(value);
    return errorMessage(value);
}
function failed(value: unknown) {
    error.value = problem(value);
    if (axios.isAxiosError(value)) {
        if (value.response?.data?.data?.id) batch.value = value.response.data.data;
        if (value.response?.status === 403) { batch.value = null; revertOpen.value = false; }
        if (value.response?.status === 410 && batch.value) { batch.value.is_expired = true; batch.value.can_confirm = false; }
    }
}
async function feedback() { await nextTick(); (error.value ? (revertOpen.value ? revertError.value : errorNotice.value) : statusNotice.value)?.focus(); }
function date(value: string) { return new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.business.timezone }).format(new Date(value)); }
function status(item: Pick<CatalogImportHistory, 'status' | 'error_count'>, expired = false) {
    return item.status === 'committed' ? 'Importado' : item.status === 'reverted' ? 'Revertido' : expired ? 'Vista previa vencida' : item.error_count > 0 ? 'Con errores' : 'Vista previa';
}
function fileChanged(event: Event) { selectedFile.value = (event.target as HTMLInputElement).files?.[0] || null; batch.value = null; notice.value = ''; error.value = ''; }
async function focusReview() { await nextTick(); reviewHeading.value?.focus({ preventScroll: true }); reviewHeading.value?.scrollIntoView({ block: 'start', behavior: 'auto' }); }
async function loadHistory(page = 1) {
    historyLoading.value = true; historyError.value = '';
    try { const response = await api.get(base, { params: { page } }); history.value = response.data.data; historyPage.value = response.data.meta.current_page; historyLast.value = response.data.meta.last_page; }
    catch (e) { historyError.value = errorMessage(e); }
    finally { historyLoading.value = false; }
}
async function preview() {
    if (busy.value || !selectedFile.value) return;
    if (selectedFile.value.size > 2097152) { error.value = 'El archivo supera el máximo de 2 MiB.'; return; }
    busy.value = true; action.value = 'preview'; error.value = ''; notice.value = '';
    try {
        const body = new FormData(); body.append('file', selectedFile.value);
        batch.value = (await api.post(`${base}/preview`, body)).data.data;
        notice.value = 'Vista previa preparada. Todavía no se ha añadido ningún producto.';
        await loadHistory(); await focusReview();
    } catch (e) { failed(e); await feedback(); }
    finally { busy.value = false; action.value = ''; }
}
async function open(id: string, page = 1) {
    if (busy.value) return;
    busy.value = true; action.value = 'open'; error.value = ''; notice.value = '';
    try { batch.value = (await api.get(`${base}/${id}`, { params: { page } })).data.data; selectedFile.value = null; if (fileInput.value) fileInput.value.value = ''; await focusReview(); }
    catch (e) { failed(e); await feedback(); }
    finally { busy.value = false; action.value = ''; }
}
async function revalidate() {
    if (busy.value || !batch.value) return;
    busy.value = true; action.value = 'revalidate'; error.value = ''; notice.value = '';
    try { batch.value = (await api.post(`${base}/${batch.value.id}/revalidate`)).data.data; notice.value = 'Revisión actualizada con el catálogo actual.'; await loadHistory(historyPage.value); }
    catch (e) { failed(e); }
    finally { busy.value = false; action.value = ''; }
    await feedback();
}
async function confirm() {
    if (busy.value || !batch.value?.can_confirm) return;
    busy.value = true; action.value = 'confirm'; error.value = ''; notice.value = '';
    try {
        batch.value = (await api.post(`${base}/${batch.value.id}/confirm`)).data.data;
        notice.value = `Importación completada: ${batch.value!.product_count} productos y ${batch.value!.row_count} variantes.`;
        emit('changed'); await loadHistory();
    } catch (e) { failed(e); await loadHistory(historyPage.value); }
    finally { busy.value = false; action.value = ''; }
    await feedback();
}
function requestRevert(event: Event) { opener.value = event.currentTarget as HTMLElement; error.value = ''; revertOpen.value = true; }
async function revert() {
    if (busy.value || !batch.value?.can_revert) return;
    busy.value = true; action.value = 'revert'; error.value = ''; notice.value = '';
    try { batch.value = (await api.post(`${base}/${batch.value.id}/revert`)).data.data; revertOpen.value = false; notice.value = 'Importación revertida. Sus productos están archivados y el historial se conserva.'; emit('changed'); await loadHistory(); }
    catch (e) { failed(e); }
    finally { busy.value = false; action.value = ''; }
    await feedback();
}
async function download(kind: 'template' | 'errors') {
    if (busy.value) return;
    busy.value = true; action.value = 'download'; error.value = '';
    try {
        const response = await api.get(kind === 'template' ? `${base}/template` : `${base}/${batch.value!.id}/errors`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data); const link = document.createElement('a');
        link.href = url; link.download = kind === 'template' ? 'plantilla-catalogo-bshop.csv' : `errores-${batch.value!.id}.csv`;
        document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch (e) { failed(e); }
    finally { busy.value = false; action.value = ''; }
}
onMounted(() => loadHistory());
</script>
<template>
    <button type="button" class="text-button" :disabled="busy" @click="emit('back')">← Volver al catálogo</button>
    <p v-if="error && !revertOpen" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" ref="statusNotice" tabindex="-1" class="notice" role="status">{{ notice }}</p>
    <section class="panel import-upload">
        <div class="panel-heading"><div><span class="eyebrow">CARGA REVISADA, SIN SORPRESAS</span><h2>Importar catálogo desde CSV</h2><p class="muted">Primero revisa; después confirma el lote completo. Se añaden productos nuevos y nunca se sobrescriben los existentes.</p></div><button class="button secondary" type="button" :disabled="busy" @click="download('template')">Descargar plantilla</button></div>
        <form class="form-stack" @submit.prevent="preview">
            <label>Archivo CSV<input ref="fileInput" type="file" accept=".csv,text/csv,text/plain" :disabled="busy" required @change="fileChanged"></label>
            <p class="muted">UTF-8, hasta 2 MiB y 500 filas. Coma o punto y coma. Un grupo identifica un producto con varias variantes; no se importa inventario.</p>
            <button type="submit" class="button primary" :disabled="busy || !selectedFile">{{ action === 'preview' ? 'Revisando archivo…' : 'Revisar archivo' }}</button>
        </form>
        <details class="import-help"><summary>Formato, grupos y referencias del catálogo</summary>
            <p>Obligatorias: <code>name</code>, <code>sku</code>, <code>sale_price</code>. Opcionales: product_key, cost_price, barcode, product_type, unit_code, track_stock, category_id, brand_id, attribute_value_ids, description, status y variant_active.</p>
            <p>Las filas con el mismo product_key se importan como variantes de un producto y deben repetir los mismos datos generales. Déjalo vacío para un producto por fila. Tipos: physical/service; booleanos 1/0. Precio sin miles, hasta cuatro decimales.</p>
            <p>En attribute_value_ids separa IDs con <code>|</code> (por ejemplo <code>1|3</code>). La categoría, marca y valores deben existir y estar activos. Conserva SKU/barras como texto en tu hoja para no perder ceros iniciales.</p>
            <div class="import-reference-grid"><div><strong>Categorías</strong><p v-for="item in options.categories.filter(c => c.is_active)" :key="item.id">{{ item.id }} · {{ item.name }}</p><strong>Marcas</strong><p v-for="item in options.brands.filter(b => b.is_active)" :key="item.id">{{ item.id }} · {{ item.name }}</p></div><div><template v-for="attribute in options.attributes.filter(a => a.is_active)" :key="attribute.id"><strong>{{ attribute.name }}</strong><p v-for="value in attribute.values.filter(v => v.is_active)" :key="value.id">{{ value.id }} · {{ value.value }}</p></template></div></div>
        </details>
    </section>
    <section v-if="batch" class="panel import-review" aria-label="Revisión de importación" :aria-busy="busy">
        <div class="panel-heading"><div><span class="eyebrow">{{ batch.filename }}</span><h2 ref="reviewHeading" tabindex="-1">Revisión del lote</h2><p class="muted">{{ batch.status === 'preview' ? `Válido hasta ${date(batch.expires_at)}` : batch.status === 'committed' ? `Importado ${date(batch.committed_at!)}` : `Revertido ${date(batch.reverted_at!)}` }}</p></div><span class="badge" :class="{ inactive: batch.error_count > 0 || batch.is_expired || batch.status === 'reverted' }">{{ status(batch, batch.is_expired) }}</span></div>
        <div class="import-metrics"><div><strong>{{ batch.row_count }}</strong><span>Filas / variantes</span></div><div><strong>{{ batch.product_count }}</strong><span>Productos</span></div><div><strong>{{ validRows }}</strong><span>Filas sin errores</span></div><div :class="{ 'has-errors': batch.error_count > 0 }"><strong>{{ batch.error_count }}</strong><span>Filas con errores</span></div></div>
        <p v-if="batch.error_count > 0" class="notice error">Corrige todas las filas antes de confirmar. No se importará sólo una parte. Puedes descargar los errores y subir un archivo corregido.</p>
        <p v-if="batch.is_expired" class="notice error">La vista previa venció. Sube otra vez el archivo para revisarlo.</p>
        <div v-if="batch.status === 'committed'" class="import-created"><strong>Productos añadidos</strong><button v-for="product in batch.products.slice(0, 20)" :key="product.id" type="button" class="text-button" :disabled="busy" @click="emit('product', product.id)">{{ product.name }} ↗</button><p v-if="batch.products.length > 20" class="muted">El resto está disponible en el catálogo.</p></div>
        <p v-if="batch.status === 'reverted'" class="notice">Los productos siguen disponibles con el filtro Archivados. No se eliminó ningún historial.</p>
        <div class="record-list import-rows"><article v-for="row in batch.rows" :key="row.row" class="record import-row"><div class="import-row-number">Fila {{ row.row }}</div><div class="record-main"><strong>{{ row.name || 'Sin nombre' }}</strong><p>SKU: {{ row.sku || 'Sin SKU' }} <span v-if="row.product_key">· Grupo: {{ row.product_key }}</span></p><p>Precio: {{ row.sale_price || 'Sin precio' }} {{ business.default_currency }} <span v-if="costs && row.cost_price != null">· Costo: {{ row.cost_price }}</span></p><ul v-if="row.errors.length" class="import-row-errors"><li v-for="(item, index) in row.errors" :key="index"><strong>{{ labels[item.field.split('.')[0]] || item.field }}:</strong> {{ item.message }}</li></ul></div><span class="badge" :class="{ inactive: row.errors.length > 0 }">{{ row.errors.length ? 'Revisar' : 'Válida' }}</span></article></div>
        <div v-if="batch.pagination.last_page > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="busy || batch.pagination.current_page <= 1" @click="open(batch.id, batch.pagination.current_page - 1)">Anterior</button><span>Página {{ batch.pagination.current_page }} de {{ batch.pagination.last_page }}</span><button type="button" class="button secondary" :disabled="busy || batch.pagination.current_page >= batch.pagination.last_page" @click="open(batch.id, batch.pagination.current_page + 1)">Siguiente</button></div>
        <div ref="actionbar" class="import-actionbar">
            <div class="import-action-summary"><strong>{{ batch.filename }}</strong><span>{{ batch.product_count }} productos · {{ batch.error_count }} filas con errores</span></div>
            <button type="button" class="button secondary" :disabled="busy" @click="open(batch.id, batch.pagination.current_page)">Actualizar estado</button>
            <button v-if="batch.status === 'preview' && !batch.is_expired" type="button" class="button secondary" :disabled="busy" @click="revalidate">Revalidar lote</button>
            <button v-if="batch.error_count > 0" type="button" class="button secondary" :disabled="busy" @click="download('errors')">Descargar errores CSV</button>
            <button v-if="batch.status === 'preview'" type="button" class="button primary" :disabled="busy || !batch.can_confirm" @click="confirm">{{ action === 'confirm' ? 'Importando lote…' : `Importar ${batch.product_count} productos` }}</button>
            <button v-if="batch.can_revert" type="button" class="button secondary" :disabled="busy" @click="requestRevert">Revertir importación</button>
        </div>
        <p v-if="action === 'confirm'" class="notice" role="status">El lote se confirma como una sola operación. Si se interrumpe la conexión, consulta su estado o reintenta el mismo lote; no se duplica.</p>
    </section>
    <section class="panel import-history"><div class="panel-heading"><div><span class="eyebrow">RECUPERAR Y COMPROBAR</span><h2>Mis importaciones recientes</h2><p class="muted">Las vistas previas y resultados quedan disponibles aunque salgas de esta pantalla.</p></div><button type="button" class="button secondary" :disabled="busy || historyLoading" @click="loadHistory(historyPage)">Actualizar historial</button></div><p v-if="historyError" class="notice error" role="alert">{{ historyError }}</p><p v-if="historyLoading" class="muted" role="status">Cargando historial…</p><article v-for="item in history" :key="item.id" class="record"><div class="record-main"><strong>{{ item.filename }}</strong><p>{{ date(item.created_at) }} · {{ item.product_count }} productos · {{ item.row_count }} filas</p></div><span class="badge" :class="{ inactive: item.error_count > 0 || item.status === 'reverted' }">{{ status(item) }}</span><button type="button" class="button secondary" :disabled="busy" @click="open(item.id)">Abrir<span class="sr-only"> {{ item.filename }}</span></button></article><p v-if="!history.length && !historyLoading" class="muted">Todavía no hay lotes para tu usuario.</p><div v-if="historyLast > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="busy || historyPage <= 1" @click="loadHistory(historyPage - 1)">Anterior</button><span>Página {{ historyPage }} de {{ historyLast }}</span><button type="button" class="button secondary" :disabled="busy || historyPage >= historyLast" @click="loadHistory(historyPage + 1)">Siguiente</button></div></section>
    <div v-if="batch" class="import-footer-space" :style="{ height: `${toolbarHeight}px` }" aria-hidden="true"></div>
    <AppDialog :open="revertOpen" title="Revertir importación" :busy="busy" :return-focus="opener" @close="revertOpen = false"><p>Se archivarán los {{ batch?.product_count }} productos creados por este lote y se conservará su historial.</p><p class="muted">Si alguno fue editado o usado en ventas, inventario o imágenes, se rechazará toda la reversión sin cambios.</p><p v-if="error" ref="revertError" tabindex="-1" class="notice error" role="alert">{{ error }}</p><template #footer><button type="button" class="button secondary" :disabled="busy" @click="revertOpen = false">Cancelar</button><button type="button" class="button primary" :disabled="busy" @click="revert">{{ action === 'revert' ? 'Revirtiendo…' : 'Archivar productos del lote' }}</button></template></AppDialog>
</template>
