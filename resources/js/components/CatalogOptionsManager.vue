<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions } from '../catalog';
import AppDialog from './AppDialog.vue';

type Kind = 'categories' | 'brands' | 'attributes' | 'values';
type RecordOption = { id: number; name?: string; value?: string; parent_id?: number | null; is_active: boolean };
const props = defineProps<{ options: CatalogOptions }>();
const emit = defineEmits<{ changed: []; back: [] }>();
const kind = ref<Kind>('categories'); const attributeId = ref<number | null>(null);
const selectedAttribute = computed(() => props.options.attributes.find(a => a.id === attributeId.value));
const records = computed<RecordOption[]>(() => kind.value === 'values' ? selectedAttribute.value?.values || [] : props.options[kind.value]);
const labels: Record<Kind, string> = { categories: 'Categoría', brands: 'Marca', attributes: 'Atributo', values: 'Valor' };
const modalOpen = ref(false); const modalKind = ref<Kind>('categories'); const edited = ref<RecordOption | null>(null); const opener = ref<HTMLElement | null>(null);
const busy = ref(false); const error = ref(''); const notice = ref(''); const errorNotice = ref<HTMLElement>();
const form = reactive({ name: '', parent_id: '' as number | '', is_active: true });
function tab(value: Kind) { kind.value = value; attributeId.value = null; notice.value = ''; }
function values(id: number) { attributeId.value = id; kind.value = 'values'; notice.value = ''; }
function open(record: RecordOption | null, event: Event) {
    modalKind.value = kind.value; edited.value = record; opener.value = event.currentTarget as HTMLElement;
    Object.assign(form, { name: record?.name || record?.value || '', parent_id: record?.parent_id || '', is_active: record?.is_active ?? true });
    error.value = ''; modalOpen.value = true;
}
async function save() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const body = { [modalKind.value === 'values' ? 'value' : 'name']: form.name, is_active: form.is_active,
            ...(modalKind.value === 'categories' ? { parent_id: form.parent_id || null } : {}),
            ...(modalKind.value === 'values' && !edited.value ? { attribute_id: attributeId.value } : {}) };
        await api.request({ method: edited.value ? 'patch' : 'post', url: `/business/catalog/${modalKind.value}${edited.value ? `/${edited.value.id}` : ''}`, data: body });
        modalOpen.value = false; notice.value = `${labels[modalKind.value]} guardado correctamente.`; emit('changed');
    } catch (e) { error.value = errorMessage(e); await nextTick(); errorNotice.value?.focus(); }
    finally { busy.value = false; }
}
</script>
<template>
    <button type="button" class="text-button" @click="emit('back')">← Volver al catálogo</button>
    <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">ORGANIZACIÓN DEL NEGOCIO</span><h2>Organizar catálogo</h2><p class="muted">Clasificaciones y opciones reutilizables, sin borrar historial.</p></div></div>
        <div class="catalog-tabs" role="group" aria-label="Opciones del catálogo"><button v-for="item in (['categories', 'brands', 'attributes'] as const)" :key="item" class="button" :class="(kind === item || (kind === 'values' && item === 'attributes')) ? 'primary' : 'secondary'" :aria-pressed="kind === item || (kind === 'values' && item === 'attributes')" @click="tab(item)">{{ { categories: 'Categorías', brands: 'Marcas', attributes: 'Atributos' }[item] }}</button></div>
        <div class="panel-heading catalog-section-heading"><div><button v-if="kind === 'values'" type="button" class="text-button" @click="tab('attributes')">← Atributos</button><h3>{{ kind === 'values' ? `Valores de ${selectedAttribute?.name || ''}` : labels[kind] + 's' }}</h3></div><button class="button primary" :disabled="kind === 'values' && !selectedAttribute?.is_active" @click="open(null, $event)">+ {{ kind === 'values' ? 'Nuevo valor' : kind === 'categories' ? 'Nueva categoría' : kind === 'brands' ? 'Nueva marca' : 'Nuevo atributo' }}</button></div>
        <div class="record-list"><article v-for="record in records" :key="record.id" class="record"><div class="record-main"><strong>{{ record.name || record.value }}</strong><p v-if="kind === 'categories' && record.parent_id">Dentro de {{ options.categories.find(c => c.id === record.parent_id)?.name }}</p><p v-if="kind === 'attributes'">{{ options.attributes.find(a => a.id === record.id)?.values.length || 0 }} valores configurados</p></div><span class="badge" :class="{ inactive: !record.is_active }">{{ record.is_active ? 'Activo' : 'Inactivo' }}</span><button v-if="kind === 'attributes'" class="button secondary" @click="values(record.id)">Valores<span class="sr-only"> de {{ record.name }}</span></button><button class="button secondary" @click="open(record, $event)">Editar<span class="sr-only"> {{ record.name || record.value }}</span></button></article></div>
        <p v-if="!records.length" class="empty">Todavía no hay opciones. Crea la primera para organizar tus productos.</p>
    </section>
    <AppDialog :open="modalOpen" :title="`${edited ? 'Editar' : 'Crear'} ${labels[modalKind].toLowerCase()}`" :return-focus="opener" :busy="busy" @close="modalOpen = false">
        <form id="catalog-option-form" class="form-stack" @submit.prevent="save">
            <fieldset class="form-fields" :disabled="busy">
                <label>{{ modalKind === 'values' ? 'Valor del atributo' : 'Nombre' }}<input v-model="form.name" required :maxlength="modalKind === 'attributes' ? 100 : modalKind === 'values' ? 120 : 130" enterkeyhint="done"></label>
                <label v-if="modalKind === 'categories'">Categoría superior<select v-model="form.parent_id"><option value="">Categoría principal</option><option v-for="category in options.categories.filter(c => c.id !== edited?.id)" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                <label class="check-label"><input v-model="form.is_active" type="checkbox"> Opción activa</label>
                <p class="muted">Desactivar impide nuevas selecciones; conserva los vínculos de productos existentes.</p>
            </fieldset>
            <p v-if="error" ref="errorNotice" tabindex="-1" role="alert" class="notice error">{{ error }}</p>
        </form>
        <template #footer><button type="button" class="button secondary" :disabled="busy" @click="modalOpen = false">Cancelar</button><button type="submit" form="catalog-option-form" class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar opción' }}</button></template>
    </AppDialog>
</template>
