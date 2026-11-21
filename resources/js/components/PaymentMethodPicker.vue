<script setup lang="ts">
import { watch } from 'vue';
import { enabledPaymentMethods, svgDataUri } from '../lib/paymentMethods';

const props = withDefaults(defineProps<{
    modelValue: string;
    label?: string;
    disabled?: boolean;
    compact?: boolean;
}>(), {
    label: 'Método de pago',
    disabled: false,
    compact: false,
});

const emit = defineEmits<{ 'update:modelValue': [string] }>();

watch(
    () => [props.modelValue, enabledPaymentMethods.value] as const,
    ([current, list]) => {
        if (!list.length) return;
        if (!current || !list.some((m) => m.code === current)) {
            emit('update:modelValue', list[0].code);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="pm-picker" :class="{ compact }" role="radiogroup" :aria-label="label">
        <span v-if="label" class="pm-picker-label">{{ label }}</span>
        <div class="pm-grid">
            <button
                v-for="item in enabledPaymentMethods"
                :key="item.code"
                type="button"
                role="radio"
                class="pm-chip"
                :class="{ active: modelValue === item.code }"
                :aria-checked="modelValue === item.code"
                :disabled="disabled"
                @click="emit('update:modelValue', item.code)"
            >
                <span class="pm-icon" :style="{ background: item.bg, color: item.fg }" aria-hidden="true">
                    <img
                        v-if="item.iconSvg && svgDataUri(item.iconSvg, item.fg)"
                        :src="svgDataUri(item.iconSvg, item.fg) || ''"
                        alt=""
                        class="pm-svg"
                    >
                    <svg v-else viewBox="0 0 24 24" class="pm-svg" focusable="false">
                        <path :d="item.svgPath" fill="currentColor" />
                    </svg>
                </span>
                <span class="pm-name">{{ item.label }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.pm-picker { display: grid; gap: 6px; min-width: 0; }
.pm-picker-label { font-size: 13px; font-weight: 600; color: var(--ink, #1f1b38); }
.pm-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }
.pm-picker.compact .pm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
.pm-chip {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 40px;
    padding: 6px 8px;
    border-radius: 10px;
    border: 1.5px solid #e1deef;
    background: #fff;
    color: #1f1b38;
    font-size: 12.5px;
    font-weight: 650;
    text-align: left;
    cursor: pointer;
    min-width: 0;
    transition: border-color .14s ease, background .14s ease, box-shadow .14s ease;
}
.pm-chip:hover:not(:disabled) { border-color: #a78bfa; background: #faf8ff; }
.pm-chip.active { border-color: #6435ed; background: #f3eeff; box-shadow: 0 0 0 2px #6435ed22; color: #3b1fa8; }
.pm-chip:disabled { opacity: 0.55; cursor: not-allowed; }
.pm-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 7px;
    flex-shrink: 0;
    padding: 4px;
    overflow: hidden;
}
.pm-svg { width: 100%; height: 100%; display: block; object-fit: contain; }
.pm-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 520px) {
    .pm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
