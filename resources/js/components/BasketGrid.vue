<script setup lang="ts">
import BasketCard from './BasketCard.vue';
import type { BasketCardData } from '../baskets';
withDefaults(defineProps<{ baskets: BasketCardData[]; selectedId: number | null; busy: boolean; columns?: 2 | 4 | 6 }>(), { columns: 4 });
const emit = defineEmits<{ select: [number] }>();
</script>
<template>
    <div class="basket-grid-container">
    <section class="basket-grid" :class="`basket-grid-view-${columns}`" :data-requested-columns="columns" aria-label="Apartados guardados">
        <BasketCard
            v-for="basket in baskets"
            :key="basket.id"
            :basket="basket"
            :selected="basket.id === selectedId"
            :disabled="busy"
            @select="emit('select', $event)"
        />
    </section>
    </div>
</template>
