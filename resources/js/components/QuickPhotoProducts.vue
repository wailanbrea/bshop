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
    sizeQuantities: Record<string, string>;
    sizeSkus: Record<string, string>;
    sizeStockKeys: Record<string, string>;
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
const batchFootwearSystem = ref<'eu' | 'us'>('eu');
const batchColumns = ref<2 | 4>(2);
const batchGrouping = ref<'separate' | 'same'>('separate');
const batchSharedName = ref('');
const batchSharedDescription = ref('');
const batchSharedProductId = ref<number | null>(null);
const batchSharedRequestKey = ref(crypto.randomUUID());
const batchVariantIdMap = ref<Record<string, number>>({});

function makeSku(): string {
    return `ART-${crypto.randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase()}`;
}

function createEmptyItem(file: File | null = null, cat = 'Ropa', footwear: 'eu' | 'us' = 'eu'): QuickItem {
    return {
        id: crypto.randomUUID(),
        file,
        preview: file ? URL.createObjectURL(file) : '',
        categoryName: cat,
        footwearSystem: footwear,
        name: '',
        costPrice: '',
        salePrice: '',
        quantity: '1',
        color: '',
        size: '',
        sizeQuantities: {},
        sizeSkus: {},
        sizeStockKeys: {},
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

function selectedSizes(item: QuickItem): string[] {
    return Object.keys(item.sizeQuantities);
}

function toggleSizeChip(item: QuickItem, size: string) {
    if (busy.value || item.productId !== null) return;
    if (item.sizeQuantities[size] !== undefined) {
        const next = { ...item.sizeQuantities };
        delete next[size];
        item.sizeQuantities = next;
    } else {
        item.sizeQuantities = { ...item.sizeQuantities, [size]: '1' };
        if (!item.sizeSkus[size]) item.sizeSkus = { ...item.sizeSkus, [size]: makeSku() };
        if (!item.sizeStockKeys[size]) item.sizeStockKeys = { ...item.sizeStockKeys, [size]: crypto.randomUUID() };
    }
}

function sizeTotalUnits(item: QuickItem): number {
    return selectedSizes(item).reduce((sum, s) => {
        const n = Number((item.sizeQuantities[s] || '0').replace(',', '.'));
        return sum + (Number.isFinite(n) && n > 0 ? n : 0);
    }, 0);
}

function syncItemCategory(item: QuickItem) {
    const p = categoryProfile(item.categoryName);
    if (!p.showSize) {
        item.size = '';
        item.sizeQuantities = {};
    }
    if (!p.showColor) item.color = '';
}

function applyBatchCategory(cat: string) {
    batchCategory.value = cat;
    for (const item of batchItems.value) {
        if (item.productId === null) {
            item.categoryName = cat;
            item.footwearSystem = batchFootwearSystem.value;
            syncItemCategory(item);
        }
    }
}

function applyBatchFootwearSystem(sys: 'eu' | 'us') {
    batchFootwearSystem.value = sys;
    for (const item of batchItems.value) {
        if (item.productId === null) {
            item.footwearSystem = sys;
            item.size = '';
            item.sizeQuantities = {};
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
    batchGrouping.value = 'separate';
    batchSharedName.value = '';
    batchSharedDescription.value = '';
    batchSharedProductId.value = null;
    batchSharedRequestKey.value = crypto.randomUUID();
    batchVariantIdMap.value = {};
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
        if (batchItems.value.length >= 20) {
            error.value = 'Puedes seleccionar hasta 20 fotos por lote.';
            break;
        }
        if (!validateFile(file)) continue;
        batchItems.value.push(createEmptyItem(file, batchCategory.value, batchFootwearSystem.value));
    }
}

function removeBatchItem(index: number) {
    const item = batchItems.value[index];
    if (!item || busy.value || (item.productId !== null && item.photoUploaded)) return;
    revokeItem(item);
    batchItems.value.splice(index, 1);
}

function validateCard(item: QuickItem, label: string, requireName = true): string | null {
    if (requireName && !item.name.trim()) return `Escribe el nombre en ${label}.`;
    if (props.costs && item.costPrice.trim()) {
        const cost = decimalInput(item.costPrice.trim());
        if (!cost || units(cost) === null) return `Indica un precio de compra válido en ${label}.`;
    }
    const sale = decimalInput(item.salePrice.trim());
    if (!sale || units(sale) === null) return `Indica un precio de venta válido en ${label}.`;
    const p = categoryProfile(item.categoryName);
    if (p.requireColor && !item.color.trim()) return `Indica el color en ${label}.`;
    const multiSizes = p.showSize ? selectedSizes(item) : [];
    if (multiSizes.length > 0) {
        for (const s of multiSizes) {
            const q = (item.sizeQuantities[s] || '').trim().replace(',', '.');
            if (!q || units(q) === null) return `Indica una cantidad válida para talla ${s} en ${label}.`;
        }
    } else {
        const qty = item.quantity.trim().replace(',', '.');
        if (!qty || units(qty) === null) return `Indica una cantidad válida en ${label}.`;
        if (p.requireSize && !item.size.trim()) return `Selecciona al menos una talla en ${label}.`;
    }
    return null;
}

function buildItemVariants(item: QuickItem) {
    const p = categoryProfile(item.categoryName);
    const costVal = item.costPrice.trim() ? decimalInput(item.costPrice.trim()) : null;
    const saleVal = decimalInput(item.salePrice.trim());
    const multiSizes = p.showSize ? selectedSizes(item) : [];
    const variants: Record<string, unknown>[] = [];
    const stockItems: { sku: string; quantity: string; request_key: string }[] = [];

    if (multiSizes.length > 0) {
        for (const s of multiSizes) {
            const sku = item.sizeSkus[s] || makeSku();
            item.sizeSkus[s] = sku;
            const stockKey = item.sizeStockKeys[s] || crypto.randomUUID();
            item.sizeStockKeys[s] = stockKey;
            const qty = (item.sizeQuantities[s] || '0').trim().replace(',', '.');
            variants.push({
                color: p.showColor && item.color.trim() ? item.color.trim() : null,
                size: s,
                sku,
                description: item.description.trim() || null,
                sale_price: saleVal,
                ...(props.costs ? { cost_price: costVal } : {}),
                is_active: true,
            });
            if (props.inventoryManage && units(qty) !== null && units(qty)! > 0n) {
                stockItems.push({ sku, quantity: qty, request_key: stockKey });
            }
        }
    } else {
        const qty = item.quantity.trim().replace(',', '.');
        variants.push({
            color: p.showColor && item.color.trim() ? item.color.trim() : null,
            size: p.showSize && item.size.trim() ? item.size.trim() : null,
            sku: item.sku,
            description: item.description.trim() || null,
            sale_price: saleVal,
            ...(props.costs ? { cost_price: costVal } : {}),
            is_active: true,
        });
        if (props.inventoryManage && units(qty) !== null && units(qty)! > 0n) {
            stockItems.push({ sku: item.sku, quantity: qty, request_key: item.stockKey });
        }
    }

    return { variants, stockItems, primarySku: String(variants[0]?.sku || item.sku) };
}

async function persistItem(item: QuickItem, indexLabel: string): Promise<number> {
    item.itemError = '';
    if (item.productId === null) {
        progress.value = `Guardando ${indexLabel}: ${item.name.trim()}…`;
        const built = buildItemVariants(item);
        if (built.stockItems.length > 0 && (!props.store?.is_active || !activeWarehouseId.value)) {
            throw new Error('Selecciona una sucursal y un almacén activos para registrar la cantidad.');
        }
        const body: Record<string, unknown> = {
            store_id: props.store?.id || undefined,
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
            clothing_variants: built.variants,
            ...(built.stockItems.length > 0
                ? {
                      initial_stock: {
                          store_id: props.store!.id,
                          warehouse_id: activeWarehouseId.value,
                          items: built.stockItems,
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

async function submitSharedBatch() {
    const list = batchItems.value;
    const sharedName = batchSharedName.value.trim();
    if (!sharedName) {
        error.value = 'Escribe el nombre del producto arriba para guardar todas las fotos juntas.';
        return;
    }
    if (list.length > 20) {
        error.value = 'Un mismo producto acepta hasta 20 fotos por lote.';
        return;
    }
    for (let i = 0; i < list.length; i++) {
        const validationError = validateCard(list[i], `la foto ${i + 1}`, false);
        if (validationError) {
            error.value = validationError;
            return;
        }
    }

    busy.value = true;
    const errors: string[] = [];
    try {
        if (batchSharedProductId.value === null) {
            const stockItems: { sku: string; quantity: string; request_key: string }[] = [];
            const clothingVariants: Record<string, unknown>[] = [];
            for (const item of list) {
                const built = buildItemVariants(item);
                clothingVariants.push(...built.variants);
                stockItems.push(...built.stockItems);
            }
            progress.value = `Guardando ${sharedName} con ${clothingVariants.length} ${clothingVariants.length === 1 ? 'variante' : 'variantes'}…`;
            if (stockItems.length > 0 && (!props.store?.is_active || !activeWarehouseId.value)) {
                throw new Error('Selecciona una sucursal y un almacén activos para registrar la cantidad.');
            }
            const body: Record<string, unknown> = {
                store_id: props.store?.id || undefined,
                name: sharedName,
                description: batchSharedDescription.value.trim() || null,
                category_id: null,
                ...(batchCategory.value && normalize(batchCategory.value) !== 'ropa' ? { category_name: batchCategory.value.trim() } : {}),
                brand_id: null,
                product_type: 'physical',
                unit_code: 'unit',
                track_stock: true,
                status: 'active',
                request_key: batchSharedRequestKey.value,
                clothing_variants: clothingVariants,
                ...(stockItems.length > 0
                    ? {
                          initial_stock: {
                              store_id: props.store!.id,
                              warehouse_id: activeWarehouseId.value,
                              items: stockItems,
                          },
                      }
                    : {}),
            };
            const response = await api.post('/business/products', body);
            batchSharedProductId.value = response.data.data.id;
            for (const item of list) item.productId = batchSharedProductId.value;
            try {
                const detailResp = await api.get(`/business/products/${batchSharedProductId.value}`, { params: { page: 1 } });
                const createdVariants: { id: number; sku: string }[] = detailResp.data?.data?.variants?.data || [];
                const map: Record<string, number> = {};
                for (const v of createdVariants) {
                    if (v?.sku && v?.id) map[v.sku.toUpperCase()] = v.id;
                }
                batchVariantIdMap.value = map;
            } catch {
                /* Fallback: upload photos without variant link if detail fetch fails */
            }
        }

        const productId = batchSharedProductId.value!;
        for (let i = 0; i < list.length; i++) {
            const item = list[i];
            item.productId = productId;
            if (!item.file || item.photoUploaded) {
                item.photoUploaded = true;
                continue;
            }
            item.itemError = '';
            progress.value = `Subiendo foto ${i + 1} de ${list.length}…`;
            try {
                const normalizedFile = await normalizePhotoForUpload(item.file);
                const primarySku = buildItemVariants(item).primarySku;
                const variantId = batchVariantIdMap.value[primarySku.toUpperCase()];
                const uploadAttempt = async (key: string) => {
                    const formData = new FormData();
                    formData.append('file', normalizedFile);
                    formData.append('request_key', key);
                    if (variantId) formData.append('variant_id', String(variantId));
                    await api.post(`/business/products/${productId}/images`, formData, { timeout: 90000 });
                };
                try {
                    await uploadAttempt(item.photoKey);
                } catch {
                    item.photoKey = crypto.randomUUID();
                    await uploadAttempt(item.photoKey);
                }
                item.photoUploaded = true;
            } catch (itemErr) {
                const msg = axios.isAxiosError(itemErr) ? errorMessage(itemErr) : (itemErr instanceof Error ? itemErr.message : errorMessage(itemErr));
                item.itemError = msg;
                errors.push(`Foto ${i + 1}: ${msg}`);
            }
        }

        emit('saved', productId);
        if (errors.length > 0) {
            error.value = `${errors.join(' · ')}. Pulsa Guardar de nuevo para reintentar las fotos pendientes.`;
        } else {
            emit('close');
        }
    } catch (err) {
        error.value = axios.isAxiosError(err) ? errorMessage(err) : (err instanceof Error ? err.message : errorMessage(err));
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

async function submit() {
    if (busy.value) return;
    error.value = '';
    const list = props.mode === 'single' ? [singleItem.value] : batchItems.value;
    if (!list.length) {
        error.value = 'Selecciona al menos una foto para continuar.';
        return;
    }
    if (props.mode === 'batch' && batchGrouping.value === 'same') {
        await submitSharedBatch();
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
                    <div class="quick-grid-3" :class="{ 'no-size': !categoryProfile(singleItem.categoryName).showSize || selectedSizes(singleItem).length > 0, 'only-qty': (!categoryProfile(singleItem.categoryName).showColor && !categoryProfile(singleItem.categoryName).showSize) || (selectedSizes(singleItem).length > 0 && categoryProfile(singleItem.categoryName).showColor) }">
                        <label v-if="selectedSizes(singleItem).length === 0">
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
                        <label v-if="categoryProfile(singleItem.categoryName).showSize && selectedSizes(singleItem).length === 0">
                            Size
                            <input
                                v-model="singleItem.size"
                                :required="selectedSizes(singleItem).length === 0"
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
                    <div v-if="categoryProfile(singleItem.categoryName).showSize" class="size-picker-block">
                        <div class="size-picker-label">
                            <span>Tallas disponibles (toca varias para indicar cantidad por talla)</span>
                            <small v-if="selectedSizes(singleItem).length > 0">{{ selectedSizes(singleItem).length }} {{ selectedSizes(singleItem).length === 1 ? 'talla' : 'tallas' }} · {{ sizeTotalUnits(singleItem) }} unid.</small>
                        </div>
                        <div class="size-chips" role="group" aria-label="Tallas disponibles">
                            <button
                                v-for="s in sizesForItem(singleItem)"
                                :key="s"
                                type="button"
                                class="size-chip"
                                :class="{ active: singleItem.sizeQuantities[s] !== undefined }"
                                :aria-pressed="singleItem.sizeQuantities[s] !== undefined"
                                :disabled="busy || singleItem.productId !== null"
                                @click="toggleSizeChip(singleItem, s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                        <div v-if="selectedSizes(singleItem).length > 0" class="size-qty-grid" role="group" aria-label="Cantidad por talla">
                            <div v-for="s in selectedSizes(singleItem)" :key="s" class="size-qty-item">
                                <span class="size-qty-badge">{{ s }}</span>
                                <input
                                    v-model="singleItem.sizeQuantities[s]"
                                    required
                                    inputmode="decimal"
                                    maxlength="20"
                                    :aria-label="`Cantidad talla ${s}`"
                                    :disabled="busy || singleItem.productId !== null"
                                    placeholder="1"
                                >
                                <button type="button" class="size-qty-rm" :aria-label="`Quitar talla ${s}`" :disabled="busy || singleItem.productId !== null" @click="toggleSizeChip(singleItem, s)">×</button>
                            </div>
                        </div>
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
                    <strong>Fotos seleccionadas: {{ batchItems.length }} / 20</strong>
                    <p class="muted">Elige la categoría una sola vez aquí arriba y aplicará para todas las fotos del lote.</p>
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
                    <label v-if="categoryProfile(batchCategory).isFootwear" class="batch-cat-label">
                        Sistema de tallas
                        <select
                            :value="batchFootwearSystem"
                            :disabled="busy"
                            @change="applyBatchFootwearSystem(($event.target as HTMLSelectElement).value as 'eu' | 'us')"
                        >
                            <option value="eu">Europeas (35–42)</option>
                            <option value="us">Regulares (6–10)</option>
                        </select>
                    </label>
                    <div class="batch-cols-toggle" role="group" aria-label="Cómo guardar las fotos">
                        <button type="button" class="button" :class="batchGrouping === 'separate' ? 'primary' : 'secondary'" :aria-pressed="batchGrouping === 'separate'" :disabled="busy || batchSharedProductId !== null" @click="batchGrouping = 'separate'">Productos separados</button>
                        <button type="button" class="button" :class="batchGrouping === 'same' ? 'primary' : 'secondary'" :aria-pressed="batchGrouping === 'same'" :disabled="busy || batchSharedProductId !== null" @click="batchGrouping = 'same'">Mismo producto (variantes)</button>
                    </div>
                    <div class="batch-cols-toggle" role="group" aria-label="Productos por fila">
                        <button type="button" class="button" :class="batchColumns === 2 ? 'primary' : 'secondary'" :aria-pressed="batchColumns === 2" @click="batchColumns = 2">Ver 2</button>
                        <button type="button" class="button" :class="batchColumns === 4 ? 'primary' : 'secondary'" :aria-pressed="batchColumns === 4" @click="batchColumns = 4">Ver 4</button>
                    </div>
                    <label v-if="batchItems.length < 20" class="button primary photo-select-btn">
                        Seleccionar hasta 20 fotos
                        <input
                            class="sr-only"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                            multiple
                            :disabled="busy"
                            aria-label="Seleccionar hasta 20 fotos"
                            @change="chooseBatchPhotos"
                        >
                    </label>
                </div>
            </div>

            <div v-if="batchGrouping === 'same'" class="batch-shared-header">
                <div class="quick-grid-2">
                    <label>
                        Nombre del producto
                        <input
                            v-model="batchSharedName"
                            required
                            maxlength="200"
                            :disabled="busy || batchSharedProductId !== null"
                            placeholder="Ej. Audífonos inalámbricos"
                        >
                    </label>
                    <label>
                        Descripción general (opcional)
                        <input
                            v-model="batchSharedDescription"
                            maxlength="500"
                            :disabled="busy || batchSharedProductId !== null"
                            placeholder="Opcional — aplica al producto completo"
                        >
                    </label>
                </div>
                <p class="muted">Todas las fotos de abajo se guardarán juntas dentro de este producto (hasta 20 fotos). Cada foto tendrá su propio color/talla, cantidad y precio.</p>
            </div>

            <p v-if="!batchItems.length" class="empty">
                Elige la categoría arriba y selecciona de 1 a 20 fotos para completar los datos debajo de cada una.
            </p>

            <div v-else class="batch-photo-grid" :class="`cols-${batchColumns}`">
                <article v-for="(item, index) in batchItems" :key="item.id" class="quick-photo-card">
                    <div class="quick-card-header">
                        <strong>{{ batchGrouping === 'same' ? `Variante ${index + 1}` : `Foto ${index + 1}` }} · {{ batchCategory }}</strong>
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
                        <label v-if="batchGrouping === 'separate'">
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
                        <div class="quick-grid-3" :class="{ 'no-size': !categoryProfile(item.categoryName).showSize || selectedSizes(item).length > 0, 'only-qty': (!categoryProfile(item.categoryName).showColor && !categoryProfile(item.categoryName).showSize) || (selectedSizes(item).length > 0 && categoryProfile(item.categoryName).showColor) }">
                            <label v-if="selectedSizes(item).length === 0">
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
                            <label v-if="categoryProfile(item.categoryName).showSize && selectedSizes(item).length === 0">
                                Size (foto {{ index + 1 }})
                                <input
                                    v-model="item.size"
                                    :required="selectedSizes(item).length === 0"
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
                        <div v-if="categoryProfile(item.categoryName).showSize" class="size-picker-block">
                            <div class="size-picker-label">
                                <span>Tallas (toca varias)</span>
                                <small v-if="selectedSizes(item).length > 0">{{ selectedSizes(item).length }}t · {{ sizeTotalUnits(item) }} u.</small>
                            </div>
                            <div class="size-chips" role="group" :aria-label="`Tallas foto ${index + 1}`">
                                <button
                                    v-for="s in sizesForItem(item)"
                                    :key="s"
                                    type="button"
                                    class="size-chip"
                                    :class="{ active: item.sizeQuantities[s] !== undefined }"
                                    :aria-pressed="item.sizeQuantities[s] !== undefined"
                                    :disabled="busy || item.productId !== null"
                                    @click="toggleSizeChip(item, s)"
                                >
                                    {{ s }}
                                </button>
                            </div>
                            <div v-if="selectedSizes(item).length > 0" class="size-qty-grid" role="group" :aria-label="`Cantidad por talla foto ${index + 1}`">
                                <div v-for="s in selectedSizes(item)" :key="s" class="size-qty-item">
                                    <span class="size-qty-badge">{{ s }}</span>
                                    <input
                                        v-model="item.sizeQuantities[s]"
                                        required
                                        inputmode="decimal"
                                        maxlength="20"
                                        :aria-label="`Cantidad talla ${s} (foto ${index + 1})`"
                                        :disabled="busy || item.productId !== null"
                                        placeholder="1"
                                    >
                                    <button type="button" class="size-qty-rm" :aria-label="`Quitar talla ${s} (foto ${index + 1})`" :disabled="busy || item.productId !== null" @click="toggleSizeChip(item, s)">×</button>
                                </div>
                            </div>
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
.batch-shared-header {
    display: grid;
    gap: 8px;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #d7c7f0;
    border-radius: 12px;
}
.batch-shared-header p {
    margin: 0;
}
.batch-toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: end;
    width: 100%;
    min-width: 0;
}
.batch-cat-label {
    min-width: 150px;
    max-width: 100%;
    flex: 1 1 160px;
}
.batch-cols-toggle {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 100%;
}
.batch-cols-toggle .button {
    min-height: 44px;
    padding: 0 12px;
    max-width: 100%;
    white-space: normal;
    text-align: center;
}
.batch-photo-grid {
    display: grid;
    gap: 14px;
    min-width: 0;
}
.batch-photo-grid.cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.batch-photo-grid.cols-4 {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}
.batch-photo-grid.cols-4 .quick-photo-card {
    padding: 10px;
    gap: 8px;
}
.batch-photo-grid.cols-4 .quick-photo-img {
    height: 130px;
}
.batch-photo-grid.cols-4 .quick-grid-2,
.batch-photo-grid.cols-4 .quick-grid-3 {
    grid-template-columns: minmax(0, 1fr);
}
@media (max-width: 960px) {
    .batch-photo-grid.cols-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 480px) {
    .batch-photo-grid.cols-2,
    .batch-photo-grid.cols-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .batch-photo-grid .quick-photo-card {
        padding: 8px;
    }
    .batch-photo-grid .quick-photo-img {
        height: 115px;
    }
    .batch-photo-grid .quick-grid-2,
    .batch-photo-grid .quick-grid-3 {
        grid-template-columns: minmax(0, 1fr);
    }
}
.quick-grid-3.no-size {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.quick-grid-3.only-qty {
    grid-template-columns: minmax(0, 1fr);
}
.size-picker-block {
    display: grid;
    gap: 8px;
}
.size-picker-label {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #4f4563;
}
.size-picker-label small {
    color: #6a46d8;
    font-weight: 700;
    white-space: nowrap;
}
.size-qty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
    gap: 8px;
}
.size-qty-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 6px;
    padding: 5px 7px;
    border: 1px solid #d7c9f0;
    border-radius: 10px;
    background: #f9f6ff;
}
.size-qty-badge {
    font-weight: 800;
    font-size: 0.86rem;
    color: #4c2fa6;
    min-width: 24px;
    text-align: center;
    white-space: nowrap;
}
.size-qty-item input {
    min-height: 34px;
    padding: 4px 6px;
    text-align: center;
    font-size: 16px;
    border-radius: 8px;
}
.size-qty-rm {
    border: 0;
    background: transparent;
    color: #7c6f94;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 2px 4px;
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
    align-items: end;
    min-width: 0;
}
.quick-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    align-items: end;
    min-width: 0;
}
.quick-fields input,
.quick-fields textarea {
    font-size: 16px;
    min-width: 0;
    width: 100%;
}
@media (max-width: 520px) {
    .batch-toolbar {
        padding: 10px;
    }
    .batch-cols-toggle,
    .photo-select-btn {
        width: 100%;
    }
    .batch-cols-toggle .button {
        flex: 1 1 auto;
    }
    .photo-select-btn {
        white-space: normal;
        text-align: center;
    }
    .quick-grid-2,
    .quick-grid-3 {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
