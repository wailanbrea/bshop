<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import type { Business, Store, Warehouse } from '../types';

const CatalogView = defineAsyncComponent(() => import('./CatalogView.vue'));
const InventoryView = defineAsyncComponent(() => import('./InventoryView.vue'));

const props = withDefaults(defineProps<{
    business: Business;
    permissions: string[];
    actorId: number;
    store?: Store;
    warehouses: Warehouse[];
    initialTab?: 'catalog' | 'inventory';
}>(), {
    initialTab: 'catalog',
});

const canCatalog = computed(() => props.permissions.includes('catalog.view'));
const canInventory = computed(() => props.permissions.includes('inventory.view'));

const tab = ref<'catalog' | 'inventory'>(
    props.initialTab === 'inventory' && canInventory.value
        ? 'inventory'
        : canCatalog.value
          ? 'catalog'
          : 'inventory',
);

watch(() => props.initialTab, (next) => {
    if (next === 'inventory' && canInventory.value) tab.value = 'inventory';
    else if (next === 'catalog' && canCatalog.value) tab.value = 'catalog';
});
</script>

<template>
    <div class="products-hub">
        <div v-if="canCatalog && canInventory" class="products-hub-tabs" role="tablist" aria-label="Secciones de productos">
            <button
                type="button"
                role="tab"
                class="products-hub-tab"
                :class="{ active: tab === 'catalog' }"
                :aria-selected="tab === 'catalog'"
                @click="tab = 'catalog'"
            >
                Catálogo
                <small>Productos, fotos y precios</small>
            </button>
            <button
                type="button"
                role="tab"
                class="products-hub-tab"
                :class="{ active: tab === 'inventory' }"
                :aria-selected="tab === 'inventory'"
                @click="tab = 'inventory'"
            >
                Stock y Kardex
                <small>Ajustes, lotes FIFO y agotados</small>
            </button>
        </div>

        <CatalogView
            v-if="tab === 'catalog' && canCatalog"
            :business="business"
            :permissions="permissions"
            :actor-id="actorId"
            :store="store"
            :warehouses="warehouses"
        />
        <InventoryView
            v-else-if="tab === 'inventory' && canInventory"
            :key="store?.id || 'none'"
            :store="store"
            :warehouses="warehouses"
            :permissions="permissions"
        />
    </div>
</template>

<style scoped>
.products-hub { display: grid; gap: 14px; min-width: 0; }
.products-hub-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 6px;
    border-radius: 14px;
    background: #f4f2fb;
    border: 1px solid #e5e1f4;
    width: fit-content;
    max-width: 100%;
}
.products-hub-tab {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1px;
    padding: 8px 16px;
    border-radius: 10px;
    border: 1px solid transparent;
    background: transparent;
    color: #4b4668;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s ease, color .15s ease, box-shadow .15s ease;
}
.products-hub-tab small {
    font-size: 11px;
    font-weight: 500;
    color: #7c759a;
}
.products-hub-tab:hover { background: rgba(255, 255, 255, 0.7); }
.products-hub-tab.active {
    background: #fff;
    color: #5b21b6;
    border-color: #ddd6fe;
    box-shadow: 0 2px 8px rgba(100, 53, 237, 0.1);
}
.products-hub-tab.active small { color: #6d28d9; }
@media (max-width: 560px) {
    .products-hub-tabs { width: 100%; display: grid; grid-template-columns: 1fr 1fr; }
    .products-hub-tab { padding: 8px 10px; }
    .products-hub-tab small { display: none; }
}
</style>
