<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Business, Store, Warehouse } from '../types';
import type { CatalogOptions, CatalogProduct, CatalogVariant, ProductImage, VariantPage } from '../catalog';
import { amount } from '../catalog';
import { quantity as fmtQty } from '../inventory';
import ProductEditor from './ProductEditor.vue';
import VariantEditor from './VariantEditor.vue';
import CatalogOptionsManager from './CatalogOptionsManager.vue';
import CatalogImportView from './CatalogImportView.vue';
import ProductGallery from './ProductGallery.vue';
import ProductBuyersModal from './ProductBuyersModal.vue';
import AppDialog from './AppDialog.vue';
import { forgetPhotos, photoRecoveries } from '../lib/productPhotoRecovery';

const props = defineProps<{ business: Business; permissions: string[]; actorId:number; store?:Store; warehouses:Warehouse[] }>();
const manage = computed(() => props.permissions.includes('catalog.manage'));
const costs = computed(() => props.permissions.includes('catalog.costs'));
const options = ref<CatalogOptions>({ categories: [], brands: [], attributes: [] });
const products = ref<CatalogProduct[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const query = ref(''); const category = ref<number | ''>(''); const brand = ref<number | ''>(''); const status = ref('active'); const type = ref('');
const loading = ref(true); const error = ref(''); const notice = ref(''); const organizing = ref(false);
const importing = ref(false);
const filtersOpen = ref(false);
const buyersOpen = ref(false);
const photosOpen = ref(false); const photoOpener = ref<HTMLElement | null>(null);
const photoReminders=ref(photoRecoveries(props.actorId));
function refreshPhotoReminders(){photoReminders.value=photoRecoveries(props.actorId);}
async function reviewPendingPhotos(id:number,event:Event){photoOpener.value=event.currentTarget as HTMLElement;await detail(id);if(selected.value?.id===id)photosOpen.value=true;}
function dismissPhotoReminder(id:number){try{forgetPhotos(props.actorId,id);refreshPhotoReminders();}catch{error.value='No se pudo actualizar el aviso en este navegador.';}}
function openPhotos(event: Event) { photoOpener.value = event.currentTarget as HTMLElement; photosOpen.value = true; }
async function photosChanged() { if (selected.value) await Promise.all([loadProducts(page.value), detail(selected.value.id, variants.value.current_page, true)]); }
const selected = ref<CatalogProduct | null>(null); const variants = ref<VariantPage>({ data: [], current_page: 1, last_page: 1, total: 0 }); const detailLoading = ref(false);
const productOpen = ref(false); const productDraft = ref<CatalogProduct | null>(null); const editorMode = ref<'single' | 'batch' | 'advanced'>('single'); const variantOpen = ref(false); const variantDraft = ref<CatalogVariant | null>(null); const opener = ref<HTMLElement | null>(null);
const deleteTarget = ref<CatalogProduct | null>(null);
const deleteOpener = ref<HTMLElement | null>(null);
const deleteBusy = ref(false);
const deleteError = ref('');
const deleteSalesCount = ref<number | null>(null);
const deleteSalesMessage = ref('');
function askDeleteProduct(product: CatalogProduct, event: Event) {
    deleteOpener.value = event.currentTarget as HTMLElement;
    deleteTarget.value = product;
    deleteError.value = '';
    deleteSalesCount.value = null;
    deleteSalesMessage.value = '';
}
function closeDeleteDialog() {
    if (deleteBusy.value) return;
    deleteTarget.value = null;
    deleteError.value = '';
    deleteSalesCount.value = null;
    deleteSalesMessage.value = '';
}
async function confirmDeleteProduct(confirmWithSales = false) {
    if (!deleteTarget.value || deleteBusy.value) return;
    deleteBusy.value = true;
    deleteError.value = '';
    try {
        const target = deleteTarget.value;
        await api.delete(`/business/products/${target.id}`, {
            data: confirmWithSales ? { confirm_with_sales: true } : {},
        });
        deleteTarget.value = null;
        deleteSalesCount.value = null;
        deleteSalesMessage.value = '';
        if (selected.value?.id === target.id) {
            selected.value = null;
        }
        notice.value = confirmWithSales
            ? 'Producto eliminado del catálogo. Su historial de ventas se conservó.'
            : 'Producto eliminado del catálogo.';
        await loadProducts(page.value);
    } catch (e) {
        if (axios.isAxiosError(e) && e.response?.status === 409 && e.response.data?.data?.requires_double_confirmation) {
            deleteSalesCount.value = Number(e.response.data.data.sales_records_count || 1);
            deleteSalesMessage.value = e.response.data.message || 'Este producto tiene ventas registradas.';
        } else {
            deleteError.value = errorMessage(e);
        }
    } finally {
        deleteBusy.value = false;
    }
}
let request = 0; let detailRequest = 0; let controller: AbortController | undefined; let searchTimer: ReturnType<typeof setTimeout> | undefined;
async function loadOptions() {
    try { options.value = (await api.get('/business/catalog/options')).data.data; }
    catch (e) { error.value = errorMessage(e); }
}
async function loadProducts(target = 1) {
    const version = ++request; controller?.abort(); controller = new AbortController();
    loading.value = true; error.value = '';
    try {
        const response = await api.get('/business/products', { signal: controller.signal, params: { page: target, q: query.value || undefined, category_id: category.value || undefined, brand_id: brand.value || undefined, status: status.value, product_type: type.value || undefined, store_id: props.store?.id || undefined } });
        if (version !== request) return;
        if (response.data.meta.current_page > response.data.meta.last_page) { await loadProducts(response.data.meta.last_page); return; }
        products.value = response.data.data; page.value = response.data.meta.current_page; lastPage.value = response.data.meta.last_page; total.value = response.data.meta.total;
    } catch (e) { if (version === request && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (version === request) loading.value = false; }
}
async function detail(id: number, target = 1, quiet = false) {
    const version = ++detailRequest; if (!quiet) detailLoading.value = true; error.value = '';
    try {
        const response = await api.get(`/business/products/${id}`, { params: { page: target, store_id: props.store?.id || undefined } });
        if (version !== detailRequest) return;
        selected.value = response.data.data.product; variants.value = response.data.data.variants;
    } catch (e) { if (version === detailRequest) error.value = errorMessage(e); }
    finally { if (version === detailRequest) detailLoading.value = false; }
}
function editProduct(product: CatalogProduct | null, event: Event, mode: 'single' | 'batch' | 'advanced' = 'single') { opener.value = event.currentTarget as HTMLElement; productDraft.value = product; editorMode.value = mode; productOpen.value = true; notice.value = ''; }
function editVariant(variant: CatalogVariant | null, event: Event) { opener.value = event.currentTarget as HTMLElement; variantDraft.value = variant; variantOpen.value = true; notice.value = ''; }
async function savedProduct(id: number, openDetail = true) {
    notice.value = 'Producto guardado correctamente.';
    if (openDetail || selected.value !== null) {
        await Promise.all([loadProducts(page.value), detail(id)]);
    } else {
        await loadProducts(1);
    }
}
const cardImageIndex = ref<Record<number, number>>({});
const detailImageIndex = ref(0);
const selectedVariantId = ref<number | null>(null);
let swipeStartX = 0;

function orderedImages(product: CatalogProduct): ProductImage[] {
    const imgs = (product.images || []).filter(img => img.renditions?.card?.url || img.renditions?.detail?.url || img.renditions?.thumb?.url);
    return [...imgs].sort((a, b) => (a.is_primary === b.is_primary ? a.sort_order - b.sort_order : a.is_primary ? -1 : 1));
}
function activeCardImage(product: CatalogProduct): ProductImage | null {
    const imgs = orderedImages(product);
    if (!imgs.length) return null;
    const idx = cardImageIndex.value[product.id] ?? 0;
    return imgs[((idx % imgs.length) + imgs.length) % imgs.length];
}
function activeCardIndex(product: CatalogProduct): number {
    const imgs = orderedImages(product);
    if (!imgs.length) return 0;
    const idx = cardImageIndex.value[product.id] ?? 0;
    return ((idx % imgs.length) + imgs.length) % imgs.length;
}
function stepCardImage(product: CatalogProduct, delta: number) {
    const imgs = orderedImages(product);
    if (imgs.length < 2) return;
    const next = (activeCardIndex(product) + delta + imgs.length) % imgs.length;
    cardImageIndex.value = { ...cardImageIndex.value, [product.id]: next };
}
function setCardImage(product: CatalogProduct, idx: number) {
    cardImageIndex.value = { ...cardImageIndex.value, [product.id]: idx };
}
function cardDisplayPrice(product: CatalogProduct): string {
    const img = activeCardImage(product);
    if (img?.variant_sale_price != null) {
        return amount(img.variant_sale_price, props.business.default_currency);
    }
    if (product.min_sale_price === null) return 'Sin variante activa';
    return `${product.min_sale_price !== product.max_sale_price ? 'Desde ' : ''}${amount(product.min_sale_price, props.business.default_currency)}`;
}
function cardDisplayVariantLabel(product: CatalogProduct): string {
    return activeCardImage(product)?.variant_label || product.variant_summary || '';
}
function cardAvailableStock(product: CatalogProduct): string | null {
    if (!product.track_stock) return null;
    const imgStock = activeCardImage(product)?.variant_available_stock;
    if (imgStock !== undefined && imgStock !== null) return imgStock;
    return product.available_stock ?? null;
}
function stockPillTone(stock: string | null | undefined): 'out' | 'low' | 'ok' {
    const n = Number(stock ?? 0);
    if (!Number.isFinite(n) || n <= 0) return 'out';
    if (n <= 2) return 'low';
    return 'ok';
}
function stockPillText(stock: string | null | undefined): string {
    const n = Number(stock ?? 0);
    if (!Number.isFinite(n) || n <= 0) return 'Agotado';
    return `${fmtQty(stock ?? '0')} disp.`;
}
function onCardTouchStart(e: TouchEvent) {
    swipeStartX = e.changedTouches[0]?.clientX ?? 0;
}
function onCardTouchEnd(product: CatalogProduct, e: TouchEvent) {
    const endX = e.changedTouches[0]?.clientX ?? 0;
    const diff = endX - swipeStartX;
    if (Math.abs(diff) >= 35) {
        stepCardImage(product, diff < 0 ? 1 : -1);
    }
}
const allDetailImages = computed(() => (selected.value ? orderedImages(selected.value) : []));
const detailImages = computed(() => {
    const all = allDetailImages.value;
    if (selectedVariantId.value === null) return all;
    const filtered = all.filter(img => img.variant_id === selectedVariantId.value);
    return filtered.length ? filtered : all;
});
const selectedVariantHasOwnPhotos = computed(() => {
    if (selectedVariantId.value === null) return true;
    return allDetailImages.value.some(img => img.variant_id === selectedVariantId.value);
});
const selectedDetailVariant = computed(() =>
    selectedVariantId.value === null ? null : (variants.value.data.find(v => v.id === selectedVariantId.value) ?? null)
);
const activeDetailImage = computed(() => {
    const imgs = detailImages.value;
    if (!imgs.length) return null;
    const idx = ((detailImageIndex.value % imgs.length) + imgs.length) % imgs.length;
    return imgs[idx];
});
function selectDetailVariant(variantId: number | null) {
    selectedVariantId.value = variantId;
    detailImageIndex.value = 0;
}
function variantPhotoCount(variantId: number): number {
    return allDetailImages.value.filter(img => img.variant_id === variantId).length;
}
function variantDisplayName(variant: CatalogVariant): string {
    return variant.attribute_values.map(v => `${v.attribute_name}: ${v.value}`).join(' · ') || variant.sku;
}
function stepDetailImage(delta: number) {
    const imgs = detailImages.value;
    if (imgs.length < 2) return;
    detailImageIndex.value = (detailImageIndex.value + delta + imgs.length) % imgs.length;
}
function onDetailTouchEnd(e: TouchEvent) {
    const endX = e.changedTouches[0]?.clientX ?? 0;
    const diff = endX - swipeStartX;
    if (Math.abs(diff) >= 35) stepDetailImage(diff < 0 ? 1 : -1);
}
watch(() => selected.value?.id, () => { detailImageIndex.value = 0; selectedVariantId.value = null; });
async function savedVariant() { if (!selected.value) return; notice.value = 'Variante guardada correctamente.'; await detail(selected.value.id, variants.value.current_page); await loadProducts(page.value); }
function back() { ++detailRequest; selected.value = null; detailLoading.value = false; error.value = ''; }
function openImport() { error.value = ''; notice.value = ''; importing.value = true; }
async function refreshCatalog() { notice.value = ''; await Promise.all([loadOptions(), loadProducts(page.value), ...(selected.value ? [detail(selected.value.id, variants.value.current_page)] : [])]); }
async function importChanged() { await Promise.all([loadProducts(1), ...(selected.value ? [detail(selected.value.id)] : [])]); }
watch(query, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadProducts(), 300); });
watch([category, brand, status, type], () => loadProducts());
watch(() => props.store?.id, (newId, oldId) => {
    if (newId && oldId && newId !== oldId) {
        selected.value = null;
        loadProducts(1);
    }
});
watch(() => props.store?.id, () => { loadProducts(page.value); if (selected.value) detail(selected.value.id, variants.value.current_page, true); });
onMounted(() => { loadOptions(); loadProducts(); });
onUnmounted(() => { ++request; ++detailRequest; controller?.abort(); clearTimeout(searchTimer); });
</script>
<template>
    <p v-if="error" class="notice error" role="alert">{{ error }} <button type="button" class="text-button" @click="selected ? detail(selected.id) : loadProducts(page)">Reintentar</button></p>
    <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    <div v-for="item in photoReminders" :key="item.productId" class="notice" role="status">El producto {{ item.name || `#${item.productId}` }} se guardó; revisa qué fotos ya se publicaron antes de volver a subir las pendientes. <button type="button" class="button secondary" @click="reviewPendingPhotos(item.productId,$event)">Revisar fotos</button> <button type="button" class="button secondary" @click="dismissPhotoReminder(item.productId)">Aviso revisado</button></div>
    <CatalogImportView v-if="importing && manage" :business="business" :options="options" :costs="costs" @back="importing = false" @changed="importChanged" @product="id => { importing = false; detail(id); }" />
    <CatalogOptionsManager v-else-if="organizing && manage" :options="options" @back="organizing = false" @changed="loadOptions" />
    <template v-else>
        <div class="cat-top-header">
            <div class="cat-top-title">
                <span class="eyebrow">PRODUCTOS Y SERVICIOS</span>
                <p class="muted">{{ store?.name || business.name }} · Catálogo del negocio</p>
            </div>
            <div class="cat-primary-ctas" v-if="manage">
                <button type="button" class="button primary" @click="editProduct(null, $event, 'single')">+ Nuevo producto</button>
                <button type="button" class="button soft-purple" @click="editProduct(null, $event, 'batch')">+ Varias fotos</button>
            </div>
            <div class="cat-secondary-tools">
                <button type="button" class="tool-pill" :disabled="loading || detailLoading" @click="refreshCatalog">Actualizar</button>
                <button v-if="manage" type="button" class="tool-pill" @click="organizing = true">Organizar catálogo</button>
                <button v-if="manage" type="button" class="tool-pill" @click="openImport">Importar CSV</button>
            </div>
        </div>
        <template v-if="selected || detailLoading">
            <button type="button" class="text-button" @click="back">← Volver a productos</button>
            <p v-if="detailLoading" class="notice" role="status">Cargando producto…</p>
            <section v-else-if="selected" class="panel">
                <div class="detail-hero">
                    <div class="detail-gallery" @touchstart.passive="onCardTouchStart" @touchend.passive="onDetailTouchEnd">
                        <div class="detail-photo-stage">
                            <img
                                v-if="activeDetailImage"
                                :src="activeDetailImage.renditions.detail?.url || activeDetailImage.renditions.card?.url || undefined"
                                :alt="activeDetailImage.alt_text || selected.name"
                                class="detail-main-img"
                            >
                            <div v-else class="detail-photo-empty">Sin fotos aún</div>
                            <button
                                v-if="detailImages.length > 1"
                                type="button"
                                class="slide-arrow slide-prev"
                                aria-label="Foto anterior"
                                @click="stepDetailImage(-1)"
                            >‹</button>
                            <button
                                v-if="detailImages.length > 1"
                                type="button"
                                class="slide-arrow slide-next"
                                aria-label="Foto siguiente"
                                @click="stepDetailImage(1)"
                            >›</button>
                            <span v-if="detailImages.length > 1" class="slide-counter">{{ ((detailImageIndex % detailImages.length) + detailImages.length) % detailImages.length + 1 }} / {{ detailImages.length }}</span>
                        </div>
                        <div v-if="detailImages.length > 1" class="detail-thumbs" role="list" aria-label="Miniaturas de fotos">
                            <button
                                v-for="(img, idx) in detailImages"
                                :key="img.id"
                                type="button"
                                class="detail-thumb-btn"
                                :class="{ active: idx === ((detailImageIndex % detailImages.length) + detailImages.length) % detailImages.length }"
                                :aria-label="`Ver foto ${idx + 1}`"
                                @click="detailImageIndex = idx"
                            >
                                <img :src="img.renditions.thumb?.url || img.renditions.card?.url || undefined" alt="">
                            </button>
                        </div>
                        <button type="button" class="button secondary" @click="openPhotos">{{ manage ? 'Administrar fotos' : 'Ver fotos' }} ({{ selected.images?.length || 0 }} / 20)</button>
                    </div>
                    <div class="detail-summary">
                        <div class="panel-heading">
                            <div>
                                <span class="eyebrow">{{ selected.category?.name || 'SIN CATEGORÍA' }} <span v-if="selected.brand">· {{ selected.brand.name }}</span></span>
                                <h2>{{ selected.name }}</h2>
                            </div>
                            <div class="detail-actions">
                                <button
                                    v-if="store && permissions.includes('sales.view') && permissions.includes('customers.view')"
                                    type="button"
                                    class="button secondary"
                                    @click="buyersOpen = true"
                                >
                                    Ver compradores
                                </button>
                                <button v-if="manage" type="button" class="button secondary" @click="editProduct(selected, $event)">Editar producto</button>
                                <button v-if="manage" type="button" class="button danger-outline" @click="askDeleteProduct(selected, $event)">Eliminar</button>
                            </div>
                        </div>
                        <p v-if="selected.description" class="muted">{{ selected.description }}</p>
                        <div class="detail-price-box">
                            <strong class="detail-active-price">
                                {{ selectedDetailVariant
                                    ? amount(selectedDetailVariant.sale_price, business.default_currency)
                                    : (activeDetailImage?.variant_sale_price != null
                                        ? amount(activeDetailImage.variant_sale_price, business.default_currency)
                                        : (selected.min_sale_price === null ? 'Sin precio' : `${selected.min_sale_price !== selected.max_sale_price ? 'Desde ' : ''}${amount(selected.min_sale_price, business.default_currency)}`)) }}
                            </strong>
                            <span v-if="selectedDetailVariant" class="badge">{{ variantDisplayName(selectedDetailVariant) }}</span>
                            <span v-else-if="activeDetailImage?.variant_label" class="badge">{{ activeDetailImage.variant_label }}</span>
                            <span
                                v-if="selected.track_stock && (selectedDetailVariant?.available_stock !== undefined || activeDetailImage?.variant_available_stock !== undefined || selected.available_stock !== undefined)"
                                class="cat-stock-pill"
                                :class="stockPillTone(selectedDetailVariant?.available_stock ?? activeDetailImage?.variant_available_stock ?? selected.available_stock)"
                            >{{ stockPillText(selectedDetailVariant?.available_stock ?? activeDetailImage?.variant_available_stock ?? selected.available_stock) }}</span>
                            <span v-if="selected.status === 'archived'" class="badge inactive">Archivado</span>
                        </div>
                        <div v-if="variants.data.length > 1" class="variant-filter-pills" role="group" aria-label="Filtrar fotos por variante">
                            <button
                                type="button"
                                class="variant-pill"
                                :class="{ active: selectedVariantId === null }"
                                @click="selectDetailVariant(null)"
                            >Todas las fotos ({{ allDetailImages.length }})</button>
                            <button
                                v-for="variant in variants.data"
                                :key="variant.id"
                                type="button"
                                class="variant-pill"
                                :class="{ active: selectedVariantId === variant.id }"
                                @click="selectDetailVariant(variant.id)"
                            >{{ variantDisplayName(variant) }} ({{ variantPhotoCount(variant.id) }})</button>
                        </div>
                        <p v-if="selectedDetailVariant && !selectedVariantHasOwnPhotos" class="muted">Esta variante todavía no tiene fotos propias asignadas; mostrando las fotos generales del producto.</p>
                    </div>
                </div>
                <div class="panel-heading catalog-section-heading"><div><h3>Variantes y precios</h3><p class="muted">{{ variants.total }} {{ variants.total === 1 ? 'variante' : 'variantes' }}</p></div><button v-if="manage" type="button" class="button primary" :disabled="selected.status !== 'active'" @click="editVariant(null, $event)">+ Nueva variante</button></div>
                <div class="record-list"><article v-for="variant in variants.data" :key="variant.id" class="record catalog-variant-record" :class="{ 'variant-selected': selectedVariantId === variant.id }" @click="selectDetailVariant(variant.id)"><div class="record-main"><strong>{{ variant.attribute_values.map(v => `${v.attribute_name}: ${v.value}`).join(' · ') || 'Variante general' }}</strong><p class="muted">{{ variant.sku }}<span v-if="variant.barcode"> · Barras: {{ variant.barcode }}</span> · {{ variantPhotoCount(variant.id) }} {{ variantPhotoCount(variant.id) === 1 ? 'foto' : 'fotos' }}</p></div><div class="variant-price"><strong>{{ amount(variant.sale_price, business.default_currency) }}</strong><small v-if="costs">Costo: {{ amount(variant.cost_price, business.default_currency) }}</small><span v-if="selected.track_stock && variant.available_stock !== undefined && variant.available_stock !== null" class="cat-stock-pill" :class="stockPillTone(variant.available_stock)">{{ stockPillText(variant.available_stock) }}</span><span class="badge" :class="{ inactive: !variant.is_active }">{{ variant.is_active ? 'Activa' : 'Inactiva' }}</span></div><button v-if="manage" type="button" class="button secondary" @click.stop="editVariant(variant, $event)">Editar<span class="sr-only"> {{ variant.sku }}</span></button></article></div>
                <p v-if="!variants.data.length" class="empty">No hay variantes para mostrar.</p>
                <div v-if="variants.last_page > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="variants.current_page <= 1" @click="detail(selected.id, variants.current_page - 1)">Anterior</button><span>Página {{ variants.current_page }} de {{ variants.last_page }}</span><button type="button" class="button secondary" :disabled="variants.current_page >= variants.last_page" @click="detail(selected.id, variants.current_page + 1)">Siguiente</button></div>
            </section>
        </template>
        <template v-else>
            <section class="cat-filter-shell" aria-label="Filtros de productos">
                <div class="cat-search-bar">
                    <label class="cat-search-input">
                        <span class="sr-only">Buscar producto</span>
                        <input v-model="query" type="search" maxlength="120" placeholder="Buscar producto, SKU o código de barras" enterkeyhint="search" autocomplete="off" autocorrect="off" aria-label="Buscar producto">
                    </label>
                    <button type="button" class="cat-filter-toggle" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">
                        Filtros {{ filtersOpen ? '▴' : '▾' }}
                    </button>
                </div>
                <div v-if="options.categories.length" class="cat-quick-chips" role="group" aria-label="Categorías rápidas">
                    <button type="button" class="cat-chip" :class="{ active: category === '' }" @click="category = ''">Todas</button>
                    <button
                        v-for="item in options.categories.filter(c => c.is_active)"
                        :key="item.id"
                        type="button"
                        class="cat-chip"
                        :class="{ active: category === item.id }"
                        @click="category = item.id"
                    >{{ item.name }}</button>
                </div>
                <div class="panel catalog-filters cat-adv-filters" :class="{ open: filtersOpen }">
                    <label>Categoría<select v-model="category"><option value="">Todas las categorías</option><option v-for="item in options.categories" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactiva' }}</option></select></label>
                    <label>Marca<select v-model="brand"><option value="">Todas las marcas</option><option v-for="item in options.brands" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactiva' }}</option></select></label>
                    <label>Tipo<select v-model="type"><option value="">Todos los tipos</option><option value="physical">Productos físicos</option><option value="service">Servicios</option></select></label>
                    <label v-if="manage">Estado<select v-model="status"><option value="active">Activos</option><option value="archived">Archivados</option><option value="all">Todos</option></select></label>
                </div>
            </section>
            <p v-if="loading" class="notice" role="status">Buscando productos…</p>
            <template v-else>
                <div class="catalog-results"><strong>{{ total }} {{ total === 1 ? 'producto' : 'productos' }}</strong><span class="muted">Toca un producto para ver detalles.</span></div>
                <section class="product-grid" aria-label="Catálogo de productos">
                    <article v-for="product in products" :key="product.id" class="product-card product-card-carousel">
                        <div class="product-visual product-visual-carousel" :class="{ service: product.product_type === 'service' }" @touchstart.passive="onCardTouchStart" @touchend.passive="onCardTouchEnd(product, $event)">
                            <img
                                v-if="activeCardImage(product)"
                                :src="activeCardImage(product)?.renditions.card?.url || activeCardImage(product)?.renditions.detail?.url || undefined"
                                :alt="activeCardImage(product)?.alt_text || product.name"
                                loading="lazy"
                                class="product-card-photo"
                                @click="detail(product.id)"
                            >
                            <span v-else aria-hidden="true" @click="detail(product.id)">{{ product.product_type === 'service' ? '✦' : product.name.slice(0, 1).toUpperCase() }}</span>
                            <span class="card-cat-badge">{{ product.category?.name || 'Sin categoría' }}</span>
                            <span v-if="product.status === 'archived'" class="card-arch-badge">Archivado</span>
                            <template v-if="orderedImages(product).length > 1">
                                <button type="button" class="slide-arrow slide-prev" :aria-label="`Foto anterior de ${product.name}`" @click.stop="stepCardImage(product, -1)">‹</button>
                                <button type="button" class="slide-arrow slide-next" :aria-label="`Foto siguiente de ${product.name}`" @click.stop="stepCardImage(product, 1)">›</button>
                                <span class="slide-counter">{{ activeCardIndex(product) + 1 }} / {{ orderedImages(product).length }}</span>
                                <div class="slide-dots" role="group" :aria-label="`Fotos de ${product.name}`">
                                    <button
                                        v-for="(img, idx) in orderedImages(product)"
                                        :key="img.id"
                                        type="button"
                                        class="slide-dot"
                                        :class="{ active: idx === activeCardIndex(product) }"
                                        :aria-label="`Foto ${idx + 1} de ${product.name}`"
                                        @click.stop="setCardImage(product, idx)"
                                    ></button>
                                </div>
                            </template>
                        </div>
                        <div class="product-card-bottom">
                            <button type="button" class="product-card-body product-card-open" :aria-label="`Ver ${product.name}`" @click="detail(product.id)">
                                <h2 class="product-card-title">{{ product.name }}</h2>
                                <p v-if="cardDisplayVariantLabel(product)" class="product-variant-tag">{{ cardDisplayVariantLabel(product) }}</p>
                                <strong class="product-price">{{ cardDisplayPrice(product) }}</strong>
                                <div class="product-meta-row">
                                    <span
                                        v-if="cardAvailableStock(product) !== null"
                                        class="cat-stock-pill"
                                        :class="stockPillTone(cardAvailableStock(product))"
                                    >{{ stockPillText(cardAvailableStock(product)) }}</span>
                                    <span v-if="product.active_variants_count > 1" class="muted product-vars-count">{{ product.active_variants_count }} variantes</span>
                                </div>
                            </button>
                            <button
                                v-if="manage"
                                type="button"
                                class="card-delete-btn"
                                :aria-label="`Eliminar ${product.name}`"
                                title="Eliminar producto"
                                @click.stop="askDeleteProduct(product, $event)"
                            >🗑</button>
                        </div>
                    </article>
                </section>
                <section v-if="!products.length" class="panel empty"><h3>{{ query || category || brand || type || status !== 'active' ? 'No encontramos coincidencias.' : 'Tu catálogo empieza aquí.' }}</h3><p>{{ manage ? 'Añade un producto con su SKU y precio, o ajusta los filtros.' : 'No hay productos disponibles con estos filtros.' }}</p></section>
                <div v-if="products.length" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1" @click="loadProducts(page - 1)">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage" @click="loadProducts(page + 1)">Siguiente</button></div>
            </template>
        </template>
    </template>
    <ProductEditor v-if="manage" :open="productOpen" :product="productDraft" :initial-mode="editorMode" :options="options" :costs="costs" :opener="opener" :actor-id="actorId" :store="store" :warehouses="warehouses" :inventory-manage="permissions.includes('inventory.manage')" @close="productOpen = false" @saved="savedProduct" @photo-recovery="refreshPhotoReminders" />
    <VariantEditor v-if="manage" :open="variantOpen" :product="selected" :variant="variantDraft" :options="options" :costs="costs" :opener="opener" :store="store" :warehouses="warehouses" :inventory-manage="permissions.includes('inventory.manage')" @close="variantOpen = false" @saved="savedVariant" />
    <ProductGallery :open="photosOpen" :product="selected" :variants="variants.data" :manage="manage" :opener="photoOpener" @close="photosOpen = false" @changed="photosChanged" />
    <AppDialog
        v-if="manage"
        :open="deleteTarget !== null"
        :title="deleteSalesCount !== null ? 'Confirmar eliminación con ventas' : 'Eliminar producto'"
        :busy="deleteBusy"
        :opener="deleteOpener"
        @close="closeDeleteDialog"
    >
        <div v-if="deleteTarget" class="delete-confirm-body">
            <template v-if="deleteSalesCount === null">
                <p>¿Seguro que deseas eliminar <strong>{{ deleteTarget.name }}</strong> del catálogo?</p>
                <p class="muted">Si el producto ya tiene ventas o pedidos registrados, te avisaremos antes de eliminarlo.</p>
            </template>
            <template v-else>
                <p class="notice error" role="alert">
                    <strong>Atención: este producto tiene {{ deleteSalesCount }} {{ deleteSalesCount === 1 ? 'registro de venta o pedido' : 'registros de venta o pedidos' }}.</strong>
                </p>
                <p>{{ deleteSalesMessage }}</p>
                <p class="muted">Si confirmas de nuevo, el producto saldrá del catálogo activo y no se podrá vender más, pero las facturas y reportes históricos se conservarán intactos.</p>
            </template>
            <p v-if="deleteError" class="notice error" role="alert">{{ deleteError }}</p>
        </div>
        <template #footer>
            <button type="button" class="button secondary" :disabled="deleteBusy" @click="closeDeleteDialog">Cancelar</button>
            <button
                v-if="deleteSalesCount === null"
                type="button"
                class="button danger-solid"
                :disabled="deleteBusy"
                @click="confirmDeleteProduct(false)"
            >{{ deleteBusy ? 'Verificando…' : 'Sí, eliminar' }}</button>
            <button
                v-else
                type="button"
                class="button danger-solid"
                :disabled="deleteBusy"
                @click="confirmDeleteProduct(true)"
            >{{ deleteBusy ? 'Eliminando…' : 'Confirmar eliminación definitiva' }}</button>
        </template>
    </AppDialog>
    <ProductBuyersModal
        v-if="store && selected"
        :open="buyersOpen"
        :store-id="store.id"
        :initial-product="{ id: selected.id, name: selected.name }"
        :currency="business.default_currency"
        @close="buyersOpen = false"
    />
</template>

<style scoped>
.cat-top-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 10px 14px;
    margin-bottom: 14px;
}
.cat-top-title p {
    margin: 2px 0 0;
}
.cat-primary-ctas {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.cat-primary-ctas .button {
    min-height: 44px;
    white-space: nowrap;
}
.soft-purple {
    background: #f2edff;
    border: 1px solid #d8c8fb;
    color: #5230b8;
}
.cat-secondary-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.tool-pill {
    min-height: 36px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #e2dcf0;
    background: #fff;
    color: #4b425e;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.cat-filter-shell {
    display: grid;
    gap: 10px;
    margin-bottom: 14px;
}
.cat-search-bar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
}
.cat-search-input {
    display: block;
    min-width: 0;
}
.cat-search-input input {
    width: 100%;
    min-height: 44px;
}
.cat-filter-toggle {
    min-height: 44px;
    padding: 0 14px;
    border-radius: 12px;
    border: 1px solid #e2dcf0;
    background: #fff;
    color: #241b35;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    white-space: nowrap;
}
.cat-quick-chips {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding-bottom: 2px;
}
.cat-chip {
    flex: 0 0 auto;
    min-height: 34px;
    padding: 5px 12px;
    border-radius: 999px;
    border: 1px solid #e2dcf0;
    background: #fff;
    color: #2b223d;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.cat-chip.active {
    background: #1f1730;
    border-color: #1f1730;
    color: #fff;
}
.cat-adv-filters {
    margin-bottom: 0;
}
.product-card-carousel {
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow: hidden;
    text-align: left;
    border-radius: 14px;
}
.product-visual-carousel {
    position: relative;
    height: auto;
    aspect-ratio: 4 / 5;
    width: 100%;
    overflow: hidden;
    cursor: pointer;
    user-select: none;
    background: #f3f0fa;
}
.product-card-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.card-cat-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    max-width: calc(100% - 64px);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.92);
    color: #1f1730;
    font-size: 0.72rem;
    font-weight: 700;
    pointer-events: none;
}
.card-arch-badge {
    position: absolute;
    bottom: 8px;
    left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(180, 35, 24, 0.85);
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    pointer-events: none;
}
.product-card-bottom {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 6px;
    padding: 10px 10px 11px;
    flex: 1;
}
.product-card-open {
    display: grid;
    gap: 4px;
    flex: 1 1 auto;
    min-width: 0;
    padding: 0;
    border: 0;
    background: transparent;
    text-align: left;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.product-card-title {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.25;
    font-weight: 700;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.product-variant-tag {
    margin: 0;
    font-size: 0.76rem;
    font-weight: 600;
    color: #5b3fb3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.product-price {
    font-size: 1rem;
    font-weight: 800;
    white-space: nowrap;
}
.product-vars-count {
    margin: 0;
    font-size: 0.74rem;
    white-space: nowrap;
}
.product-meta-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px 8px;
    margin-top: 2px;
}
.cat-stock-pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    line-height: 1.3;
    white-space: nowrap;
}
.cat-stock-pill.ok {
    background: #eaf9f0;
    color: #127345;
}
.cat-stock-pill.low {
    background: #fff6e5;
    color: #a85c00;
}
.cat-stock-pill.out {
    background: #ffefef;
    color: #b42318;
}
.card-delete-btn {
    flex: 0 0 auto;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: #fff1f1;
    color: #b42318;
    font-size: 0.88rem;
    display: grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
}
@media (max-width: 767px) {
    .cat-top-title {
        display: none;
    }
    .cat-primary-ctas {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .cat-secondary-tools {
        width: 100%;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 2px;
    }
    .cat-adv-filters:not(.open) {
        display: none;
    }
    .product-card-title {
        font-size: 0.88rem;
    }
    .product-price {
        font-size: 0.95rem;
    }
}
@media (min-width: 768px) {
    .cat-filter-toggle {
        display: none;
    }
}
.slide-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.85);
    background: rgba(24, 18, 38, 0.62);
    color: #fff;
    font-size: 20px;
    line-height: 1;
    display: grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
}
.slide-arrow:hover {
    background: rgba(24, 18, 38, 0.85);
}
.slide-prev {
    left: 8px;
}
.slide-next {
    right: 8px;
}
.slide-counter {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(24, 18, 38, 0.68);
    color: #fff;
    font-size: 0.76rem;
    font-weight: 600;
    white-space: nowrap;
}
.slide-dots {
    position: absolute;
    left: 50%;
    bottom: 8px;
    transform: translateX(-50%);
    display: inline-flex;
    gap: 5px;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(24, 18, 38, 0.48);
}
.slide-dot {
    width: 8px;
    height: 8px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.5);
    cursor: pointer;
}
.slide-dot.active {
    background: #fff;
    transform: scale(1.2);
}
.detail-hero {
    display: grid;
    grid-template-columns: minmax(260px, 380px) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
    margin-bottom: 18px;
}
.detail-gallery {
    display: grid;
    gap: 10px;
}
.detail-photo-stage {
    position: relative;
    height: 280px;
    border-radius: 14px;
    border: 1px solid #e6dff2;
    background: #f7f5fa;
    overflow: hidden;
    display: grid;
    place-items: center;
}
.detail-main-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.detail-photo-empty {
    color: #6f687a;
    font-size: 0.95rem;
}
.detail-thumbs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.detail-thumb-btn {
    width: 56px;
    height: 56px;
    padding: 2px;
    border-radius: 10px;
    border: 2px solid #dfd7ef;
    background: #fff;
    cursor: pointer;
    overflow: hidden;
}
.detail-thumb-btn.active {
    border-color: #6a46d8;
}
.detail-thumb-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 6px;
}
.detail-summary {
    display: grid;
    gap: 10px;
    align-content: start;
}
.detail-price-box {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #f7f5fa;
    border: 1px solid #e5dcf3;
}
.detail-active-price {
    font-size: 1.45rem;
    font-weight: 700;
    color: #1f1730;
    white-space: nowrap;
}
.detail-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.product-card-footer {
    display: flex;
    justify-content: flex-end;
    padding: 0 14px 12px;
}
.danger-outline {
    border: 1px solid #e2a4a4;
    background: #fff5f5;
    color: #b42318;
}
.danger-solid {
    border: 1px solid #b42318;
    background: #d92d20;
    color: #fff;
}
.danger-text {
    color: #b42318;
    font-size: 0.85rem;
}
.delete-confirm-body {
    display: grid;
    gap: 10px;
}
.delete-confirm-body p {
    margin: 0;
}
.variant-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.variant-pill {
    min-height: 38px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #d5c9ea;
    background: #fff;
    color: #322745;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
}
.variant-pill.active {
    background: #6a46d8;
    border-color: #6a46d8;
    color: #fff;
}
.catalog-variant-record {
    cursor: pointer;
}
.catalog-variant-record.variant-selected {
    border-color: #6a46d8;
    background: #f6f2ff;
}
@media (max-width: 760px) {
    .detail-hero {
        grid-template-columns: minmax(0, 1fr);
    }
    .detail-photo-stage {
        height: 240px;
    }
}
</style>
