<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { api, errorMessage } from '../api';
import type { CatalogOptions } from '../catalog';
import { decimalInput } from '../catalog';
import type { Store, Warehouse } from '../types';
import { units } from '../sales';
import { forgetPhotos, rememberPhotos } from '../lib/productPhotoRecovery';

const props = defineProps<{
    mode: 'single' | 'batch';
    options: CatalogOptions;
    costs: boolean;
    actorId: number;
    store?: Store;
    warehouses: Warehouse[];
    inventoryManage: boolean;
}>();

const emit = defineEmits<{
    close: [];
    saved: [id: number];
    'photo-recovery': [];
}>();

type QuickItem = {
    id: string;
    file: File | null;
    preview: string;
    categoryName: string;
    footwearSystem: 'eu' | 'us';
    name: string;
    costPrice: string;
    salePrice: string;
    quantity: string;
    color: string;
    size: string;
    description: string;
    sku: string;
    requestKey: string;
    stockKey: string;
    photoKey: string;
    productId: number | null;
    photoUploaded: boolean;
    itemError: string;
};

const defaultCategories = ['Ropa', 'Calzado', 'Electrónicos', 'Lentes', 'Joyería', 'Perfumes', 'Cremas', 'Carteras / Bolsos', 'Accesorios', 'Otros'];
const defaultColors = ['Negro', 'Blanco', 'Gris', 'Verde', 'Azul', 'Celeste', 'Rojo', 'Vino', 'Rosado', 'Peach / Salmón', 'Beige', 'Crema', 'Marrón', 'Amarillo', 'Naranja', 'Morado', 'Dorado', 'Plateado', 'Multicolor'];
const clothingSizes = ['XS', 'S', 'M', 'L', 'XL', '2XL'];
const footwearEuSizes = ['35', '36', '37', '38', '39', '40', '41', '42'];
const footwearUsSizes = ['6', '6.5', '7', '7.5', '8', '8.5', '9', '9.5', '10'];

const normalize = (value: string) => value.trim().toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
function buildChoices(defaults: string[], attributeName: string): string[] {
    const existing = props.options.attributes.find(item => normalize(item.name) === attributeName && item.is_active)?.values.filter(item => item.is_active).map(item => item.value) || [];
    const seen = new Set<string>();
    return [...defaults, ...existing].filter(item => {
        const key = normalize(item);
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}
const categoryChoices = computed(() => {
    const existing = (props.options.categories || []).filter(c => c.is_active).map(c => c.name);
    const seen = new Set<string>();
    return [...defaultCategories, ...existing].filter(name => {
        const key = normalize(name);
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
});
const colorChoices = computed(() => buildChoices(defaultColors, 'color'));

type CategoryProfile = {
    showColor: boolean;
    requireColor: boolean;
    showSize: boolean;
    requireSize: boolean;
    isFootwear: boolean;
};

function categoryProfile(categoryName: string): CategoryProfile {
    const clean = normalize(categoryName || 'ropa');
    if (clean.includes('calzado') || clean.includes('zapato') || clean.includes('tenis') || clean.includes('sandalia')) {
        return { showColor: true, requireColor: true, showSize: true, requireSize: true, isFootwear: true };
    }
    if (clean === 'ropa' || clean.includes('vestido') || clean.includes('blusa') || clean.includes('pantalon')) {
        return { showColor: true, requireColor: true, showSize: true, requireSize: true, isFootwear: false };
    }
    if (clean.includes('electronico') || clean.includes('audifono') || clean.includes('bocina')) {
        return { showColor: true, requireColor: true, showSize: false, requireSize: false, isFootwear: false };
    }
    if (clean.includes('lente') || clean.includes('joyer') || clean.includes('pulsera') || clean.includes('anillo') || clean.includes('perfume') || clean.includes('crema')) {
        return { showColor: false, requireColor: false, showSize: false, requireSize: false, isFootwear: false };
    }
    return { showColor: true, requireColor: false, showSize: false, requireSize: false, isFootwear: false };
}

function sizesForItem(item: QuickItem): string[] {
    const profile = categoryProfile(item.categoryName);
    if (!profile.showSize) return [];
    if (profile.isFootwear) {
        return item.footwearSystem === 'eu' ? footwearEuSizes : footwearUsSizes;
    }
    return clothingSizes;
}

const batchCategory = ref('Ropa');

function makeSku(): string {
    return `ART-${crypto.randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase()}`;
}

function createEmptyItem(file: File | null = null, cat = 'Ropa'): QuickItem {
    return {
        id: crypto.randomUUID(),
        file,
        preview: file ? URL.createObjectURL(file) : '',
        categoryName: cat,
        footwearSystem: 'eu',
        name: '',
        costPrice: '',
        salePrice: '',
        quantity: '1',
        color: '',
        size: '',
        description: '',
        sku: makeSku(),
        requestKey: crypto.randomUUID(),
        stockKey: crypto.randomUUID(),
        photoKey: crypto.randomUUID(),
        productId: null,
        photoUploaded: false,
        itemError: '',
    };
}

function syncItemCategory(item: QuickItem) {
    const p = categoryProfile(item.categoryName);
    if (!p.showSize) item.size = '';
    if (!p.showColor) item.color = '';
}

function applyBatchCategory(cat: string) {
    batchCategory.value = cat;
    for (const item of batchItems.value) {
        if (item.productId === null) {
            item.categoryName = cat;
            syncItemCategory(item);
        }
    }
}

const singleItem = ref<QuickItem>(createEmptyItem());
const batchItems = ref<QuickItem[]>([]);
const busy = ref(false);
const error = ref('');
const progress = ref('');

const activeWarehouseId = computed(() =>
    props.warehouses.find(item => item.is_active && item.is_default)?.id ||
    props.warehouses.find(item => item.is_active)?.id ||
    ''
);

function revokeItem(item: QuickItem) {
    if (item.preview) URL.revokeObjectURL(item.preview);
}

function clearAll() {
    revokeItem(singleItem.value);
    for (const item of batchItems.value) revokeItem(item);
    singleItem.value = createEmptyItem();
    batchItems.value = [];
    error.value = '';
    progress.value = '';
}

onUnmounted(clearAll);

watch(() => props.mode, () => {
    error.value = '';
    progress.value = '';
});

function validateFile(file: File): boolean {
    const validMime = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'].includes(file.type) || /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name);
    if (file.size > 25 * 1024 * 1024 || !validMime) {
        error.value = 'Usa imágenes JPEG, PNG o WebP de hasta 25 MiB.';
        return false;
    }
    return true;
}

async function normalizePhotoForUpload(file: File): Promise<File> {
    if (file.size < 250000 && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        return file;
    }
    return new Promise<File>((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            try {
                const maxDim = 1600;
                const w = img.naturalWidth || img.width;
                const h = img.naturalHeight || img.height;
                if (!w || !h) {
                    resolve(file);
                    return;
                }
                const scale = Math.min(1, maxDim / Math.max(w, h));
                const targetW = Math.max(1, Math.round(w * scale));
                const targetH = Math.max(1, Math.round(h * scale));
                const canvas = document.createElement('canvas');
                canvas.width = targetW;
                canvas.height = targetH;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    resolve(file);
                    return;
                }
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, targetW, targetH);
                ctx.drawImage(img, 0, 0, targetW, targetH);
                canvas.toBlob(
                    (blob) => {
                        if (!blob || blob.size === 0) {
                            resolve(file);
                            return;
                        }
                        const cleanName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                        resolve(new File([blob], cleanName, { type: 'image/jpeg' }));
                    },
                    'image/jpeg',
                    0.88,
                );
            } catch {
                resolve(file);
            }
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
}

function chooseSinglePhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || busy.value) return;
    error.value = '';
    if (!validateFile(file)) return;
    if (singleItem.value.preview) URL.revokeObjectURL(singleItem.value.preview);
    singleItem.value.file = file;
    singleItem.value.preview = URL.createObjectURL(file);
    singleItem.value.photoKey = crypto.randomUUID();
    singleItem.value.photoUploaded = false;
}

function removeSinglePhoto() {
    if (busy.value || singleItem.value.photoUploaded) return;
    if (singleItem.value.preview) URL.revokeObjectURL(singleItem.value.preview);
    singleItem.value.file = null;
    singleItem.value.preview = '';
}

function chooseBatchPhotos(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files || []);
    input.value = '';
    if (!files.length || busy.value) return;
    error.value = '';
    for (const file of files) {
        if (batchItems.value.length >= 10) {
            error.value = 'Puedes seleccionar hasta 10 fotos por lote.';
            break;
        }
        if (!validateFile(file)) continue;
        batchItems.value.push(createEmptyItem(file, batchCategory.value));
    }
}

function removeBatchItem(index: number) {
    const item = batchItems.value[index];
    if (!item || busy.value || (item.productId !== null && item.photoUploaded)) return;
    revokeItem(item);
    batchItems.value.splice(index, 1);
}

function validateCard(item: QuickItem, label: string): string | null {
    if (!item.name.trim()) return `Escribe el nombre en ${label}.`;
    if (props.costs && item.costPrice.trim()) {
        const cost = decimalInput(item.costPrice.trim());
        if (!cost || units(cost) === null) return `Indica un precio de compra válido en ${label}.`;
    }
    const sale = decimalInput(item.salePrice.trim());
    if (!sale || units(sale) === null) return `Indica un precio de venta válido en ${label}.`;
    const qty = item.quantity.trim().replace(',', '.');
    if (!qty || units(qty) === null) return `Indica una cantidad válida en ${label}.`;
    const p = categoryProfile(item.categoryName);
    if (p.requireColor && !item.color.trim()) return `Indica el color en ${label}.`;
    if (p.requireSize && !item.size.trim()) return `Indica la talla en ${label}.`;
    return null;
}

async function persistItem(item: QuickItem, indexLabel: string): Promise<number> {
    item.itemError = '';
    if (item.productId === null) {
        progress.value = `Guardando ${indexLabel}: ${item.name.trim()}…`;
        const qty = item.quantity.trim().replace(',', '.');
        const hasPositiveStock = props.inventoryManage && units(qty) !== null && units(qty)! > 0n;
        if (hasPositiveStock && (!props.store?.is_active || !activeWarehouseId.value)) {
            throw new Error('Selecciona una sucursal y un almacén activos para registrar la cantidad.');
        }
        const p = categoryProfile(item.categoryName);
        const costVal = item.costPrice.trim() ? decimalInput(item.costPrice.trim()) : null;
        const body: Record<string, unknown> = {
            name: item.name.trim(),
            description: item.description.trim() || null,
            category_id: null,
            ...(item.categoryName && normalize(item.categoryName) !== 'ropa' ? { category_name: item.categoryName.trim() } : {}),
            brand_id: null,
            product_type: 'physical',
            unit_code: 'unit',
            track_stock: true,
            status: 'active',
            request_key: item.requestKey,
            clothing_variants: [
                {
                    color: p.showColor && item.color.trim() ? item.color.trim() : null,
                    size: p.showSize && item.size.trim() ? item.size.trim() : null,
                    sku: item.sku,
                    description: item.description.trim() || null,
                    sale_price: decimalInput(item.salePrice.trim()),
                    ...(props.costs ? { cost_price: costVal } : {}),
                    is_active: true,
                },
            ],
            ...(hasPositiveStock
                ? {
                      initial_stock: {
                          store_id: props.store!.id,
                          warehouse_id: activeWarehouseId.value,
                          items: [
                              {
                                  sku: item.sku,
                                  quantity: qty,
                                  request_key: item.stockKey,
                              },
                          ],
                      },
                  }
                : {}),
        };
        const response = await api.post('/business/products', body);
        item.productId = response.data.data.id;
    }

    if (item.file && !item.photoUploaded) {
        progress.value = `Subiendo foto de ${item.name.trim()}…`;
        try {
            rememberPhotos(props.actorId, item.productId!, item.name.trim());
            emit('photo-recovery');
        } catch {
            /* Continue upload */
        }
        const normalizedFile = await normalizePhotoForUpload(item.file);
        const uploadAttempt = async (key: string) => {
            const formData = new FormData();
            formData.append('file', normalizedFile);
            formData.append('request_key', key);
            await api.post(`/business/products/${item.productId}/images`, formData, { timeout: 90000 });
        };
        try {
            await uploadAttempt(item.photoKey);
        } catch {
            item.photoKey = crypto.randomUUID();
            await uploadAttempt(item.photoKey);
        }
        item.photoUploaded = true;
        try {
            forgetPhotos(props.actorId, item.productId!);
            emit('photo-recovery');
        } catch {
            /* Ignore cleanup warning */
        }
    } else {
        item.photoUploaded = true;
    }

    return item.productId!;
}

async function submit() {
    if (busy.value) return;
    error.value = '';
    const list = props.mode === 'single' ? [singleItem.value] : batchItems.value;
    if (!list.length) {
        error.value = 'Selecciona al menos una foto para continuar.';
        return;
    }
    for (let i = 0; i < list.length; i++) {
        const label = props.mode === 'single' ? 'el producto' : `la foto ${i + 1}`;
        const validationError = validateCard(list[i], label);
        if (validationError) {
            error.value = validationError;
            return;
        }
    }

    busy.value = true;
    let lastSavedId: number | null = null;
    const errors: string[] = [];
    try {
        for (let i = 0; i < list.length; i++) {
            const item = list[i];
            if (item.productId !== null && item.photoUploaded) {
                lastSavedId = item.productId;
                continue;
            }
            const label = props.mode === 'single' ? 'producto' : `producto ${i + 1} de ${list.length}`;
            try {
                lastSavedId = await persistItem(item, label);
            } catch (itemErr) {
                if (item.productId !== null) {
                    lastSavedId = item.productId;
                }
                const msg = axios.isAxiosError(itemErr) ? errorMessage(itemErr) : (itemErr instanceof Error ? itemErr.message : errorMessage(itemErr));
                item.itemError = msg;
                errors.push(`${item.name.trim() || label}: ${msg}`);
            }
        }
        if (lastSavedId !== null) {
            emit('saved', lastSavedId);
        }
        if (errors.length > 0) {
            error.value = `${errors.join(' · ')}. Pulsa Guardar de nuevo para reintentar las fotos pendientes.`;
        } else {
            emit('close');
        }
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

defineExpose({ submit, busy });
</script>

<template>
    <form id="quick-photo-products-form" class="quick-photo-stack" @submit.prevent="submit">
        <datalist id="quick-catalog-colors">
            <option v-for="c in colorChoices" :key="c" :value="c" />
        </datalist>

        <p v-if="progress" class="notice" role="status">{{ progress }}</p>

        <!-- MODO 1: UNO POR UNO -->
        <template v-if="mode === 'single'">
            <article class="quick-photo-card single-card">
                <div class="quick-photo-box">
                    <img v-if="singleItem.preview" :src="singleItem.preview" alt="Foto seleccionada 1" class="quick-photo-img">
                    <div v-else class="quick-photo-empty">Sin foto seleccionada</div>
                    <div class="quick-photo-actions">
                        <label class="button primary photo-select-btn">
                            {{ singleItem.preview ? 'Cambiar foto' : 'Seleccionar foto' }}
                            <input
                                class="sr-only"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                                :disabled="busy || singleItem.photoUploaded"
                                aria-label="Seleccionar foto del producto"
                                @change="chooseSinglePhoto"
                            >
                        </label>
                        <button
                            v-if="singleItem.preview && !singleItem.photoUploaded"
                            type="button"
                            class="button secondary"
                            :disabled="busy"
                            @click="removeSinglePhoto"
                        >
                            Quitar foto
                        </button>
                    </div>
                </div>

                <div class="quick-fields" :class="{ locked: busy || (singleItem.productId !== null && singleItem.photoUploaded) }">
                    <p v-if="singleItem.itemError" class="notice error" role="alert">{{ singleItem.itemError }}</p>
                    <label>
                        Categoría
                        <select
                            v-model="singleItem.categoryName"
                            :disabled="busy || singleItem.productId !== null"
                            @change="syncItemCategory(singleItem)"
                        >
                            <option v-for="cat in categoryChoices" :key="cat" :value="cat">{{ cat }}</option>
                        </select>
                    </label>
                    <label v-if="categoryProfile(singleItem.categoryName).isFootwear">
                        Sistema de tallas
                        <select
                            v-model="singleItem.footwearSystem"
                            :disabled="busy || singleItem.productId !== null"
                            @change="singleItem.size = ''"
                        >
                            <option value="eu">Europeas (35–42)</option>
                            <option value="us">Regulares (6–10)</option>
                        </select>
                    </label>
                    <label>
                        Nombre
                        <input
                            v-model="singleItem.name"
                            required
                            maxlength="200"
                            :disabled="busy || singleItem.productId !== null"
                            placeholder="Ej. T-shirt Alo"
                        >
                    </label>
                    <div class="quick-grid-2">
                        <label v-if="costs">
                            Precio de compra (opcional)
                            <input
                                v-model="singleItem.costPrice"
                                inputmode="decimal"
                                :disabled="busy || singleItem.productId !== null"
                                placeholder="Opcional — ej. 15.00"
                                @input="singleItem.costPrice = decimalInput(singleItem.costPrice)"
                            >
                        </label>
                        <label>
                            Precio de venta
                            <input
                                v-model="singleItem.salePrice"
                                required
                                inputmode="decimal"
                                :disabled="busy || singleItem.productId !== null"
                                placeholder="Ej. 30.00"
                                @input="singleItem.salePrice = decimalInput(singleItem.salePrice)"
                            >
                        </label>
                    </div>
                    <div class="quick-grid-3" :class="{ 'no-size': !categoryProfile(singleItem.categoryName).showSize, 'only-qty': !categoryProfile(singleItem.categoryName).showColor && !categoryProfile(singleItem.categoryName).showSize }">
                        <label>
                            Cantidad
                            <input
                                v-model="singleItem.quantity"
                                required
                                inputmode="decimal"
                                maxlength="20"
                                :disabled="busy || singleItem.productId !== null"
                                placeholder="1"
                            >
                        </label>
                        <label v-if="categoryProfile(singleItem.categoryName).showColor">
                            Color{{ categoryProfile(singleItem.categoryName).requireColor ? '' : ' (opcional)' }}
                            <input
                                v-model="singleItem.color"
                                :required="categoryProfile(singleItem.categoryName).requireColor"
                                maxlength="120"
                                list="quick-catalog-colors"
                                :disabled="busy || singleItem.productId !== null"
                                placeholder="Ej. Negro"
                            >
                        </label>
                        <label v-if="categoryProfile(singleItem.categoryName).showSize">
                            Size
                            <input
                                v-model="singleItem.size"
                                required
                                maxlength="120"
                                :list="`quick-sizes-${singleItem.id}`"
                                :disabled="busy || singleItem.productId !== null"
                                :placeholder="categoryProfile(singleItem.categoryName).isFootwear ? (singleItem.footwearSystem === 'eu' ? 'Ej. 38' : 'Ej. 7.5') : 'Ej. M'"
                            >
                            <datalist :id="`quick-sizes-${singleItem.id}`">
                                <option v-for="s in sizesForItem(singleItem)" :key="s" :value="s" />
                            </datalist>
                        </label>
                    </div>
                    <div v-if="categoryProfile(singleItem.categoryName).showSize" class="size-chips" role="group" aria-label="Tallas disponibles">
                        <button
                            v-for="s in sizesForItem(singleItem)"
                            :key="s"
                            type="button"
                            class="size-chip"
                            :class="{ active: normalize(singleItem.size) === normalize(s) }"
                            :disabled="busy || singleItem.productId !== null"
                            @click="singleItem.size = s"
                        >
                            {{ s }}
                        </button>
                    </div>
                    <label>
                        Descripción (opcional)
                        <textarea
                            v-model="singleItem.description"
                            rows="2"
                            maxlength="2000"
                            :disabled="busy || singleItem.productId !== null"
                            placeholder="Opcional — puedes dejarla vacía"
                        ></textarea>
                    </label>
                </div>
            </article>
        </template>

        <!-- MODO 2: HASTA 10 FOTOS -->
        <template v-else>
            <div class="batch-toolbar">
                <div>
                    <strong>Fotos seleccionadas: {{ batchItems.length }} / 10</strong>
                    <p class="muted">Cada foto se guardará como un producto independiente con los datos que pongas debajo.</p>
                </div>
                <div class="batch-toolbar-controls">
                    <label class="batch-cat-label">
                        Categoría del lote
                        <select
                            :value="batchCategory"
                            :disabled="busy"
                            @change="applyBatchCategory(($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="cat in categoryChoices" :key="cat" :value="cat">{{ cat }}</option>
                        </select>
                    </label>
                    <label v-if="batchItems.length < 10" class="button primary photo-select-btn">
                        Seleccionar hasta 10 fotos
                        <input
                            class="sr-only"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                            multiple
                            :disabled="busy"
                            aria-label="Seleccionar hasta 10 fotos"
                            @change="chooseBatchPhotos"
                        >
                    </label>
                </div>
            </div>

            <p v-if="!batchItems.length" class="empty">
                Selecciona de 1 a 10 fotos para completar Nombre, Precio de compra, Precio de venta, Cantidad, Color y Size debajo de cada una.
            </p>

            <div v-else class="batch-photo-grid">
                <article v-for="(item, index) in batchItems" :key="item.id" class="quick-photo-card">
                    <div class="quick-card-header">
                        <strong>Foto {{ index + 1 }}</strong>
                        <span v-if="item.productId !== null && item.photoUploaded" class="badge">Guardado</span>
                        <span v-else-if="item.productId !== null" class="badge">Producto guardado · falta foto</span>
                        <button
                            v-else
                            type="button"
                            class="text-button"
                            :disabled="busy"
                            :aria-label="`Quitar foto ${index + 1}`"
                            @click="removeBatchItem(index)"
                        >
                            Quitar
                        </button>
                    </div>

                    <img :src="item.preview" :alt="`Foto de producto ${index + 1}`" class="quick-photo-img">
                    <p v-if="item.itemError" class="notice error" role="alert">{{ item.itemError }}</p>

                    <div class="quick-fields">
                        <label>
                            Categoría (foto {{ index + 1 }})
                            <select
                                v-model="item.categoryName"
                                :disabled="busy || item.productId !== null"
                                @change="syncItemCategory(item)"
                            >
                                <option v-for="cat in categoryChoices" :key="cat" :value="cat">{{ cat }}</option>
                            </select>
                        </label>
                        <label v-if="categoryProfile(item.categoryName).isFootwear">
                            Sistema de tallas (foto {{ index + 1 }})
                            <select
                                v-model="item.footwearSystem"
                                :disabled="busy || item.productId !== null"
                                @change="item.size = ''"
                            >
                                <option value="eu">Europeas (35–42)</option>
                                <option value="us">Regulares (6–10)</option>
                            </select>
                        </label>
                        <label>
                            Nombre (foto {{ index + 1 }})
                            <input
                                v-model="item.name"
                                required
                                maxlength="200"
                                :disabled="busy || item.productId !== null"
                                placeholder="Ej. T-shirt Alo"
                            >
                        </label>
                        <div class="quick-grid-2">
                            <label v-if="costs">
                                Precio de compra (foto {{ index + 1 }})
                                <input
                                    v-model="item.costPrice"
                                    inputmode="decimal"
                                    :disabled="busy || item.productId !== null"
                                    placeholder="Opcional — ej. 15.00"
                                    @input="item.costPrice = decimalInput(item.costPrice)"
                                >
                            </label>
                            <label>
                                Precio de venta (foto {{ index + 1 }})
                                <input
                                    v-model="item.salePrice"
                                    required
                                    inputmode="decimal"
                                    :disabled="busy || item.productId !== null"
                                    placeholder="Ej. 30.00"
                                    @input="item.salePrice = decimalInput(item.salePrice)"
                                >
                            </label>
                        </div>
                        <div class="quick-grid-3" :class="{ 'no-size': !categoryProfile(item.categoryName).showSize, 'only-qty': !categoryProfile(item.categoryName).showColor && !categoryProfile(item.categoryName).showSize }">
                            <label>
                                Cantidad (foto {{ index + 1 }})
                                <input
                                    v-model="item.quantity"
                                    required
                                    inputmode="decimal"
                                    maxlength="20"
                                    :disabled="busy || item.productId !== null"
                                    placeholder="1"
                                >
                            </label>
                            <label v-if="categoryProfile(item.categoryName).showColor">
                                Color (foto {{ index + 1 }})
                                <input
                                    v-model="item.color"
                                    :required="categoryProfile(item.categoryName).requireColor"
                                    maxlength="120"
                                    list="quick-catalog-colors"
                                    :disabled="busy || item.productId !== null"
                                    placeholder="Ej. Negro"
                                >
                            </label>
                            <label v-if="categoryProfile(item.categoryName).showSize">
                                Size (foto {{ index + 1 }})
                                <input
                                    v-model="item.size"
                                    required
                                    maxlength="120"
                                    :list="`quick-sizes-${item.id}`"
                                    :disabled="busy || item.productId !== null"
                                    :placeholder="categoryProfile(item.categoryName).isFootwear ? (item.footwearSystem === 'eu' ? 'Ej. 38' : 'Ej. 7.5') : 'Ej. M'"
                                >
                                <datalist :id="`quick-sizes-${item.id}`">
                                    <option v-for="s in sizesForItem(item)" :key="s" :value="s" />
                                </datalist>
                            </label>
                        </div>
                        <div v-if="categoryProfile(item.categoryName).showSize" class="size-chips" role="group" :aria-label="`Tallas foto ${index + 1}`">
                            <button
                                v-for="s in sizesForItem(item)"
                                :key="s"
                                type="button"
                                class="size-chip"
                                :class="{ active: normalize(item.size) === normalize(s) }"
                                :disabled="busy || item.productId !== null"
                                @click="item.size = s"
                            >
                                {{ s }}
                            </button>
                        </div>
                        <label>
                            Descripción (foto {{ index + 1 }}, opcional)
                            <textarea
                                v-model="item.description"
                                rows="2"
                                maxlength="2000"
                                :disabled="busy || item.productId !== null"
                                placeholder="Opcional — puedes dejarla vacía"
                            ></textarea>
                        </label>
                    </div>
                </article>
            </div>
        </template>

        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    </form>
</template>

<style scoped>
.quick-photo-stack {
    display: grid;
    gap: 14px;
    min-width: 0;
}
.batch-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: #f7f5fa;
    border: 1px solid #e3dcef;
    border-radius: 12px;
}
.batch-toolbar p {
    margin: 4px 0 0;
}
.batch-toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: end;
}
.batch-cat-label {
    min-width: 170px;
}
.quick-grid-3.no-size {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.quick-grid-3.only-qty {
    grid-template-columns: minmax(0, 1fr);
}
.size-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.size-chip {
    min-height: 36px;
    min-width: 40px;
    padding: 4px 10px;
    border: 1px solid #d5c9ea;
    border-radius: 999px;
    background: #fff;
    color: #322745;
    font-weight: 600;
    cursor: pointer;
}
.size-chip.active {
    background: #7050ca;
    border-color: #7050ca;
    color: #fff;
}
.photo-select-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    cursor: pointer;
    white-space: nowrap;
}
.photo-select-btn:focus-within {
    outline: 3px solid #8d53ff;
    outline-offset: 3px;
}
.batch-photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 16px;
    min-width: 0;
}
.quick-photo-card {
    display: grid;
    align-content: start;
    gap: 12px;
    padding: 14px;
    border: 1px solid #ddd5ec;
    border-radius: 14px;
    background: #fff;
    min-width: 0;
}
.quick-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.quick-photo-box {
    display: grid;
    gap: 10px;
}
.quick-photo-img {
    width: 100%;
    height: 190px;
    object-fit: contain;
    background: #f7f5fa;
    border-radius: 10px;
    border: 1px solid #ece7f5;
}
.quick-photo-empty {
    display: grid;
    place-items: center;
    height: 150px;
    background: #f7f5fa;
    border: 1px dashed #c9bfe0;
    border-radius: 10px;
    color: #6f687a;
    font-size: 0.92rem;
}
.quick-photo-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.quick-fields {
    display: grid;
    gap: 10px;
    min-width: 0;
}
.quick-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    min-width: 0;
}
.quick-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    min-width: 0;
}
.quick-fields input,
.quick-fields textarea {
    font-size: 16px;
    min-width: 0;
    width: 100%;
}
@media (max-width: 520px) {
    .batch-photo-grid,
    .quick-grid-2,
    .quick-grid-3 {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
