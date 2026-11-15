<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { api, errorMessage } from '../api';
import AppDialog from './AppDialog.vue';

type Link = {id:number; expires_at:string; status:'active'|'expired'|'revoked'};
const props = defineProps<{root:string; orderId:number; disabled:boolean}>();
const open = ref(false); const busy = ref(false); const error = ref('');
const links = ref<Link[]>([]); const hours = ref('24'); const url = ref(''); const copied = ref(false);
const urlLinkId = ref<number|null>(null);
const opener = ref<HTMLElement|null>(null);
const pending = ref<{request_key:string; hours:number}|null>(null);
let alive = true;
const endpoint = `${props.root}/baskets/${props.orderId}/view-links`;
async function load() {
    links.value = (await api.get(endpoint)).data.data;
}
async function show(event:Event) {
    opener.value = event.currentTarget as HTMLElement; open.value = true; busy.value = true; error.value = '';
    try { await load(); } catch(e) { if(alive) error.value = errorMessage(e); }
    finally { if(alive) busy.value = false; }
}
async function generate() {
    if(busy.value || props.disabled) return;
    busy.value = true; error.value = ''; copied.value = false;
    pending.value ??= {request_key:crypto.randomUUID(),hours:Number(hours.value)};
    try {
        const result = (await api.post(endpoint,pending.value)).data.data;
        if(!alive) return;
        pending.value = null;
        url.value = result.path ? new URL(result.path,location.origin).href : '';
        urlLinkId.value = result.link.id;
        links.value = [result.link,...links.value.filter(link=>link.id!==result.link.id)].slice(0,20);
        if(!result.path) error.value = 'El enlace ya no está activo. Puedes generar uno nuevo.';
    } catch(e) { if(alive) error.value = errorMessage(e); }
    finally { if(alive) busy.value = false; }
}
async function revoke(link:Link) {
    if(busy.value || props.disabled) return;
    busy.value = true; error.value = '';
    try {
        const result = (await api.post(`${endpoint}/${link.id}/revoke`)).data.data as Link;
        if(!alive) return;
        links.value = links.value.map(item=>item.id===link.id ? result : item);
        if(urlLinkId.value===link.id) {url.value = ''; urlLinkId.value = null; copied.value = false;}
    } catch(e) { if(alive) error.value = errorMessage(e); }
    finally { if(alive) busy.value = false; }
}
async function copy() {
    try { await navigator.clipboard.writeText(url.value); if(alive) copied.value = true; }
    catch { error.value = 'No se pudo copiar automáticamente. Selecciona y copia el enlace del campo.'; }
}
onUnmounted(()=>{alive=false;});
</script>
<template>
    <button type="button" class="button secondary" :disabled="disabled || busy" @click="show">Compartir enlace</button>
    <AppDialog :open="open" title="Compartir apartado" :busy="busy" :return-focus="opener" @close="open=false">
        <p>El cliente podrá consultar los artículos y los importes guardados de este apartado. Cualquier persona con el enlace podrá verlo hasta su caducidad o revocación.</p>
        <p class="muted">El envío es manual. Los cambios sin guardar no aparecen en el enlace.</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <p v-if="busy" role="status">Actualizando enlaces…</p>
        <label>Caducidad del enlace<select v-model="hours" :disabled="busy || !!pending"><option value="1">1 hora</option><option value="24">24 horas</option><option value="72">3 días</option><option value="168">7 días</option></select></label>
        <button type="button" class="button primary" :disabled="busy || disabled" @click="generate">{{ pending ? 'Reintentar generación' : 'Generar enlace de consulta' }}</button>
        <template v-if="url"><label>Enlace para el cliente<input :value="url" readonly type="url" @focus="($event.target as HTMLInputElement).select()"></label><button type="button" class="button secondary" @click="copy">Copiar enlace</button><p v-if="copied" role="status">Enlace copiado.</p></template>
        <p v-if="pending" class="notice">Generación sin confirmar. Reintentar conserva la misma operación.</p>
        <section aria-label="Enlaces recientes" class="share-link-list"><h3>Enlaces recientes</h3>
            <article v-for="link in links" :key="link.id"><span><strong>Enlace #{{ link.id }}</strong><small>{{ link.status === 'active' ? 'Activo' : link.status === 'revoked' ? 'Revocado' : 'Caducado' }} · {{ new Date(link.expires_at).toLocaleString('es') }}</small></span><button v-if="link.status==='active'" type="button" class="button secondary" :disabled="busy || disabled" :aria-label="`Revocar enlace #${link.id}`" @click="revoke(link)">Revocar</button></article>
            <p v-if="!busy && !links.length" class="muted">Todavía no hay enlaces para este apartado.</p>
        </section>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="open=false">Cerrar</button></template>
    </AppDialog>
</template>
<style scoped>
.share-link-list{margin-top:24px}.share-link-list article{display:flex;gap:12px;justify-content:space-between;align-items:center;border-top:1px solid #e7e0ef;padding:12px 0}.share-link-list span{min-width:0}.share-link-list small{display:block;overflow-wrap:anywhere;color:#665b76}
</style>
