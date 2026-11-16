<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Business, Store, Warehouse } from '../types';
import type { CatalogOptions, CatalogProduct, CatalogVariant, VariantPage } from '../catalog';
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
const productOpen = ref(false); const productDraft = ref<CatalogProduct | null>(null); const variantOpen = ref(false); const variantDraft = ref<CatalogVariant | null>(null); const opener = ref<HTMLElement | null>(null);
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
function editProduct(product: CatalogProduct | null, event: Event) { opener.value = event.currentTarget as HTMLElement; productDraft.value = product; productOpen.value = true; notice.value = ''; }
function editVariant(variant: CatalogVariant | null, event: Event) { opener.value = event.currentTarget as HTMLElement; variantDraft.value = variant; variantOpen.value = true; notice.value = ''; }
async function savedProduct(id: number) { notice.value = 'Producto guardado correctamente.'; await Promise.all([loadProducts(page.value), detail(id)]); }
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
        <div class="catalog-actions"><div><span class="eyebrow">PRODUCTOS Y SERVICIOS</span><p class="muted">{{ business.name }} · Catálogo del negocio</p></div><div class="actions"><button type="button" class="button secondary" :disabled="loading || detailLoading" @click="refreshCatalog">Actualizar</button><button v-if="manage" type="button" class="button secondary" @click="organizing = true">Organizar catálogo</button><button v-if="manage" type="button" class="button secondary" @click="openImport">Importar CSV</button><button v-if="manage" type="button" class="button primary" @click="editProduct(null, $event)">+ Nuevo producto</button></div></div>
        <template v-if="selected || detailLoading">
            <button type="button" class="text-button" @click="back">← Volver a productos</button>
            <p v-if="detailLoading" class="notice" role="status">Cargando producto…</p>
            <section v-else-if="selected" class="panel">
                <div class="panel-heading"><div><span class="eyebrow">{{ selected.category?.name || 'SIN CATEGORÍA' }} <span v-if="selected.brand">· {{ selected.brand.name }}</span></span><h2>{{ selected.name }}</h2><p class="muted">{{ selected.description || 'Sin descripción adicional.' }}</p></div><button v-if="manage" type="button" class="button secondary" @click="editProduct(selected, $event)">Editar producto</button></div>
                <div class="catalog-tags"><span class="badge" :class="{ inactive: selected.status === 'archived' }">{{ selected.status === 'active' ? 'Activo' : 'Archivado' }}</span><span class="badge">{{ selected.product_type === 'service' ? 'Servicio' : 'Producto físico' }}</span><span class="muted">Unidad: {{ selected.unit_code }}</span></div>
                <p class="catalog-stock-note">{{ selected.track_stock ? 'Seguimiento de existencias habilitado por variante y almacén. Consulta y ajusta las cantidades en Inventario.' : 'Este artículo se utiliza sin seguimiento de existencias.' }}</p>
                <div class="panel-heading"><h3>Fotografías · {{ selected.images?.length || 0 }} / 5</h3><button type="button" class="button secondary" @click="openPhotos">{{ manage ? 'Administrar fotos' : 'Ver fotos' }}</button></div>
                <div class="panel-heading catalog-section-heading"><div><h3>Variantes y precios</h3><p class="muted">{{ selected.active_variants_count }} activas · {{ variants.total }} visibles</p></div><button v-if="manage" type="button" class="button primary" :disabled="selected.status !== 'active'" @click="editVariant(null, $event)">+ Nueva variante</button></div>
                <div class="record-list"><article v-for="variant in variants.data" :key="variant.id" class="record catalog-variant-record"><div class="record-main"><strong>{{ variant.sku }}</strong><p>{{ variant.attribute_values.map(v => `${v.attribute_name}: ${v.value}`).join(' · ') || 'Variante general' }}</p><p v-if="variant.barcode">Barras: {{ variant.barcode }}</p></div><div class="variant-price"><strong>{{ amount(variant.sale_price, business.default_currency) }}</strong><small v-if="costs">Costo: {{ amount(variant.cost_price, business.default_currency) }}</small><span class="badge" :class="{ inactive: !variant.is_active }">{{ variant.is_active ? 'Activa' : 'Inactiva' }}</span></div><button v-if="manage" type="button" class="button secondary" @click="editVariant(variant, $event)">Editar<span class="sr-only"> {{ variant.sku }}</span></button></article></div>
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
                <section class="product-grid" aria-label="Catálogo de productos"><button v-for="product in products" :key="product.id" type="button" class="product-card" :aria-label="`Ver ${product.name}`" @click="detail(product.id)"><div class="product-visual" :class="{ service: product.product_type === 'service' }" aria-hidden="true"><img v-if="product.images?.some(image => image.is_primary && image.renditions.card.url)" :src="product.images.find(image => image.is_primary)?.renditions.card.url || undefined" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: contain"><span v-else>{{ product.product_type === 'service' ? '✦' : product.name.slice(0, 1).toUpperCase() }}</span></div><div class="product-card-body"><div class="catalog-tags"><span class="badge" :class="{ inactive: product.status === 'archived' }">{{ product.status === 'active' ? (product.product_type === 'service' ? 'Servicio' : 'Físico') : 'Archivado' }}</span><small>{{ product.category?.name || 'Sin categoría' }}</small></div><h2>{{ product.name }}</h2><p class="product-sku">{{ product.primary_sku || 'Sin SKU activo' }}</p><strong class="product-price">{{ product.min_sale_price === null ? 'Sin variante activa' : `${product.min_sale_price !== product.max_sale_price ? 'Desde ' : ''}${amount(product.min_sale_price, business.default_currency)}` }}</strong><p class="muted">{{ product.active_variants_count }} {{ product.active_variants_count === 1 ? 'variante activa' : 'variantes activas' }}</p></div></button></section>
                <section v-if="!products.length" class="panel empty"><h3>{{ query || category || brand || type || status !== 'active' ? 'No encontramos coincidencias.' : 'Tu catálogo empieza aquí.' }}</h3><p>{{ manage ? 'Añade un producto con su SKU y precio, o ajusta los filtros.' : 'No hay productos disponibles con estos filtros.' }}</p></section>
                <div v-if="products.length" class="catalog-pagination"><button type="button" class="button secondary" :disabled="page <= 1" @click="loadProducts(page - 1)">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="button secondary" :disabled="page >= lastPage" @click="loadProducts(page + 1)">Siguiente</button></div>
            </template>
        </template>
    </template>
    <ProductEditor v-if="manage" :open="productOpen" :product="productDraft" :options="options" :costs="costs" :opener="opener" :actor-id="actorId" :store="store" :warehouses="warehouses" :inventory-manage="permissions.includes('inventory.manage')" @close="productOpen = false" @saved="savedProduct" @photo-recovery="refreshPhotoReminders" />
    <VariantEditor v-if="manage" :open="variantOpen" :product="selected" :variant="variantDraft" :options="options" :costs="costs" :opener="opener" @close="variantOpen = false" @saved="savedVariant" />
    <ProductGallery :open="photosOpen" :product="selected" :variants="variants.data" :manage="manage" :opener="photoOpener" @close="photosOpen = false" @changed="photosChanged" />
</template>
