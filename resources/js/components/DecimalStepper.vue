<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { quantity } from '../inventory';
import { decimal, units } from '../sales';

const props = withDefaults(defineProps<{
    modelValue: string;
    label: string;
    positive?: boolean;
    readonly?: boolean;
    disabled?: boolean;
}>(), { positive: false });
const emit = defineEmits<{ 'update:modelValue': [string] }>();
const id = useId();
const editing = ref<string | null>(null);
const value = computed(() => editing.value ?? quantity(props.modelValue));
const current = computed(() => units(props.modelValue || '0'));
const canDecrease = computed(() => current.value !== null && (props.positive ? current.value > 10000n : current.value > 0n));
function input(event: Event) {
    editing.value = (event.target as HTMLInputElement).value.replace(',', '.');
    emit('update:modelValue', editing.value);
}
function change(direction: bigint) {
    if (props.readonly || props.disabled || current.value === null) return;
    if (direction < 0n && !canDecrease.value) return;
    const next = current.value + direction * 10000n;
    const result = decimal(next < 0n ? 0n : next);
    if (units(result) !== null) emit('update:modelValue', quantity(result));
}
</script>
<template>
    <span class="decimal-field">
        <label :for="id">{{ label }}</label>
        <span class="decimal-stepper">
            <button v-if="!readonly" type="button" :aria-label="`Disminuir ${label}`"
                :disabled="disabled || !canDecrease" @click="change(-1n)">−</button>
            <input :id="id" :value="value" inputmode="decimal" maxlength="20"
                :readonly="readonly" :disabled="disabled" @focus="editing = quantity(modelValue)" @blur="editing = null"
                @input="input" />
            <button v-if="!readonly" type="button" :aria-label="`Aumentar ${label}`"
                :disabled="disabled || current === null" @click="change(1n)">+</button>
        </span>
    </span>
</template>
<style scoped>
.decimal-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.decimal-stepper { display: flex; align-items: center; width: 100%; min-width: 0; border: 1px solid #dfe5db; border-radius: 8px; background: #fff; }
.decimal-stepper > button { flex: 0 0 44px; min-height: 46px; padding: 0; border: 0; border-radius: 7px; background: #f4f0fc; color: #5630d8; font-size: 22px; }
.decimal-stepper > input { flex: 1 1 0; width: 0; min-width: 0; border: 0; padding: 10px 2px; text-align: center; font-variant-numeric: tabular-nums; }
</style>
