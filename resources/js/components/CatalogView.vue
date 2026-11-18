<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Business, Store, Warehouse } from '../types';
import type { CatalogOptions, CatalogProduct, CatalogVariant, ProductImage, VariantPage } from '../catalog';
import { amount } from '../catalog';
import ProductEditor from './ProductEditor.vue';
import VariantEditor from './VariantEditor.vue';
import CatalogOptionsManager from './CatalogOptionsManager.vue';
import CatalogImportView from './CatalogImportView.vue';
import ProductGallery from './ProductGallery.vue';
import { forgetPhotos, photoRecoveries } from '../lib/productPhotoRecovery';

const props = defineProps<{ business: Business; permissions: string[]; actorId:number; store?:Store; warehouses:Warehouse[] }>();
const manage = computed(() => props.permissions.includes('catalog.manage'));
const costs = computed(() => props.permissions.includes('catalog.costs'));
const options = ref<CatalogOptions>({ categories: [], brands: [], attributes: [] });
const products = ref<CatalogProduct[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const query = ref(''); const category = ref<number | ''>(''); const brand = ref<number | ''>(''); const status = ref('active'); const type = ref('');
const loading = ref(true); const error = ref(''); const notice = ref(''); const organizing = ref(false);
const importing = ref(false);
const photosOpen = ref(false); const photoOpener = ref<HTMLElement | null>(null);
const photoReminders=ref(photoRecoveries(props.actorId));
function refreshPhotoReminders(){photoReminders.value=photoRecoveries(props.actorId);}
async function reviewPendingPhotos(id:number,event:Event){photoOpener.value=event.currentTarget as HTMLElement;await detail(id);if(selected.value?.id===id)photosOpen.value=true;}
function dismissPhotoReminder(id:number){try{forgetPhotos(props.actorId,id);refreshPhotoReminders();}catch{error.value='No se pudo actualizar el aviso en este navegador.';}}
function openPhotos(event: Event) { photoOpener.value = event.currentTarget as HTMLElement; photosOpen.value = true; }
async function photosChanged() { if (selected.value) await Promise.all([loadProducts(page.value), detail(selected.value.id, variants.value.current_page, true)]); }
const selected = ref<CatalogProduct | null>(null); const variants = ref<VariantPage>({ data: [], current_page: 1, last_page: 1, total: 0 }); const detailLoading = ref(false);
const productOpen = ref(false); const productDraft = ref<CatalogProduct | null>(null); const editorMode = ref<'single' | 'batch' | 'advanced'>('single'); const variantOpen = ref(false); const variantDraft = ref<CatalogVariant | null>(null); const opener = ref<HTMLElement | null>(null);
let request = 0; let detailRequest = 0; let controller: AbortController | undefined; let searchTimer: ReturnType<typeof setTimeout> | undefined;
async function loadOptions() {
    try { options.value = (await api.get('/business/catalog/options')).data.data; }
    catch (e) { error.value = errorMessage(e); }
}
async function loadProducts(target = 1) {
    const version = ++request; controller?.abort(); controller = new AbortController();
    loading.value = true; error.value = '';
    try {
        const response = await api.get('/business/products', { signal: controller.signal, params: { page: target, q: query.value || undefined, category_id: category.value || undefined, brand_id: brand.value || undefined, status: status.value, product_type: type.value || undefined } });
        if (version !== request) return;
        if (response.data.meta.current_page > response.data.meta.last_page) { await loadProducts(response.data.meta.last_page); return; }
        products.value = response.data.data; page.value = response.data.meta.current_page; lastPage.value = response.data.meta.last_page; total.value = response.data.meta.total;
    } catch (e) { if (version === request && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (version === request) loading.value = false; }
}
async function detail(id: number, target = 1, quiet = false) {
    const version = ++detailRequest; if (!quiet) detailLoading.value = true; error.value = '';
    try {
        const response = await api.get(`/business/products/${id}`, { params: { page: target } });
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
    return activeCardImage(product)?.variant_label || '';
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
const detailImages = computed(() => (selected.value ? orderedImages(selected.value) : []));
const activeDetailImage = computed(() => {
    const imgs = detailImages.value;
    if (!imgs.length) return null;
    const idx = ((detailImageIndex.value % imgs.length) + imgs.length) % imgs.length;
    return imgs[idx];
});
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
watch(() => selected.value?.id, () => { detailImageIndex.value = 0; });
async function savedVariant() { if (!selected.value) return; notice.value = 'Variante guardada correctamente.'; await detail(selected.value.id, variants.value.current_page); await loadProducts(page.value); }
function back() { ++detailRequest; selected.value = null; detailLoading.value = false; error.value = ''; }
function openImport() { error.value = ''; notice.value = ''; importing.value = true; }
async function refreshCatalog() { notice.value = ''; await Promise.all([loadOptions(), loadProducts(page.value), ...(selected.value ? [detail(selected.value.id, variants.value.current_page)] : [])]); }
async function importChanged() { await Promise.all([loadProducts(1), ...(selected.value ? [detail(selected.value.id)] : [])]); }
watch(query, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadProducts(), 300); });
watch([category, brand, status, type], () => loadProducts());
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
        <div class="catalog-actions"><div><span class="eyebrow">PRODUCTOS Y SERVICIOS</span><p class="muted">{{ business.name }} · Catálogo del negocio</p></div><div class="actions"><button type="button" class="button secondary" :disabled="loading || detailLoading" @click="refreshCatalog">Actualizar</button><button v-if="manage" type="button" class="button secondary" @click="organizing = true">Organizar catálogo</button><button v-if="manage" type="button" class="button secondary" @click="openImport">Importar CSV</button><button v-if="manage" type="button" class="button secondary" @click="editProduct(null, $event, 'batch')">+ Subir hasta 10 fotos</button><button v-if="manage" type="button" class="button primary" @click="editProduct(null, $event, 'single')">+ Nuevo producto</button></div></div>
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
                        <button type="button" class="button secondary" @click="openPhotos">{{ manage ? 'Administrar fotos' : 'Ver fotos' }} ({{ selected.images?.length || 0 }} / 5)</button>
                    </div>
                    <div class="detail-summary">
                        <div class="panel-heading">
                            <div>
                                <span class="eyebrow">{{ selected.category?.name || 'SIN CATEGORÍA' }} <span v-if="selected.brand">· {{ selected.brand.name }}</span></span>
                                <h2>{{ selected.name }}</h2>
                            </div>
                            <button v-if="manage" type="button" class="button secondary" @click="editProduct(selected, $event)">Editar producto</button>
                        </div>
                        <p v-if="selected.description" class="muted">{{ selected.description }}</p>
                        <div class="detail-price-box">
                            <strong class="detail-active-price">
                                {{ activeDetailImage?.variant_sale_price != null
                                    ? amount(activeDetailImage.variant_sale_price, business.default_currency)
                                    : (selected.min_sale_price === null ? 'Sin precio' : `${selected.min_sale_price !== selected.max_sale_price ? 'Desde ' : ''}${amount(selected.min_sale_price, business.default_currency)}`) }}
                            </strong>
                            <span v-if="activeDetailImage?.variant_label" class="badge">{{ activeDetailImage.variant_label }}</span>
                            <span v-if="selected.status === 'archived'" class="badge inactive">Archivado</span>
                        </div>
                    </div>
                </div>
                <div class="panel-heading catalog-section-heading"><div><h3>Variantes y precios</h3><p class="muted">{{ variants.total }} {{ variants.total === 1 ? 'variante' : 'variantes' }}</p></div><button v-if="manage" type="button" class="button primary" :disabled="selected.status !== 'active'" @click="editVariant(null, $event)">+ Nueva variante</button></div>
                <div class="record-list"><article v-for="variant in variants.data" :key="variant.id" class="record catalog-variant-record"><div class="record-main"><strong>{{ variant.attribute_values.map(v => `${v.attribute_name}: ${v.value}`).join(' · ') || 'Variante general' }}</strong><p class="muted">{{ variant.sku }}<span v-if="variant.barcode"> · Barras: {{ variant.barcode }}</span></p></div><div class="variant-price"><strong>{{ amount(variant.sale_price, business.default_currency) }}</strong><small v-if="costs">Costo: {{ amount(variant.cost_price, business.default_currency) }}</small><span class="badge" :class="{ inactive: !variant.is_active }">{{ variant.is_active ? 'Activa' : 'Inactiva' }}</span></div><button v-if="manage" type="button" class="button secondary" @click="editVariant(variant, $event)">Editar<span class="sr-only"> {{ variant.sku }}</span></button></article></div>
                <p v-if="!variants.data.length" class="empty">No hay variantes para mostrar.</p>
                <div v-if="variants.last_page > 1" class="catalog-pagination"><button type="button" class="button secondary" :disabled="variants.current_page <= 1" @click="detail(selected.id, variants.current_page - 1)">Anterior</button><span>Página {{ variants.current_page }} de {{ variants.last_page }}</span><button type="button" class="button secondary" :disabled="variants.current_page >= variants.last_page" @click="detail(selected.id, variants.current_page + 1)">Siguiente</button></div>
            </section>
        </template>
        <template v-else>
            <section class="panel catalog-filters" aria-label="Filtros de productos">
                <label class="catalog-search">Buscar producto<input v-model="query" type="search" maxlength="120" placeholder="Nombre, SKU o código de barras" enterkeyhint="search" autocomplete="off" autocorrect="off"></label>
                <label>Categoría<select v-model="category"><option value="">Todas las categorías</option><option v-for="item in options.categories" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactiva' }}</option></select></label>
                <label>Marca<select v-model="brand"><option value="">Todas las marcas</option><option v-for="item in options.brands" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_active ? '' : ' · Inactiva' }}</option></select></label>
                <label>Tipo<select v-model="type"><option value="">Todos los tipos</option><option value="physical">Productos físicos</option><option value="service">Servicios</option></select></label>
                <label v-if="manage">Estado<select v-model="status"><option value="active">Activos</option><option value="archived">Archivados</option><option value="all">Todos</option></select></label>
            </section>
            <p v-if="loading" class="notice" role="status">Buscando productos…</p>
            <template v-else>
                <div class="catalog-results"><strong>{{ total }} {{ total === 1 ? 'producto' : 'productos' }}</strong><span class="muted">Fotos y variantes del catálogo.</span></div>
                <section class="product-grid" aria-label="Catálogo de productos">
                    <article v-for="product in products" :key="product.id" class="product-card product-card-carousel">
                        <div class="product-visual product-visual-carousel" :class="{ service: product.product_type === 'service' }" @touchstart.passive="onCardTouchStart" @touchend.passive="onCardTouchEnd(product, $event)">
                            <img
                                v-if="activeCardImage(product)"
                                :src="activeCardImage(product)?.renditions.card?.url || activeCardImage(product)?.renditions.detail?.url || undefined"
                                :alt="activeCardImage(product)?.alt_text || product.name"
                                loading="lazy"
                                style="width: 100%; height: 100%; object-fit: contain"
                                @click="detail(product.id)"
                            >
                            <span v-else aria-hidden="true" @click="detail(product.id)">{{ product.product_type === 'service' ? '✦' : product.name.slice(0, 1).toUpperCase() }}</span>
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
                        <button type="button" class="product-card-body product-card-open" :aria-label="`Ver ${product.name}`" @click="detail(product.id)">
                            <div class="catalog-tags">
                                <span v-if="product.status === 'archived'" class="badge inactive">Archivado</span>
                                <small>{{ product.category?.name || 'Sin categoría' }}</small>
                            </div>
                            <h2>{{ product.name }}</h2>
                            <p v-if="cardDisplayVariantLabel(product)" class="product-variant-tag">{{ cardDisplayVariantLabel(product) }}</p>
                            <strong class="product-price">{{ cardDisplayPrice(product) }}</strong>
                            <p class="muted">{{ product.active_variants_count }} {{ product.active_variants_count === 1 ? 'variante' : 'variantes' }}</p>
                        </button>
                    </article>
                </section>
                <section v-if="!products.length" class="panel empty"><h3>{{ query || category || brand || type || status !== 'active' ? 'No encontramos coincidencias.' : 'Tu catálogo empieza aquí.' }}</h3><p>{{ manage ? 'Añade un producto con su SKU y precio, o ajusta los filtros.' : 'No hay productos disponibles con estos filtros.' }}</p></section>
                <div v-if="products.length" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1" @click="loadProducts(page - 1)">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage" @click="loadProducts(page + 1)">Siguiente</button></div>
            </template>
        </template>
    </template>
    <ProductEditor v-if="manage" :open="productOpen" :product="productDraft" :initial-mode="editorMode" :options="options" :costs="costs" :opener="opener" :actor-id="actorId" :store="store" :warehouses="warehouses" :inventory-manage="permissions.includes('inventory.manage')" @close="productOpen = false" @saved="savedProduct" @photo-recovery="refreshPhotoReminders" />
    <VariantEditor v-if="manage" :open="variantOpen" :product="selected" :variant="variantDraft" :options="options" :costs="costs" :opener="opener" @close="variantOpen = false" @saved="savedVariant" />
    <ProductGallery :open="photosOpen" :product="selected" :variants="variants.data" :manage="manage" :opener="photoOpener" @close="photosOpen = false" @changed="photosChanged" />
</template>

<style scoped>
.product-card-carousel {
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow: hidden;
    text-align: left;
}
.product-visual-carousel {
    position: relative;
    cursor: pointer;
    user-select: none;
}
.product-card-open {
    display: grid;
    gap: 6px;
    width: 100%;
    padding: 14px;
    border: 0;
    background: transparent;
    text-align: left;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.product-variant-tag {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 600;
    color: #5b3fb3;
}
.product-price {
    white-space: nowrap;
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
@media (max-width: 760px) {
    .detail-hero {
        grid-template-columns: minmax(0, 1fr);
    }
    .detail-photo-stage {
        height: 240px;
    }
}
</style>
