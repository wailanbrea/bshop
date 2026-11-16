<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SalesVariant } from '../sales';
import { attribute, available, supportsColorSize } from '../lib/variantSelection';
import { quantity } from '../inventory';

const props=defineProps<{productName:string;variants:SalesVariant[];modelValue:number|''|undefined;disabled?:boolean}>();
const emit=defineEmits<{ 'update:modelValue':[number|''] }>();
const color=ref('');const size=ref('');
const structured=computed(()=>supportsColorSize(props.variants));
const colors=computed(()=>[...new Set(props.variants.map(item=>attribute(item,'color')).filter((value):value is string=>!!value))]);
const sizes=computed(()=>props.variants.filter(item=>attribute(item,'color')===color.value));
const selected=computed(()=>props.variants.find(item=>item.id===props.modelValue));
const photo=computed(()=>selected.value?.image_url || (structured.value&&color.value?sizes.value.find(item=>item.image_url)?.image_url:null));
function changeColor(value:string){color.value=value;size.value='';emit('update:modelValue','');}
function changeSize(value:string){size.value=value;const item=sizes.value.find(variant=>attribute(variant,'talla')===value);emit('update:modelValue',item&&available(item)?item.id:'');}
watch(()=>[props.productName,props.variants],()=>{color.value='';size.value='';},{deep:false});
</script>
<template>
    <div class="variant-selector">
        <template v-if="structured">
            <label>Color de {{ productName }}<select :value="color" :disabled="disabled" @change="changeColor(($event.target as HTMLSelectElement).value)"><option value="">Selecciona color</option><option v-for="item in colors" :key="item" :value="item">{{ item }}</option></select></label>
            <label>Talla de {{ productName }}<select :value="size" :disabled="disabled||!color" @change="changeSize(($event.target as HTMLSelectElement).value)"><option value="">Selecciona talla</option><option v-for="item in sizes" :key="item.id" :value="attribute(item,'talla')" :disabled="!available(item)">{{ attribute(item,'talla') }} · {{ item.available===null?'Sin control de stock':available(item)?`Disponible: ${quantity(item.available)}`:'Agotada' }}</option></select></label>
        </template>
        <label v-else>Variante del producto<select :value="modelValue || ''" :aria-label="`Variante de ${productName}`" :disabled="disabled" @change="emit('update:modelValue',Number(($event.target as HTMLSelectElement).value)||'')"><option value="">Selecciona variante</option><option v-for="item in variants" :key="item.id" :value="item.id">{{ item.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || item.sku }} · {{ item.available===null?'Sin control de stock':available(item)?`Disponible: ${quantity(item.available)}`:'Agotada' }}</option></select></label>
        <p v-if="selected" class="muted">{{ productName }} · {{ selected.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || selected.sku }} · Cantidad inicial: 1 · {{ selected.available===null?'Sin control de stock':`Disponible: ${quantity(selected.available)}` }}</p>
        <img v-if="photo" :src="photo" :alt="`Foto de ${productName}`" class="variant-preview" loading="lazy">
    </div>
</template>
<style scoped>.variant-selector{display:grid;gap:10px;min-width:0}.variant-preview{width:96px;height:96px;object-fit:contain;border-radius:10px;background:#f5f1fc}.variant-selector select{min-height:48px;font-size:16px}</style>
