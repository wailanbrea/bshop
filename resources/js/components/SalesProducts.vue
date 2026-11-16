<script setup lang="ts">
import axios from 'axios';
import { onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import { quantity } from '../inventory';
import type { SalesProduct, SalesVariant } from '../sales';
import AppDialog from './AppDialog.vue';
import VariantSelector from './VariantSelector.vue';
import { available, supportsColorSize } from '../lib/variantSelection';

const props = defineProps<{ root: string; warehouseId: number | ''; currency: string; disabled: boolean; refresh: number; mode: 'sale' | 'reservation' }>();
const emit = defineEmits<{ add: [SalesProduct, SalesVariant] }>();
const products = ref<SalesProduct[]>([]); const query = ref(''); const category = ref<number | ''>(''); const sort = ref('newest'); const list = ref(false); const page = ref(1); const lastPage = ref(1); const total = ref(0); const loading = ref(false); const error = ref('');
const categories = ref<{ id: number; name: string; is_active: boolean }[]>([]); const selections = ref<Record<number, number | ''>>({});
const picking = ref<SalesProduct | null>(null); const pickingOpener = ref<HTMLElement | null>(null);
function choose(product: SalesProduct, event: Event) { if (props.disabled) return; if (product.variants.length === 1 && !supportsColorSize(product.variants)) { if (available(product.variants[0])) emit('add', product, product.variants[0]); } else { selections.value[product.id]=''; picking.value = product; pickingOpener.value = event.currentTarget as HTMLElement; } }
function addPicked() { if (!picking.value) return; const selected = variant(picking.value); if (!selected || !available(selected)) return; emit('add', picking.value, selected); picking.value = null; }
let token = 0; let controller: AbortController | undefined; let timer: ReturnType<typeof setTimeout> | undefined;
function variant(product: SalesProduct) { return product.variants.length === 1 && !supportsColorSize(product.variants) ? product.variants[0] : product.variants.find(v => v.id === selections.value[product.id]); }
async function load(target = 1) {
    if (!props.warehouseId) return; const version = ++token; controller?.abort(); controller = new AbortController(); loading.value = true; error.value = '';
    try { const result = await api.get(`${props.root}/sales/catalog`, { signal: controller.signal, params: { warehouse_id: props.warehouseId, q: query.value, category_id: category.value || undefined, sort: sort.value, page: target } }); if (version !== token) return; products.value = result.data.data; page.value = result.data.meta.current_page; lastPage.value = result.data.meta.last_page; total.value = result.data.meta.total; }
    catch (e) { if (version === token && !axios.isCancel(e)) error.value = errorMessage(e); }
    finally { if (version === token) loading.value = false; }
}
watch(query, () => { clearTimeout(timer); timer = setTimeout(() => load(), 200); }); watch([category, sort], () => load());
watch(() => [props.root, props.warehouseId, props.refresh], () => load(), { immediate: true });
api.get('/business/catalog/options').then(result => { categories.value = result.data.data.categories.filter((c: { is_active: boolean }) => c.is_active); }).catch(e => { error.value = errorMessage(e); });
onUnmounted(() => { ++token; controller?.abort(); clearTimeout(timer); });
</script>
<template>
    <section class="sales-products sales-surface" aria-label="Productos para la operación">
        <h2>Agregar productos</h2><div class="sales-search-row"><label class="sr-only" for="sales-product-search">Buscar producto para vender</label><input id="sales-product-search" v-model="query" type="search" maxlength="120" placeholder="Nombre, código o categoría…" autocomplete="off"><button class="icon-button sales-view-switch" type="button" :aria-label="list ? 'Ver cuadrícula' : 'Ver lista'" @click="list = !list">{{ list ? '▦' : '☷' }}</button></div>
        <div class="sales-categories" aria-label="Categorías de venta"><button type="button" class="button" :class="!category ? 'primary' : 'secondary'" :aria-pressed="!category" @click="category = ''">Todos</button><button v-for="item in categories" :key="item.id" type="button" class="button" :class="category === item.id ? 'primary' : 'secondary'" :aria-pressed="category === item.id" @click="category = item.id">{{ item.name }}</button></div>
        <div class="sales-grid-heading"><span>{{ total }} productos</span><label class="sales-sort">Ordenar<select v-model="sort" aria-label="Ordenar productos"><option value="newest">Más recientes</option><option value="name">Nombre</option></select></label></div>
        <p v-if="error" class="notice error" role="alert">{{ error }} <button class="text-button" @click="load(page)">Reintentar</button></p><p v-if="loading" class="muted" role="status">Buscando productos…</p>
        <div class="sales-product-grid" :class="{ list }"><article v-for="product in products" :key="product.id" class="sales-product-card">
            <button v-if="mode === 'sale'" type="button" class="sales-card-select" :disabled="disabled || (product.variants.length === 1 && !available(product.variants[0]))" :aria-label="`Elegir ${product.name}`" @click="choose(product, $event)"><div class="sales-product-photo"><img v-if="product.image?.renditions.card.url" :src="product.image.renditions.card.url" :alt="product.name" loading="lazy"><span v-else aria-hidden="true">{{ product.name.slice(0,1) }}</span></div><div class="sales-product-body"><h3>{{ product.name }}</h3><strong class="sales-product-price">{{ amount(variant(product)?.price || product.variants[0]?.price, currency) }}</strong><p class="muted">{{ product.variants.length }} {{ product.variants.length === 1 ? 'variante' : 'variantes' }}</p><span v-if="variant(product)" class="badge" :class="{ inactive: !available(variant(product)!) }">{{ variant(product)?.available === null ? 'Sin control de stock' : `Disponible (${quantity(variant(product)?.available || '0')})` }}</span><span v-else class="sales-choose-variant">Elegir variante</span></div></button>
            <template v-else><div class="sales-product-photo"><img v-if="variant(product)?.image_url || product.image?.renditions.card.url" :src="variant(product)?.image_url || product.image?.renditions.card.url || undefined" :alt="product.name" loading="lazy"><span v-else aria-hidden="true">{{ product.name.slice(0,1) }}</span></div><div class="sales-product-body"><h3>{{ product.name }}</h3><strong class="sales-product-price">{{ amount(variant(product)?.price || product.variants[0]?.price, currency) }}</strong><p class="muted">{{ product.variants.length }} {{ product.variants.length === 1 ? 'variante' : 'variantes' }}</p><VariantSelector v-if="product.variants.length > 1 || supportsColorSize(product.variants)" v-model="selections[product.id]" :product-name="product.name" :variants="product.variants" :disabled="disabled"/><span v-if="variant(product)" class="badge" :class="{ inactive: !available(variant(product)!) }">{{ variant(product)?.available === null ? 'Sin control de stock' : `${available(variant(product)!) ? 'Disponible' : 'Sin stock'} (${quantity(variant(product)?.available || '0')})` }}</span><p v-else class="muted">Elige la variante para consultar stock.</p><button type="button" class="button secondary full" :disabled="disabled || !variant(product) || !available(variant(product)!)" :aria-label="`Agregar ${product.name}`" @click="emit('add', product, variant(product)!)">+ Agregar</button></div></template>
        </article></div>
        <p v-if="!loading && !products.length" class="empty">No hay productos disponibles con estos filtros.</p>
        <div v-if="lastPage > 1" class="catalog-pagination"><button class="button secondary" :disabled="page <= 1 || loading" @click="load(page - 1)">Anterior</button><span>{{ page }}/{{ lastPage }}</span><button class="button secondary" :disabled="page >= lastPage || loading" @click="load(page + 1)">Siguiente</button></div>
    </section>
    <AppDialog :open="!!picking" :title="`Elegir variante de ${picking?.name || 'producto'}`" :return-focus="pickingOpener" @close="picking = null"><template v-if="picking"><VariantSelector :key="picking.id" v-model="selections[picking.id]" :product-name="picking.name" :variants="picking.variants"/><p v-if="variant(picking)" class="notice">{{ amount(variant(picking)?.price, currency) }} · {{ variant(picking)?.available === null ? 'Sin control de stock' : `Disponible: ${quantity(variant(picking)?.available || '0')}` }}</p></template><template #footer><button class="button secondary" @click="picking = null">Cancelar</button><button class="button primary" :disabled="!picking || !variant(picking) || !available(variant(picking)!)" @click="addPicked">Agregar a la venta</button></template></AppDialog>
</template>
