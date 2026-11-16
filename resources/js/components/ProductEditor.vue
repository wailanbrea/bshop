<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions, CatalogProduct, VariantDraft } from '../catalog';
import { emptyVariant, variantPayload } from '../catalog';
import type { Store, Warehouse } from '../types';
import { units } from '../sales';
import { clearPending, readPending, savePending } from '../lib/pendingRequests';
import { forgetPhotos, rememberPhotos } from '../lib/productPhotoRecovery';
import AppDialog from './AppDialog.vue';
import VariantFields from './VariantFields.vue';

const props = defineProps<{ open: boolean; product: CatalogProduct | null; options: CatalogOptions; costs: boolean; opener: HTMLElement | null; actorId:number; store?:Store; warehouses:Warehouse[]; inventoryManage:boolean }>();
const emit = defineEmits<{ close: []; saved: [id: number]; 'photo-recovery':[] }>();
const busy = ref(false); const error = ref(''); const errorNotice = ref<HTMLElement>();
const form = reactive({ name: '', description: '', category_id: '' as number | '', brand_id: '' as number | '', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active' });
const variants = ref<VariantDraft[]>([]); let nextKey = 1;
type PhotoDraft = { file:File; preview:string; key:string; uploaded:boolean; uncertain:boolean };
const photos=ref<PhotoDraft[]>([]);const primaryPhoto=ref('');const progress=ref('');const createdId=ref<number|null>(null);const operation=ref<Record<string,unknown>|null>(null);
const addStock=ref(false);const stockWarehouse=ref<number|''>('');const quantities=ref<Record<number,string>>({});
const journal=computed(()=>`bshop:catalog-create:${props.actorId}`);
const editing = computed(() => props.product !== null);
const locked=computed(()=>busy.value||!!operation.value||createdId.value!==null);
function clearPhotos(){for(const photo of photos.value)URL.revokeObjectURL(photo.preview);photos.value=[];primaryPhoto.value='';}
function choosePhotos(event:Event){const input=event.target as HTMLInputElement;const selected=Array.from(input.files||[]);input.value='';if(busy.value||operation.value)return;error.value='';for(const file of selected){if(photos.value.length>=5){error.value='Se permiten hasta 5 fotografías por producto.';break;}if(file.size>10485760||!['image/jpeg','image/png','image/webp'].includes(file.type)){error.value='Usa JPEG, PNG o WebP de hasta 10 MiB; exporta HEIC a JPEG.';continue;}const key=crypto.randomUUID();photos.value.push({file,preview:URL.createObjectURL(file),key,uploaded:false,uncertain:false});if(!primaryPhoto.value)primaryPhoto.value=key;}}
function removePhoto(key:string){const index=photos.value.findIndex(item=>item.key===key);if(index<0||busy.value||operation.value||photos.value[index].uploaded||photos.value[index].uncertain)return;URL.revokeObjectURL(photos.value[index].preview);photos.value.splice(index,1);if(primaryPhoto.value===key)primaryPhoto.value=photos.value[0]?.key||'';}
async function checkPhoto(photo:PhotoDraft){if(!createdId.value||busy.value)return;busy.value=true;error.value='';try{const result=(await api.get(`/business/products/${createdId.value}/uploads/${photo.key}`)).data.data;if(result.status==='verified'){photo.uploaded=true;photo.uncertain=false;progress.value='La foto ya está publicada.';}else if(result.retryable){photo.uncertain=false;progress.value='La foto no se publicó; puedes corregirla o reintentar.';}else progress.value='La carga sigue pendiente; espera o reintenta el mismo archivo.';}catch(e){if(axios.isAxiosError(e)&&e.response?.status===404){photo.uncertain=false;progress.value='No hay una carga confirmada; puedes corregir la selección.';}else error.value=errorMessage(e);}finally{busy.value=false;}}
watch(() => props.open, open => {
    if (!open) { clearPhotos(); return; }
    error.value = '';
    createdId.value=null;progress.value='';addStock.value=false;quantities.value={};stockWarehouse.value=props.warehouses.find(item=>item.is_active&&item.is_default)?.id||props.warehouses.find(item=>item.is_active)?.id||'';
    Object.assign(form, props.product ? { name: props.product.name, description: props.product.description || '', category_id: props.product.category?.id || '', brand_id: props.product.brand?.id || '', product_type: props.product.product_type, unit_code: props.product.unit_code, track_stock: props.product.track_stock, status: props.product.status } : { name: '', description: '', category_id: '', brand_id: '', product_type: 'physical', unit_code: 'unit', track_stock: true, status: 'active' });
    variants.value = [emptyVariant(nextKey++)];
    operation.value=props.product?null:readPending(journal.value);
}, { immediate: true });
onUnmounted(clearPhotos);
async function save() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        if(editing.value){const body={...form,category_id:form.category_id||null,brand_id:form.brand_id||null,version:props.product!.version};const response=await api.patch(`/business/products/${props.product!.id}`,body);emit('saved',response.data.data.id);emit('close');return;}
        if(createdId.value===null){
            if(!operation.value){const body:Record<string,unknown>={...form,category_id:form.category_id||null,brand_id:form.brand_id||null,variants:variants.value.map(v=>variantPayload(v,props.costs)),request_key:crypto.randomUUID()};
                if(addStock.value&&props.inventoryManage&&form.track_stock&&form.product_type==='physical'){
                    const entries=variants.value.map(v=>({sku:v.sku.trim(),value:(quantities.value[v.key]||'').trim().replace(',','.')})).filter(v=>v.value!=='');
                    if(entries.some(v=>units(v.value)===null)){error.value='Revisa las cantidades iniciales; usa números no negativos con hasta cuatro decimales.';return;}
                    const items=entries.filter(v=>units(v.value)!==0n).map(v=>({sku:v.sku,quantity:v.value,request_key:crypto.randomUUID()}));
                    if(items.length){if(!props.store?.is_active||!stockWarehouse.value){error.value='Selecciona una sucursal y un almacén activos.';return;}body.initial_stock={store_id:props.store.id,warehouse_id:stockWarehouse.value,items};}
                }
                try{savePending(journal.value,body);}catch{error.value='No se pudo guardar la recuperación. No se envió el producto.';return;}operation.value=body;
            }
            const response=await api.post('/business/products',operation.value);createdId.value=response.data.data.id;clearPending(journal.value);operation.value=null;emit('saved',createdId.value!);
        }
        const queue=[...photos.value].sort((a,b)=>Number(b.key===primaryPhoto.value)-Number(a.key===primaryPhoto.value));
        if(queue.some(photo=>!photo.uploaded)){try{rememberPhotos(props.actorId,createdId.value!,form.name);emit('photo-recovery');}catch{error.value='El producto se guardó, pero no se pudo conservar el aviso de fotos. Mantén esta pantalla abierta y reintenta.';return;}}
        for(const photo of queue){if(photo.uploaded)continue;progress.value=`Subiendo foto ${queue.filter(item=>item.uploaded).length+1} de ${queue.length}…`;const body=new FormData();body.append('file',photo.file);body.append('request_key',photo.key);photo.uncertain=true;await api.post(`/business/products/${createdId.value}/images`,body,{timeout:90000,onUploadProgress:event=>{if(event.total)progress.value=`Subiendo foto ${queue.filter(item=>item.uploaded).length+1} de ${queue.length}: ${Math.round(event.loaded*100/event.total)} %`;}});photo.uploaded=true;photo.uncertain=false;}
        try{forgetPhotos(props.actorId,createdId.value!);emit('photo-recovery');}catch{/* Product and photos are already saved; a retained reminder can be dismissed later. */}
        emit('saved',createdId.value!);emit('close');
    } catch (e) { if(createdId.value===null&&axios.isAxiosError(e)&&e.response&&[409,422].includes(e.response.status)){try{clearPending(journal.value);}catch{}operation.value=null;}if(createdId.value!==null)emit('saved',createdId.value);error.value=(createdId.value!==null?'El producto se guardó. La existencia inicial indicada quedó registrada. Reintenta las fotos pendientes. ':'')+errorMessage(e); await nextTick(); errorNotice.value?.focus(); }
    finally { busy.value = false; }
}
</script>
<template>
    <AppDialog :open="open" :title="editing ? 'Editar producto' : 'Nuevo producto'" :busy="busy" :return-focus="opener" @close="emit('close')">
        <form id="product-editor-form" class="form-stack" @submit.prevent="save">
            <p v-if="operation && createdId===null" class="notice" role="status">Hay un guardado pendiente. Reintenta la misma operación; las fotos que no estén seleccionadas deberán añadirse después en la galería.</p>
            <p v-if="createdId!==null" class="notice" role="status">Producto guardado. Puedes reintentar las fotografías pendientes sin crear otro producto.</p>
            <p v-if="progress" role="status">{{ progress }}</p>
            <section v-if="!editing" class="photo-first"><h3>Fotografías del producto</h3><p class="muted">Selecciona hasta 5 fotos del mismo producto. Puedes continuar sin fotos.</p><label class="button secondary photo-select">Seleccionar fotos<input type="file" accept="image/jpeg,image/png,image/webp" multiple :disabled="busy||!!operation" @change="choosePhotos"></label><div class="photo-drafts"><article v-for="(photo,index) in photos" :key="photo.key"><img :src="photo.preview" :alt="`Foto seleccionada ${index+1}`"><label class="check-label"><input v-model="primaryPhoto" type="radio" :value="photo.key" :disabled="busy||!!operation||photos.some(item=>item.uploaded||item.uncertain)"> Principal</label><span v-if="photo.uploaded" class="muted">Publicada</span><button v-if="photo.uncertain" type="button" class="button secondary" :disabled="busy" :aria-label="`Comprobar foto ${index+1}`" @click="checkPhoto(photo)">Comprobar carga</button><button type="button" class="button secondary" :disabled="busy||!!operation||photo.uploaded||photo.uncertain" :aria-label="`Retirar foto seleccionada ${index+1}`" @click="removePhoto(photo.key)">Retirar</button></article></div></section>
            <fieldset class="form-fields" :disabled="locked">
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
                    <section v-if="inventoryManage && form.track_stock && form.product_type==='physical'" class="initial-stock"><h3>Existencia inicial</h3><label class="check-label"><input v-model="addStock" type="checkbox"> Registrar entrada inicial por variante</label><template v-if="addStock"><label>Almacén<select v-model="stockWarehouse"><option value="">Selecciona almacén</option><option v-for="item in warehouses.filter(w=>w.is_active)" :key="item.id" :value="item.id">{{ item.name }}</option></select></label><p class="muted">Las cantidades vacías o cero quedan sin entrada. Se registrará un movimiento por variante.</p><label v-for="(variant,index) in variants" :key="variant.key">{{ variant.sku || `Variante ${index+1}` }} · {{ Object.values(variant.selections).filter(Boolean).map(id=>options.attributes.flatMap(a=>a.values).find(v=>v.id===id)?.value).filter(Boolean).join(' / ') || 'Variante general' }}<input v-model="quantities[variant.key]" inputmode="decimal" maxlength="20" placeholder="0"></label><p class="notice">Resumen: {{ variants.filter(v=>quantities[v.key] && units(quantities[v.key].replace(',','.')) !== 0n).length }} variantes con cantidad indicada en {{ warehouses.find(w=>w.id===stockWarehouse)?.name || 'almacén sin seleccionar' }}.</p></template></section>
                </template>
            </fieldset>
            <p v-if="error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="emit('close')">{{ createdId!==null?'Cerrar':'Cancelar' }}</button><button type="submit" form="product-editor-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : createdId!==null ? 'Reintentar fotos' : operation ? 'Reintentar guardado' : 'Guardar producto' }}</button></template>
    </AppDialog>
</template>
<style scoped>.photo-first,.initial-stock{display:grid;gap:12px;padding:16px;border:1px solid #ddd5ec;border-radius:14px}.photo-select{position:relative;display:inline-flex;align-items:center;justify-content:center;min-height:48px;cursor:pointer}.photo-select input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}.photo-drafts{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px}.photo-drafts article{display:grid;gap:8px;min-width:0}.photo-drafts img{width:100%;height:120px;object-fit:contain;background:#f5f1fc;border-radius:10px}.photo-drafts .check-label,.photo-drafts button{min-height:48px}</style>
