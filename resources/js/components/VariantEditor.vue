<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions, CatalogProduct, CatalogVariant } from '../catalog';
import { emptyVariant, variantPayload } from '../catalog';
import AppDialog from './AppDialog.vue';
import VariantFields from './VariantFields.vue';

const props = defineProps<{ open: boolean; product: CatalogProduct | null; variant: CatalogVariant | null; options: CatalogOptions; costs: boolean; opener: HTMLElement | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const form = ref(emptyVariant(0)); const error = ref(''); const errorNotice = ref<HTMLElement>(); const busy = ref(false);
watch(() => props.open, open => {
    if (!open) return;
    error.value = ''; form.value = emptyVariant(0);
    if (props.variant) Object.assign(form.value, { sku: props.variant.sku, barcode: props.variant.barcode || '', sale_price: props.variant.sale_price, cost_price: props.variant.cost_price || '', is_active: props.variant.is_active });
}, { immediate: true });
async function save() {
    if (busy.value || !props.product) return;
    busy.value = true; error.value = '';
    try {
        const body = variantPayload(form.value, props.costs) as ReturnType<typeof variantPayload> & { version?: number };
        if (props.variant) delete (body as { attribute_value_ids?: number[] }).attribute_value_ids;
        body.version = props.variant?.version ?? props.product.version;
        await api.request({ method: props.variant ? 'patch' : 'post', url: `/business/products/${props.product.id}/variants${props.variant ? `/${props.variant.id}` : ''}`, data: body });
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
                <label class="check-label"><input v-model="form.is_active" type="checkbox"> Variante activa</label>
            </fieldset>
            <p v-if="error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="emit('close')">Cancelar</button><button type="submit" form="variant-editor-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar variante' }}</button></template>
    </AppDialog>
</template>
