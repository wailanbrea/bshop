<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import { amount } from '../catalog';
import AppDialog from './AppDialog.vue';

interface SearchProductItem {
    id: number;
    name: string;
    status: string;
    skus: string[];
    buyers_count: number;
    units_sold: string;
}

interface BuyerOrderItem {
    order_id: number;
    basket_number: string | null;
    invoice_number: string | null;
    status: string;
    order_kind: string;
    sku: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    created_at: string;
}

interface ProductBuyer {
    customer_id: number;
    full_name: string;
    phone: string | null;
    email: string | null;
    orders_count: number;
    total_quantity: string;
    total_spent: string;
    last_order_at: string;
    items: BuyerOrderItem[];
}

interface BuyersPayload {
    product: { id: number; name: string };
    summary: {
        unique_customers: number;
        total_orders: number;
        total_units: string;
        total_revenue: string;
        currency: string;
    };
    buyers: ProductBuyer[];
}

const props = withDefaults(defineProps<{
    open: boolean;
    storeId: number;
    initialProduct?: { id: number; name: string } | null;
    currency?: string;
    allowOpenOrder?: boolean;
}>(), {
    initialProduct: null,
    currency: 'USD',
    allowOpenOrder: false,
});

const emit = defineEmits<{ close: []; openOrder: [number] }>();

const search = ref('');
const searching = ref(false);
const products = ref<SearchProductItem[]>([]);
const selectedProduct = ref<{ id: number; name: string } | null>(null);
const scope = ref<'all' | 'invoiced' | 'open'>('all');
const loadingBuyers = ref(false);
const error = ref('');
const buyersData = ref<BuyersPayload | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function formatQty(value: string): string {
    const n = Number(value || 0);
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString('es-DO', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
        return iso;
    }
}

function waLink(phone: string | null): string | null {
    if (!phone) return null;
    const digits = phone.replace(/\D/g, '');
    return digits.length >= 8 ? `https://wa.me/${digits}` : null;
}

async function runSearch() {
    if (!props.storeId) return;
    searching.value = true;
    error.value = '';
    try {
        const { data } = await api.get<{ data: SearchProductItem[] }>(
            `/business/stores/${props.storeId}/products/buyers/search`,
            { params: { q: search.value.trim() || undefined } },
        );
        products.value = data.data || [];
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudieron buscar productos.';
    } finally {
        searching.value = false;
    }
}

async function loadBuyers(product: { id: number; name: string }) {
    if (!props.storeId) return;
    selectedProduct.value = product;
    loadingBuyers.value = true;
    error.value = '';
    try {
        const { data } = await api.get<{ data: BuyersPayload }>(
            `/business/stores/${props.storeId}/products/${product.id}/buyers`,
            { params: { scope: scope.value } },
        );
        buyersData.value = data.data;
    } catch (e) {
        error.value = errorMessage(e) || 'No se pudieron cargar los compradores.';
    } finally {
        loadingBuyers.value = false;
    }
}

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    error.value = '';
    scope.value = 'all';
    search.value = '';
    if (props.initialProduct) {
        loadBuyers(props.initialProduct);
    } else {
        selectedProduct.value = null;
        buyersData.value = null;
        runSearch();
    }
});

watch(search, () => {
    if (!props.open || selectedProduct.value) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        runSearch();
    }, 240);
});

watch(scope, () => {
    if (props.open && selectedProduct.value) {
        loadBuyers(selectedProduct.value);
    }
});

const modalTitle = computed(() =>
    selectedProduct.value ? `Compradores: ${selectedProduct.value.name}` : 'Buscar clientes por producto',
);

function backToSearch() {
    selectedProduct.value = null;
    buyersData.value = null;
    if (!products.value.length) runSearch();
}

function handleOpenOrder(orderId: number) {
    emit('openOrder', orderId);
    emit('close');
}
</script>

<template>
    <AppDialog :open="open" :title="modalTitle" :busy="loadingBuyers" @close="emit('close')">
        <div class="pb-wrap">
            <p v-if="error" class="notice error" role="alert">{{ error }}</p>

            <!-- Product search view -->
            <template v-if="!selectedProduct">
                <label class="pb-search-label">
                    Buscar producto por nombre o SKU
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Ej. Vestido, Cartera, SKU..."
                        autocomplete="off"
                    >
                </label>

                <p v-if="searching" class="muted">Buscando productos...</p>
                <p v-else-if="!products.length" class="muted">No se encontraron productos en esta sucursal.</p>

                <div v-else class="pb-product-list">
                    <button
                        v-for="p in products"
                        :key="p.id"
                        type="button"
                        class="pb-product-row"
                        @click="loadBuyers({ id: p.id, name: p.name })"
                    >
                        <div class="pb-product-main">
                            <strong>{{ p.name }}</strong>
                            <small v-if="p.skus.length" class="muted">{{ p.skus.join(' · ') }}</small>
                        </div>
                        <div class="pb-product-stats">
                            <span class="pb-pill" :class="{ active: p.buyers_count > 0 }">
                                {{ p.buyers_count }} {{ p.buyers_count === 1 ? 'cliente' : 'clientes' }}
                            </span>
                            <small v-if="Number(p.units_sold) > 0" class="muted">
                                {{ formatQty(p.units_sold) }} uds.
                            </small>
                        </div>
                    </button>
                </div>
            </template>

            <!-- Buyers list view -->
            <template v-else>
                <div class="pb-top-bar">
                    <button type="button" class="button secondary pb-back-btn" @click="backToSearch">
                        ← Cambiar producto
                    </button>
                    <div class="pb-scope-tabs" role="tablist">
                        <button
                            type="button"
                            class="pb-scope-btn"
                            :class="{ active: scope === 'all' }"
                            @click="scope = 'all'"
                        >
                            Todos
                        </button>
                        <button
                            type="button"
                            class="pb-scope-btn"
                            :class="{ active: scope === 'invoiced' }"
                            @click="scope = 'invoiced'"
                        >
                            Facturados
                        </button>
                        <button
                            type="button"
                            class="pb-scope-btn"
                            :class="{ active: scope === 'open' }"
                            @click="scope = 'open'"
                        >
                            Apartados
                        </button>
                    </div>
                </div>

                <div v-if="buyersData" class="pb-summary-grid">
                    <div class="pb-stat">
                        <small>Clientes</small>
                        <strong>{{ buyersData.summary.unique_customers }}</strong>
                    </div>
                    <div class="pb-stat">
                        <small>Unidades</small>
                        <strong>{{ formatQty(buyersData.summary.total_units) }}</strong>
                    </div>
                    <div class="pb-stat">
                        <small>Total vendido</small>
                        <strong class="pb-nowrap">{{ amount(buyersData.summary.total_revenue, buyersData.summary.currency || currency) }}</strong>
                    </div>
                </div>

                <p v-if="loadingBuyers" class="muted">Cargando lista de clientes...</p>
                <p v-else-if="!buyersData?.buyers.length" class="muted">
                    Ningún cliente ha adquirido este producto con el filtro seleccionado.
                </p>

                <div v-else class="pb-buyers-list">
                    <article v-for="buyer in buyersData.buyers" :key="buyer.customer_id" class="pb-buyer-card">
                        <div class="pb-buyer-head">
                            <div class="pb-buyer-info">
                                <strong>{{ buyer.full_name }}</strong>
                                <div class="pb-buyer-contact">
                                    <span v-if="buyer.phone">{{ buyer.phone }}</span>
                                    <span v-if="buyer.email">· {{ buyer.email }}</span>
                                    <span>· Última: {{ formatDate(buyer.last_order_at) }}</span>
                                </div>
                            </div>
                            <a
                                v-if="waLink(buyer.phone)"
                                :href="waLink(buyer.phone)!"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="pb-wa-btn"
                            >
                                WhatsApp
                            </a>
                        </div>

                        <div class="pb-buyer-totals">
                            <span class="pb-qty-badge">
                                {{ formatQty(buyer.total_quantity) }} {{ Number(buyer.total_quantity) === 1 ? 'unidad' : 'unidades' }}
                            </span>
                            <strong class="pb-spent-val pb-nowrap">
                                {{ amount(buyer.total_spent, buyersData.summary.currency || currency) }}
                            </strong>
                        </div>

                        <div class="pb-orders-chips">
                            <button
                                v-for="(it, idx) in buyer.items"
                                :key="`${it.order_id}-${idx}`"
                                type="button"
                                class="pb-order-chip"
                                :disabled="!allowOpenOrder"
                                @click="allowOpenOrder && handleOpenOrder(it.order_id)"
                            >
                                <span>
                                    {{ it.invoice_number ? `Factura ${it.invoice_number}` : `Apartado ${it.basket_number || '#' + it.order_id}` }}
                                </span>
                                <span class="muted">
                                    · {{ formatQty(it.quantity) }} × {{ it.sku }}
                                </span>
                            </button>
                        </div>
                    </article>
                </div>
            </template>
        </div>

        <template #footer>
            <button type="button" class="button secondary" @click="emit('close')">Cerrar</button>
        </template>
    </AppDialog>
</template>

<style scoped>
.pb-wrap { display: grid; gap: 12px; min-width: 0; }
.pb-search-label { display: grid; gap: 6px; font-size: 13px; font-weight: 600; }
.pb-product-list { display: grid; gap: 6px; max-height: 360px; overflow-y: auto; }
.pb-product-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #e5e2f2;
    background: #fff;
    text-align: left;
    cursor: pointer;
    min-width: 0;
}
.pb-product-row:hover { border-color: #6435ed; background: #faf8ff; }
.pb-product-main { display: grid; gap: 2px; min-width: 0; }
.pb-product-main strong { font-size: 13.5px; color: #1f1b38; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pb-product-stats { display: grid; justify-items: end; gap: 2px; flex-shrink: 0; }
.pb-pill {
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
    white-space: nowrap;
}
.pb-pill.active { background: #ede9fe; color: #5b21b6; }

.pb-top-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.pb-back-btn { padding: 6px 10px; font-size: 12.5px; }
.pb-scope-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
.pb-scope-btn {
    padding: 5px 10px;
    border-radius: 999px;
    border: 1px solid #ddd6fe;
    background: #fff;
    color: #4b4668;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.pb-scope-btn.active { background: #6435ed; border-color: #6435ed; color: #fff; }

.pb-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.pb-stat {
    background: #f8f7fc;
    border: 1px solid #e7e4f3;
    border-radius: 10px;
    padding: 8px 10px;
    display: grid;
    gap: 2px;
}
.pb-stat small { font-size: 11px; color: #6b648a; }
.pb-stat strong { font-size: 15px; color: #1f1b38; }
.pb-nowrap { white-space: nowrap; }

.pb-buyers-list { display: grid; gap: 10px; max-height: 360px; overflow-y: auto; }
.pb-buyer-card {
    border: 1px solid #e5e2f2;
    border-radius: 12px;
    padding: 11px 12px;
    background: #fff;
    display: grid;
    gap: 8px;
}
.pb-buyer-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.pb-buyer-info { display: grid; gap: 2px; min-width: 0; }
.pb-buyer-info strong { font-size: 14px; color: #1f1b38; }
.pb-buyer-contact { font-size: 12px; color: #6b648a; display: flex; flex-wrap: wrap; gap: 4px; }
.pb-wa-btn {
    padding: 4px 10px;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-size: 11.5px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}
.pb-buyer-totals {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 4px;
    border-top: 1px dashed #ece9f6;
}
.pb-qty-badge {
    padding: 2px 8px;
    border-radius: 8px;
    background: #f3eeff;
    color: #5b21b6;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.pb-spent-val { font-size: 14px; color: #1f1b38; }

.pb-orders-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.pb-order-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 8px;
    border: 1px solid #e5e2f2;
    background: #faf9fd;
    color: #1f1b38;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
}
.pb-order-chip:disabled { cursor: default; }
.pb-order-chip:hover:not(:disabled) { border-color: #6435ed; background: #f3eeff; }

@media (max-width: 520px) {
    .pb-summary-grid { grid-template-columns: 1fr; }
}
</style>
