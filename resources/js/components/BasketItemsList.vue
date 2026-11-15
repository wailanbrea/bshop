<script setup lang="ts">
import type { BasketLine } from '../baskets';
import { amount } from '../catalog';
import DecimalStepper from './DecimalStepper.vue';
defineProps<{ lines: BasketLine[]; currency: string; editable: boolean; busy: boolean }>();
const emit = defineEmits<{ quantity: [number, string]; remove: [number] }>();
</script>
<template>
    <section class="basket-items" aria-label="Artículos del apartado">
        <article v-for="line in lines" :key="line.variant_id" class="basket-item">
            <img v-if="line.image" :src="line.image" :alt="line.name" loading="lazy" /><span
                v-else
                class="basket-item-placeholder"
                aria-hidden="true"
                >{{ line.name.slice(0, 1) }}</span
            >
            <div class="basket-item-info">
                <strong>{{ line.name }}</strong>
                <p>{{ line.attributes.map((a) => `${a.name}: ${a.value}`).join(' · ') || line.sku }}</p>
                <small>{{ amount(line.price, currency) }} por unidad · {{ line.sku }}</small>
            </div>
            <DecimalStepper class="basket-quantity"
                    :model-value="line.quantity"
                    positive
                    :readonly="!editable"
                    :disabled="busy"
                    :label="`Cantidad de ${line.sku}`"
                    @update:model-value="emit('quantity', line.variant_id, $event)" />
            <strong class="basket-item-price">{{ amount(line.price, currency) }}</strong
            ><button
                v-if="editable"
                type="button"
                class="icon-button"
                :disabled="busy"
                :aria-label="`Retirar ${line.sku}`"
                @click="emit('remove', line.variant_id)"
            >
                ×
            </button>
        </article>
        <p v-if="!lines.length" class="empty">
            Este apartado todavía no tiene artículos. Busca un producto para comenzar.
        </p>
    </section>
</template>
