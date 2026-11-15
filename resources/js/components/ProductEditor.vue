<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions, CatalogProduct, VariantDraft } from '../catalog';
import { emptyVariant, variantPayload } from '../catalog';
import AppDialog from './AppDialog.vue';
import VariantFields from './VariantFields.vue';

const props = defineProps<{ open: boolean; product: CatalogProduct | null; options: CatalogOptions; costs: boolean; opener: HTMLElement | null }>();
const emit = defineEmits<{ close: []; saved: [id: number] }>();
const busy = ref(false); const error = ref(''); const errorNotice = ref<HTMLElement>();
const form = reactive({ name: '', description: '', category_id: '' as number | '', brand_id: '' as number | '', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active' });
const variants = ref<VariantDraft[]>([]); let nextKey = 1;
const editing = computed(() => props.product !== null);
watch(() => props.open, open => {
    if (!open) return;
    error.value = '';
    Object.assign(form, props.product ? { name: props.product.name, description: props.product.description || '', category_id: props.product.category?.id || '', brand_id: props.product.brand?.id || '', product_type: props.product.product_type, unit_code: props.product.unit_code, track_stock: props.product.track_stock, status: props.product.status } : { name: '', description: '', category_id: '', brand_id: '', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active' });
    variants.value = [emptyVariant(nextKey++)];
}, { immediate: true });
async function save() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const body = { ...form, category_id: form.category_id || null, brand_id: form.brand_id || null,
            ...(props.product ? { version: props.product.version } : { variants: variants.value.map(v => variantPayload(v, props.costs)) }) };
        const response = await api.request({ method: editing.value ? 'patch' : 'post', url: `/business/products${props.product ? `/${props.product.id}` : ''}`, data: body });
        emit('saved', response.data.data.id); emit('close');
    } catch (e) { error.value = errorMessage(e); await nextTick(); errorNotice.value?.focus(); }
    finally { busy.value = false; }
}
</script>
<template>
    <AppDialog :open="open" :title="editing ? 'Editar producto' : 'Nuevo producto'" :busy="busy" :return-focus="opener" @close="emit('close')">
        <form id="product-editor-form" class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <label>Nombre del producto<input v-model="form.name" required maxlength="200" enterkeyhint="next" placeholder="Camiseta básica"></label>
                <label>Descripción<textarea v-model="form.description" maxlength="10000" rows="3" placeholder="Detalles del producto o servicio"></textarea></label>
                <div class="form-grid">
                    <label>Categoría<select v-model="form.category_id"><option value="">Sin categoría</option><option v-for="category in options.categories" :key="category.id" :value="category.id" :disabled="!category.is_active && form.category_id !== category.id">{{ category.name }}{{ category.is_active ? '' : ' · Inactiva' }}</option></select></label>
                    <label>Marca<select v-model="form.brand_id"><option value="">Sin marca</option><option v-for="brand in options.brands" :key="brand.id" :value="brand.id" :disabled="!brand.is_active && form.brand_id !== brand.id">{{ brand.name }}{{ brand.is_active ? '' : ' · Inactiva' }}</option></select></label>
                    <label>Tipo de producto<select v-model="form.product_type" @change="form.track_stock = form.product_type === 'physical'"><option value="physical">Producto físico</option><option value="service">Servicio</option></select></label>
                    <label>Unidad<input v-model="form.unit_code" required maxlength="20" placeholder="unit, kg, l, h"></label>
                </div>
                <label class="check-label"><input v-model="form.track_stock" type="checkbox" :disabled="form.product_type === 'service'"> Controlar existencias por almacén</label>
                <label>Estado<select v-model="form.status"><option value="active">Activo</option><option value="archived">Archivado</option></select></label>
                <p v-if="editing" class="notice">Los SKU y precios se editan en las variantes. Archivar no borra productos, stock ni historial.</p>
                <template v-else>
                    <section v-for="(variant, index) in variants" :key="variant.key" class="variant-draft">
                        <div class="variant-draft-heading"><h3>Variante {{ index + 1 }}</h3><button v-if="variants.length > 1" type="button" class="icon-button" @click="variants.splice(index, 1)" :aria-label="`Quitar variante ${index + 1}`">×</button></div>
                        <VariantFields :form="variant" :options="options" :costs="costs" />
                    </section>
                    <button type="button" class="button secondary" :disabled="variants.length >= 100" @click="variants.push(emptyVariant(nextKey++))">+ Añadir otra variante</button>
                </template>
            </fieldset>
            <p v-if="error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="emit('close')">Cancelar</button><button type="submit" form="product-editor-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar producto' }}</button></template>
    </AppDialog>
</template>
