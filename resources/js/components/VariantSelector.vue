<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SalesVariant } from '../sales';
import { attribute, available, supportsColorSize } from '../lib/variantSelection';
import { quantity } from '../inventory';
import { amount } from '../catalog';

const props=defineProps<{productName:string;variants:SalesVariant[];modelValue:number|''|undefined;disabled?:boolean;currency?:string}>();
const emit=defineEmits<{ 'update:modelValue':[number|''] }>();
const color=ref('');const size=ref('');
const structured=computed(()=>supportsColorSize(props.variants));
const colors=computed(()=>[...new Set(props.variants.map(item=>attribute(item,'color')).filter((value):value is string=>!!value))]);
const sizes=computed(()=>props.variants.filter(item=>attribute(item,'color')===color.value));
const selected=computed(()=>props.variants.find(item=>item.id===props.modelValue));
const photo=computed(()=>selected.value?.image_url || (structured.value&&color.value?sizes.value.find(item=>item.image_url)?.image_url:null) || props.variants.find(item=>item.image_url)?.image_url || null);
function colorPhoto(value:string){const items=props.variants.filter(item=>attribute(item,'color')===value);return items.find(item=>item.thumb_url)?.thumb_url || items.find(item=>item.image_url)?.image_url || null;}
function colorPrice(value:string){const prices=[...new Set(props.variants.filter(item=>attribute(item,'color')===value).map(item=>item.price))];return prices.length===1&&props.currency?amount(prices[0],props.currency):'Varios precios';}
function colorAvailable(value:string){return props.variants.some(item=>attribute(item,'color')===value&&available(item));}
function changeColor(value:string){if(color.value===value)return;color.value=value;size.value='';emit('update:modelValue','');}
function changeSize(value:string){size.value=value;const item=sizes.value.find(variant=>attribute(variant,'talla')===value);emit('update:modelValue',item&&available(item)?item.id:'');}
watch(()=>[props.productName,props.variants],()=>{color.value='';size.value='';},{deep:false});
watch(()=>props.modelValue,value=>{const item=props.variants.find(variant=>variant.id===value);if(item&&structured.value){color.value=attribute(item,'color')||'';size.value=attribute(item,'talla')||'';}},{immediate:true});
</script>
<template>
    <div class="variant-selector">
        <template v-if="structured">
            <div v-if="photo" class="variant-hero"><img :src="photo" :alt="`Foto de ${productName}${color ? ` en ${color}` : ''}`" class="variant-preview" loading="lazy"></div>
            <div class="variant-heading">Color <strong v-if="color">{{ color }}</strong></div>
            <div class="variant-colors" role="group" :aria-label="`Elegir color de ${productName}`">
                <button v-for="item in colors" :key="item" type="button" class="color-choice" :class="{ selected:color===item }" :aria-label="`Elegir color ${item}${colorAvailable(item)?'':' (agotado)'}`" :aria-pressed="color===item" :disabled="disabled" @click="changeColor(item)">
                    <img v-if="colorPhoto(item)" :src="colorPhoto(item)!" :alt="`Foto de ${productName} en ${item}`" loading="lazy"><span v-else class="color-placeholder" aria-hidden="true">{{ item.slice(0,1) }}</span><span>{{ item }}</span><small v-if="currency">{{ colorPrice(item) }}</small><small v-if="!colorAvailable(item)">Agotado</small>
                </button>
            </div>
            <div v-if="color" class="variant-heading">Talla <strong v-if="size">{{ size }}</strong></div>
            <div v-if="color" class="variant-sizes" role="group" :aria-label="`Elegir talla de ${productName}`"><button v-for="item in sizes" :key="item.id" type="button" class="size-choice" :class="{ selected:size===attribute(item,'talla') }" :aria-label="`Elegir talla ${attribute(item,'talla')}${available(item)?'':' (agotada)'}`" :aria-pressed="size===attribute(item,'talla')" :disabled="disabled||!available(item)" @click="changeSize(attribute(item,'talla')||'')">{{ attribute(item,'talla') }} <small v-if="currency">{{ amount(item.price,currency) }}</small></button></div>
        </template>
        <label v-else>Variante del producto<select :value="modelValue || ''" :aria-label="`Variante de ${productName}`" :disabled="disabled" @change="emit('update:modelValue',Number(($event.target as HTMLSelectElement).value)||'')"><option value="">Selecciona variante</option><option v-for="item in variants" :key="item.id" :value="item.id">{{ item.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || item.sku }} · {{ item.available===null?'Sin control de stock':available(item)?`Disponible: ${quantity(item.available)}`:'Agotada' }}</option></select></label>
        <p v-if="selected" class="muted">{{ productName }} · {{ selected.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || selected.sku }} · Cantidad inicial: 1 · {{ selected.available===null?'Sin control de stock':`Disponible: ${quantity(selected.available)}` }}</p>
        <p v-if="selected?.description" class="variant-description">{{ selected.description }}</p>
        <img v-if="photo && !structured" :src="photo" :alt="`Foto de ${productName}`" class="variant-preview" loading="lazy">
    </div>
</template>
<style scoped>
.variant-selector{display:grid;gap:10px;min-width:0}.variant-selector select{min-height:48px;font-size:16px}.variant-heading{display:flex;gap:8px;align-items:baseline}.variant-hero{display:flex;justify-content:center;background:#f7f5fa;border-radius:12px;padding:8px}.variant-preview{width:100%;max-height:340px;height:clamp(220px,45vw,340px);object-fit:contain;border-radius:10px}.variant-colors{display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;padding:3px 2px 10px;scroll-snap-type:x proximity}.variant-sizes{display:flex;flex-wrap:wrap;gap:8px}.color-choice,.size-choice{font:inherit;background:#fff;border:1px solid #c9c4d2;border-radius:9px;cursor:pointer;color:inherit}.color-choice{display:grid;justify-items:center;align-content:start;gap:4px;flex:0 0 92px;min-height:106px;padding:5px;overflow-wrap:anywhere;scroll-snap-align:start}.color-choice img,.color-placeholder{width:60px;height:54px;object-fit:contain;border-radius:6px;background:#f7f5fa}.color-placeholder{display:grid;place-items:center;font-weight:700}.color-choice small{font-size:.72rem;color:#736d7a}.size-choice{min-width:48px;min-height:48px;padding:8px 12px}.size-choice small{display:block;font-size:.72rem}.selected{border:2px solid #6f3bc6;box-shadow:0 0 0 2px #e7ddf7}.size-choice:disabled{opacity:.48;text-decoration:line-through;cursor:not-allowed}.color-choice:focus-visible,.size-choice:focus-visible{outline:3px solid #7044dc;outline-offset:2px}
</style>
