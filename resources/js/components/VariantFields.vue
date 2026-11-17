<script setup lang="ts">
import { computed } from 'vue';
import type { CatalogOptions, VariantDraft } from '../catalog';
import { decimalInput } from '../catalog';

const props = defineProps<{ form: VariantDraft; options: CatalogOptions; costs: boolean; editing?: boolean }>();
const attributes = computed(() => props.options.attributes.filter(attribute => attribute.is_active));
</script>
<template>
    <div class="form-grid">
        <label>SKU<input v-model="form.sku" required maxlength="90" autocapitalize="characters" autocorrect="off" :spellcheck="false" enterkeyhint="next" placeholder="CAM-NEGRA-M"></label>
        <label>Código de barras<input v-model="form.barcode" maxlength="100" inputmode="text" autocorrect="off" :spellcheck="false" enterkeyhint="next" placeholder="Opcional"></label>
        <label>Precio de venta<input v-model="form.sale_price" @input="form.sale_price = decimalInput(form.sale_price)" type="text" inputmode="decimal" required pattern="(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?" title="Sin separadores de miles; hasta cuatro decimales." placeholder="1250.00" enterkeyhint="next"></label>
        <label v-if="costs">Costo<input v-model="form.cost_price" @input="form.cost_price = decimalInput(form.cost_price)" type="text" inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?" title="Sin separadores de miles; hasta cuatro decimales." placeholder="Opcional" enterkeyhint="done"></label>
    </div>
    <label>Descripción de esta variante (opcional)<textarea v-model="form.description" maxlength="2000" rows="2" placeholder="Detalles particulares de esta opción"></textarea></label>
    <div v-if="!editing && attributes.length" class="form-grid">
        <label v-for="attribute in attributes" :key="attribute.id">{{ attribute.name }}
            <select v-model="form.selections[attribute.id]"><option value="">Sin seleccionar</option><option v-for="value in attribute.values.filter(v => v.is_active)" :key="value.id" :value="value.id">{{ value.value }}</option></select>
        </label>
    </div>
    <p v-if="!editing" class="muted">Deja los atributos vacíos para una variante general. Cada variante activa debe usar los mismos atributos.</p>
</template>
