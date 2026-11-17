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
const allSizes=computed(()=>[...new Set(props.variants.map(item=>attribute(item,'talla')).filter((value):value is string=>!!value))]);
const colorVariants=computed(()=>props.variants.filter(item=>attribute(item,'color')===color.value));
const selected=computed(()=>props.variants.find(item=>item.id===props.modelValue));
const photo=computed(()=>selected.value?.image_url || (structured.value&&color.value?colorVariants.value.find(item=>item.image_url)?.image_url:null) || props.variants.find(item=>item.image_url)?.image_url || null);
function defaultColor(){return colors.value.find(item=>colorAvailable(item)) || colors.value[0] || '';}
function colorPhoto(value:string){const items=props.variants.filter(item=>attribute(item,'color')===value);return items.find(item=>item.thumb_url)?.thumb_url || items.find(item=>item.image_url)?.image_url || null;}
function colorPrice(value:string){const prices=[...new Set(props.variants.filter(item=>attribute(item,'color')===value).map(item=>item.price))];return prices.length===1&&props.currency?amount(prices[0],props.currency):'Varios precios';}
function colorAvailable(value:string){return props.variants.some(item=>attribute(item,'color')===value&&available(item));}
function sizeVariant(value:string){return color.value?colorVariants.value.find(item=>attribute(item,'talla')===value):undefined;}
function sizeAvailable(value:string){const item=sizeVariant(value);return !!item&&available(item);}
function changeColor(value:string){if(color.value===value)return;color.value=value;const item=size.value?sizeVariant(size.value):undefined;if(item&&available(item)){emit('update:modelValue',item.id);}else{size.value='';emit('update:modelValue','');}}
function changeSize(value:string){const item=sizeVariant(value);if(!item||!available(item))return;size.value=value;emit('update:modelValue',item.id);}
function applyInitialSelection(){
    if(!structured.value){color.value='';size.value='';return;}
    const active=props.variants.filter(item=>available(item));
    if(active.length===1){
        color.value=attribute(active[0],'color')||'';
        size.value=attribute(active[0],'talla')||'';
        emit('update:modelValue',active[0].id);
        return;
    }
    color.value=defaultColor();
    size.value='';
}
watch(()=>[props.productName,props.variants],()=>{applyInitialSelection();},{immediate:true,deep:false});
watch(()=>props.modelValue,value=>{const item=props.variants.find(variant=>variant.id===value);if(item&&structured.value){color.value=attribute(item,'color')||'';size.value=attribute(item,'talla')||'';}else if(structured.value&&!color.value){applyInitialSelection();}},{immediate:true});
</script>
<template>
    <div class="variant-selector">
        <template v-if="structured">
            <div v-if="photo" class="variant-hero"><img :src="photo" :alt="`Foto de ${productName}${color ? ` en ${color}` : ''}`" class="variant-preview" loading="lazy"></div>
            <div class="variant-section">
                <div class="variant-heading"><span>Color</span><strong v-if="color" class="variant-current">{{ color }}</strong></div>
                <div class="variant-colors" role="group" :aria-label="`Elegir color de ${productName}`">
                    <button v-for="item in colors" :key="item" type="button" class="color-choice" :class="{ selected:color===item, unavailable:!colorAvailable(item) }" :aria-label="`Elegir color ${item}${colorAvailable(item)?'':' (agotado)'}`" :aria-pressed="color===item" :disabled="disabled" @click="changeColor(item)">
                        <img v-if="colorPhoto(item)" :src="colorPhoto(item)!" :alt="`Foto de ${productName} en ${item}`" loading="lazy"><span v-else class="color-placeholder" aria-hidden="true">{{ item.slice(0,1) }}</span><span class="color-name">{{ item }}</span><small v-if="currency">{{ colorPrice(item) }}</small><small v-if="!colorAvailable(item)" class="status-tag">Agotado</small>
                    </button>
                </div>
            </div>
            <div class="variant-section">
                <div class="variant-heading"><span>Talla</span><strong v-if="size" class="variant-current">{{ size }}</strong><span v-else class="variant-hint">Selecciona una talla</span></div>
                <div class="variant-sizes" role="group" :aria-label="`Elegir talla de ${productName}`">
                    <button v-for="item in allSizes" :key="item" type="button" class="size-choice" :class="{ selected:size===item&&sizeAvailable(item), unavailable:!sizeAvailable(item) }" :aria-label="`Elegir talla ${item}${sizeAvailable(item)?'':' (agotada)'}`" :aria-pressed="size===item&&sizeAvailable(item)" :disabled="disabled||!sizeAvailable(item)" @click="changeSize(item)">
                        <span class="size-label">{{ item }}</span>
                        <small v-if="!sizeAvailable(item)" class="size-status">No disponible</small>
                        <small v-else-if="currency && sizeVariant(item)" class="size-price">{{ amount(sizeVariant(item)!.price,currency) }}</small>
                    </button>
                </div>
            </div>
        </template>
        <label v-else>Variante del producto<select :value="modelValue || ''" :aria-label="`Variante de ${productName}`" :disabled="disabled" @change="emit('update:modelValue',Number(($event.target as HTMLSelectElement).value)||'')"><option value="">Selecciona variante</option><option v-for="item in variants" :key="item.id" :value="item.id">{{ item.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || item.sku }} · {{ item.available===null?'Sin control de stock':available(item)?`Disponible: ${quantity(item.available)}`:'Agotada' }}</option></select></label>
        <div v-if="selected" class="variant-summary">
            <div class="variant-summary-top">
                <strong v-if="currency" class="detail-price">{{ amount(selected.price,currency) }}</strong>
                <div class="variant-badges">
                    <span class="variant-badge stock">{{ selected.available===null?'Sin control de stock':`Disponible: ${quantity(selected.available)}` }}</span>
                    <span class="variant-badge">Cantidad inicial: 1</span>
                </div>
            </div>
            <p class="variant-meta muted">{{ productName }} · {{ selected.attributes.map(a=>`${a.name}: ${a.value}`).join(' · ') || selected.sku }}</p>
            <p v-if="selected.description" class="variant-description">{{ selected.description }}</p>
        </div>
        <div v-else-if="structured" class="variant-summary empty-summary">
            <p class="muted">Elige una talla disponible para consultar su precio y existencias.</p>
        </div>
        <img v-if="photo && !structured" :src="photo" :alt="`Foto de ${productName}`" class="variant-preview" loading="lazy">
    </div>
</template>
<style scoped>
.variant-selector{display:grid;gap:14px;min-width:0}.variant-selector select{min-height:48px;font-size:16px}.variant-section{display:grid;gap:8px;min-width:0}.variant-heading{display:flex;justify-content:space-between;gap:8px;align-items:baseline;font-size:.95rem}.variant-current{color:#4b2399;background:#f2ecfc;padding:2px 10px;border-radius:999px;font-size:.88rem}.variant-hint{font-size:.82rem;color:#736d7a}.variant-hero{display:flex;justify-content:center;background:#f7f5fa;border:1px solid #ece7f5;border-radius:12px;padding:10px}.variant-preview{width:100%;max-height:280px;height:clamp(190px,40vw,280px);object-fit:contain;border-radius:10px}.variant-colors{display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding:4px 2px 8px;scroll-snap-type:x proximity}.variant-sizes{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px}.color-choice,.size-choice{font:inherit;background:#fff;border:1px solid #c9c4d2;border-radius:10px;cursor:pointer;color:inherit;transition:border-color .15s,background-color .15s}.color-choice{display:grid;justify-items:center;align-content:start;gap:4px;flex:0 0 96px;min-height:108px;padding:6px;overflow-wrap:anywhere;scroll-snap-align:start}.color-choice img,.color-placeholder{width:62px;height:56px;object-fit:contain;border-radius:6px;background:#f7f5fa}.color-placeholder{display:grid;place-items:center;font-weight:700}.color-name{font-weight:600;font-size:.9rem}.color-choice small{font-size:.72rem;color:#736d7a;white-space:nowrap}.status-tag{color:#9f3a38!important;font-weight:600}.size-choice{display:grid;justify-items:center;align-content:center;gap:2px;min-width:48px;min-height:52px;padding:8px 10px;text-align:center}.size-label{font-weight:700;font-size:.95rem}.size-price,.size-status{display:block;font-size:.72rem;white-space:nowrap}.size-price{color:#5e5668}.selected{border:2px solid #6f3bc6;background:#faf7ff;box-shadow:0 0 0 2px #e7ddf7}.size-choice.unavailable,.size-choice:disabled{background:#eceaef;border-color:#d5d1dc;color:#8a8494;cursor:not-allowed;box-shadow:none}.size-choice:disabled .size-label{text-decoration:line-through}.size-status{color:#787182;font-weight:600}.variant-summary{display:grid;gap:6px;padding:12px 14px;background:#f7f5fa;border:1px solid #e5dff0;border-radius:12px;min-width:0}.variant-summary-top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px}.detail-price{font-size:1.3rem;font-weight:800;color:#1f1630;white-space:nowrap}.variant-badges{display:flex;flex-wrap:wrap;gap:6px}.variant-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.8rem;font-weight:600;background:#fff;border:1px solid #dcd5e8;color:#4a4356;white-space:nowrap}.variant-badge.stock{background:#eef8f2;border-color:#bfe3cd;color:#1d683b}.variant-meta{margin:0;font-size:.86rem;overflow-wrap:anywhere}.variant-description{margin:0;font-size:.88rem}.color-choice:focus-visible,.size-choice:focus-visible{outline:3px solid #7044dc;outline-offset:2px}
</style>
