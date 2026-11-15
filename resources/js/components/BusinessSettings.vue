<script setup lang="ts">
import { nextTick, reactive, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { Business, Store, Warehouse } from '../types';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ business: Business; stores: Store[]; warehouses: Warehouse[]; store: Store | undefined; permissions: string[]; page: string }>();
const emit = defineEmits<{ changed: [] }>();
const modal = ref<'store' | 'warehouse' | null>(null);
const editId = ref<number | null>(null);
const busy = ref(false); const error = ref(''); const saved = ref('');
const errorNotice = ref<HTMLElement>();
const dialogOpener = ref<HTMLElement | null>(null);
const storeForm = reactive({ name: '', code: '', address: '', phone: '', is_active: true });
const warehouseForm = reactive({ name: '', is_default: false, is_active: true });
const businessForm = reactive({ name: props.business.name, timezone: props.business.timezone });
watch(() => props.business, business => { businessForm.name = business.name; businessForm.timezone = business.timezone; });
function openStore(store?: Store, event?: Event) {
    dialogOpener.value = event?.currentTarget as HTMLElement || document.activeElement as HTMLElement;
    editId.value = store?.id || null; error.value = ''; saved.value = '';
    Object.assign(storeForm, store ? { name: store.name, code: store.code, address: store.address || '', phone: store.phone || '', is_active: store.is_active } : { name: '', code: '', address: '', phone: '', is_active: true });
    modal.value = 'store';
}
function openWarehouse(warehouse?: Warehouse, event?: Event) {
    dialogOpener.value = event?.currentTarget as HTMLElement || document.activeElement as HTMLElement;
    editId.value = warehouse?.id || null; error.value = ''; saved.value = '';
    Object.assign(warehouseForm, warehouse ? { name: warehouse.name, is_default: warehouse.is_default, is_active: warehouse.is_active } : { name: '', is_default: false, is_active: true });
    modal.value = 'warehouse';
}
async function save() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const base = '/business';
        const url = modal.value === 'store' ? `${base}/stores` : `${base}/stores/${props.store?.id}/warehouses`;
        await api.request({ method: editId.value ? 'patch' : 'post', url: url + (editId.value ? `/${editId.value}` : ''), data: modal.value === 'store' ? storeForm : warehouseForm });
        modal.value = null; saved.value = 'Los cambios se guardaron correctamente.'; emit('changed');
    } catch (e) { error.value = errorMessage(e); }
    finally {
        busy.value = false;
        if(error.value){await nextTick();errorNotice.value?.focus({preventScroll:true});errorNotice.value?.scrollIntoView({block:'nearest',behavior:'instant'});}
    }
}
async function saveBusiness() {
    if (busy.value) return;
    busy.value = true; error.value = ''; saved.value = '';
    try { await api.patch('/business', businessForm); saved.value = 'Negocio actualizado.'; emit('changed'); }
    catch (e) { error.value = errorMessage(e); }
    finally { busy.value = false; }
}
</script>
<template>
    <p v-if="saved" class="notice success" role="status">{{ saved }}</p>
    <p v-if="error && !modal" class="notice error" role="alert">{{ error }}</p>
    <section v-if="page === 'stores'" class="panel">
        <div class="panel-heading"><div><h2>Tus sucursales</h2><p class="muted">Espacios de operación con acceso independiente.</p></div><button v-if="permissions.includes('stores.manage')" class="button primary" @click="openStore(undefined, $event)">+ Nueva sucursal</button></div>
        <div class="record-list"><article v-for="branch in stores" :key="branch.id" class="record"><span class="record-icon">⌂</span><div class="record-main"><strong>{{ branch.name }}</strong><p>{{ branch.code }} <span v-if="branch.address">· {{ branch.address }}</span></p></div><span class="badge" :class="{ inactive: !branch.is_active }">{{ branch.is_active ? 'Activa' : 'Inactiva' }}</span><button v-if="permissions.includes('stores.manage')" class="button secondary" @click="openStore(branch, $event)">Editar<span class="sr-only"> {{ branch.name }}</span></button></article></div>
        <p v-if="!stores.length" class="empty">Aún no tienes sucursales autorizadas. Solicita acceso al propietario.</p>
    </section>
    <section v-if="page === 'warehouses'" class="panel">
        <div class="panel-heading"><div><h2>Almacenes</h2><p class="muted">{{ store ? `Ubicaciones de ${store.name}.` : 'Selecciona una sucursal para continuar.' }}</p></div><button v-if="store && permissions.includes('warehouses.manage')" class="button primary" @click="openWarehouse(undefined, $event)">+ Nuevo almacén</button></div>
        <div class="record-list"><article v-for="warehouse in warehouses" :key="warehouse.id" class="record"><span class="record-icon">▦</span><div class="record-main"><strong>{{ warehouse.name }}</strong><p>{{ warehouse.is_default ? 'Almacén principal' : 'Almacén adicional' }}</p></div><span class="badge" :class="{ inactive: !warehouse.is_active }">{{ warehouse.is_active ? 'Activo' : 'Inactivo' }}</span><button v-if="permissions.includes('warehouses.manage')" class="button secondary" @click="openWarehouse(warehouse, $event)">Editar<span class="sr-only"> {{ warehouse.name }}</span></button></article></div>
        <div v-if="!warehouses.length" class="empty"><h3>Un lugar para cada producto.</h3><p>Crea el primer almacén de esta sucursal para preparar la operación.</p></div>
    </section>
    <section v-if="page === 'settings'" class="panel settings-panel"><span class="eyebrow">IDENTIDAD DEL NEGOCIO</span><h2>Información del negocio</h2><form class="form-stack" @submit.prevent="saveBusiness"><label>Nombre comercial<input v-model="businessForm.name" required maxlength="160" :disabled="!permissions.includes('business.manage')"></label><label>Zona horaria<input v-model="businessForm.timezone" required :disabled="!permissions.includes('business.manage')"></label><div class="readonly-fields"><div><small>SISTEMA</small><strong>BShop</strong></div><div><small>MONEDA</small><strong>{{ business.default_currency }}</strong></div></div><button v-if="permissions.includes('business.manage')" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar cambios' }}</button><p v-else class="muted">Sólo el propietario puede editar estos datos.</p></form></section>

    <AppDialog :open="modal !== null" :return-focus="dialogOpener" :title="`${editId ? 'Editar' : 'Nueva ubicación:'} ${modal === 'store' ? 'sucursal' : 'almacén'}`" :busy="busy" @close="modal = null">
        <form id="location-form" class="form-stack" :aria-describedby="error ? 'location-error' : undefined" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <template v-if="modal === 'store'">
                    <label>Nombre<input v-model="storeForm.name" required maxlength="160" autocomplete="organization" enterkeyhint="next"></label>
                    <label>Código<input v-model="storeForm.code" @input="storeForm.code = storeForm.code.toUpperCase()" required maxlength="32" pattern="[A-Z0-9_\-]+" placeholder="CENTRAL" autocapitalize="characters" autocorrect="off" :spellcheck="false" enterkeyhint="next"></label>
                    <label>Dirección<input v-model="storeForm.address" maxlength="255" autocomplete="street-address" enterkeyhint="next"></label>
                    <label>Teléfono<input v-model="storeForm.phone" type="tel" inputmode="tel" maxlength="40" autocomplete="tel" enterkeyhint="done"></label>
                    <label class="check-label"><input v-model="storeForm.is_active" type="checkbox"> Sucursal activa</label>
                </template>
                <template v-else>
                    <label>Nombre<input v-model="warehouseForm.name" required maxlength="160" enterkeyhint="done"></label>
                    <label class="check-label"><input v-model="warehouseForm.is_default" type="checkbox"> Usar como almacén principal</label>
                    <label class="check-label"><input v-model="warehouseForm.is_active" type="checkbox"> Almacén activo</label>
                    <p class="muted">Al seleccionar un principal, el anterior pasa a ser un almacén adicional.</p>
                </template>
            </fieldset>
            <p v-if="error" id="location-error" ref="errorNotice" tabindex="-1" class="notice error" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <button type="button" class="button secondary" @click="modal = null" :disabled="busy">Cancelar</button>
            <button type="submit" form="location-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar cambios' }}</button>
        </template>
    </AppDialog>
</template>
