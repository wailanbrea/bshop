<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions, CatalogProduct, CatalogVariant } from '../catalog';
import { decimalInput, emptyVariant, variantPayload } from '../catalog';
import type { Store, Warehouse } from '../types';
import { quantity as fmtQty, type StockRow } from '../inventory';
import AppDialog from './AppDialog.vue';
import VariantFields from './VariantFields.vue';

const props = defineProps<{
    open: boolean;
    product: CatalogProduct | null;
    variant: CatalogVariant | null;
    options: CatalogOptions;
    costs: boolean;
    opener: HTMLElement | null;
    store?: Store;
    warehouses?: Warehouse[];
    inventoryManage?: boolean;
}>();
const emit = defineEmits<{ close: []; saved: [] }>();
const form = ref(emptyVariant(0)); const error = ref(''); const errorNotice = ref<HTMLElement>(); const busy = ref(false);
const stockRow = ref<StockRow | null>(null);
const stockQty = ref('');
const stockLoading = ref(false);
const activeWarehouse = computed(() =>
    (props.warehouses || []).find(w => w.is_active && w.is_default) ||
    (props.warehouses || []).find(w => w.is_active) ||
    null
);
const canAdjustStock = computed(() =>
    Boolean(props.inventoryManage && props.product?.track_stock && props.store?.is_active && activeWarehouse.value)
);

async function loadVariantStock(variant: CatalogVariant) {
    if (!canAdjustStock.value || !props.store || !activeWarehouse.value) return;
    stockLoading.value = true;
    try {
        const res = await api.get(`/business/stores/${props.store.id}/warehouses/${activeWarehouse.value.id}/stock`, {
            params: { q: variant.sku },
        });
        const found = ((res.data?.data || []) as StockRow[]).find(r => r.variant_id === variant.id) || null;
        stockRow.value = found;
        stockQty.value = found ? fmtQty(found.on_hand) : '0';
    } catch {
        stockRow.value = null;
        stockQty.value = '';
    } finally {
        stockLoading.value = false;
    }
}

watch(() => props.open, open => {
    if (!open) return;
    error.value = ''; form.value = emptyVariant(0);
    stockRow.value = null; stockQty.value = ''; stockLoading.value = false;
    if (props.variant) {
        Object.assign(form.value, { sku: props.variant.sku, barcode: props.variant.barcode || '', description: props.variant.description || '', sale_price: props.variant.sale_price, cost_price: props.variant.cost_price || '', is_active: props.variant.is_active });
        void loadVariantStock(props.variant);
    }
}, { immediate: true });

async function save() {
    if (busy.value || !props.product) return;
    busy.value = true; error.value = '';
    try {
        const body = variantPayload(form.value, props.costs) as ReturnType<typeof variantPayload> & { version?: number };
        if (props.variant) delete (body as { attribute_value_ids?: number[] }).attribute_value_ids;
        body.version = props.variant?.version ?? props.product.version;
        const resp = await api.request({ method: props.variant ? 'patch' : 'post', url: `/business/products/${props.product.id}/variants${props.variant ? `/${props.variant.id}` : ''}`, data: body });
        const savedVariantId: number | undefined = props.variant?.id ?? resp.data?.data?.id;

        const cleanQty = decimalInput(stockQty.value.trim());
        if (canAdjustStock.value && props.store && activeWarehouse.value && savedVariantId && cleanQty !== '') {
            if (props.variant && stockRow.value) {
                const currentNum = Number(stockRow.value.on_hand);
                const nextNum = Number(cleanQty);
                if (Number.isFinite(nextNum) && nextNum >= 0 && nextNum !== currentNum) {
                    await api.post(`/business/stores/${props.store.id}/inventory/movements`, {
                        request_key: crypto.randomUUID(),
                        warehouse_id: activeWarehouse.value.id,
                        variant_id: savedVariantId,
                        version: stockRow.value.version,
                        operation: 'count',
                        quantity: cleanQty,
                        note: 'Ajuste rápido de cantidad desde catálogo',
                    });
                }
            } else if (!props.variant) {
                const nextNum = Number(cleanQty);
                if (Number.isFinite(nextNum) && nextNum > 0) {
                    await api.post(`/business/stores/${props.store.id}/inventory/movements`, {
                        request_key: crypto.randomUUID(),
                        warehouse_id: activeWarehouse.value.id,
                        variant_id: savedVariantId,
                        version: 0,
                        operation: 'receipt',
                        quantity: cleanQty,
                        note: 'Existencia inicial de nueva variante',
                    });
                }
            }
        }
        emit('saved'); emit('close');
    } catch (e) { error.value = errorMessage(e); await nextTick(); errorNotice.value?.focus(); }
    finally { busy.value = false; }
}
</script>
<template>
    <AppDialog :open="open" :title="variant ? 'Editar variante' : 'Nueva variante'" :busy="busy" :return-focus="opener" @close="emit('close')">
        <form id="variant-editor-form" class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <p class="muted">{{ product?.name }}</p>
                <p v-if="variant" class="notice">{{ variant.attribute_values.map(v => `${v.attribute_name}: ${v.value}`).join(' · ') || 'Variante general' }}. Su combinación conserva la identidad del inventario; crea otra variante para una talla o color distinto.</p>
                <VariantFields :form="form" :options="options" :costs="costs" :editing="variant !== null" />
                <label v-if="canAdjustStock">
                    {{ variant ? 'Cantidad en inventario (existencia física)' : 'Cantidad inicial (opcional)' }}
                    <input
                        v-model="stockQty"
                        inputmode="decimal"
                        maxlength="20"
                        :disabled="busy || stockLoading"
                        :placeholder="stockLoading ? 'Consultando…' : 'Ej. 2'"
                        @input="stockQty = decimalInput(stockQty)"
                    >
                    <small v-if="variant && stockRow" class="muted">
                        {{ activeWarehouse?.name }} · Actual: {{ fmtQty(stockRow.on_hand) }} · Reservado: {{ fmtQty(stockRow.reserved) }} · Disponible: {{ fmtQty(stockRow.available) }}
                    </small>
                </label>
                <label class="check-label"><input v-model="form.is_active" type="checkbox"> Variante activa</label>
            </fieldset>
            <p v-if="error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="emit('close')">Cancelar</button><button type="submit" form="variant-editor-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar variante' }}</button></template>
    </AppDialog>
</template>
