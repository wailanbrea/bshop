<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { lockPageScroll } from '../lib/scrollLock';

const props = withDefaults(defineProps<{ open: boolean; title: string; busy?: boolean; returnFocus?: HTMLElement | null }>(), { busy: false });
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
const heading = ref<HTMLElement>();
const titleId = useId();
let previousFocus: HTMLElement | null = null;
let unlock: (() => void) | undefined;

function release() {
    unlock?.(); unlock = undefined;
    const target = previousFocus; previousFocus = null;
    // WebKit touch events can clear focus at the end of a click/close event.
    requestAnimationFrame(() => { if (target?.isConnected) target.focus({ preventScroll: true }); });
}
function cancel(event: Event) {
    event.preventDefault();
    if (!props.busy) emit('close');
}
onMounted(() => {
    watch(() => props.open, async open => {
        await nextTick();
        if (!dialog.value) return;
        if (open && !dialog.value.open) {
            previousFocus = props.returnFocus || document.activeElement as HTMLElement | null;
            unlock = lockPageScroll();
            dialog.value.showModal();
            // A touch user chooses when to open the keyboard by tapping a field.
            heading.value?.focus({ preventScroll: true });
        } else if (!open && dialog.value.open) {
            dialog.value.close(); release();
        }
    }, { immediate: true, flush: 'post' });
});
onUnmounted(() => { dialog.value?.close(); release(); });
</script>

<template>
    <Teleport to="body">
        <dialog ref="dialog" class="app-dialog" :aria-labelledby="titleId" :aria-busy="busy" @cancel="cancel">
            <header class="dialog-heading">
                <h2 :id="titleId" ref="heading" tabindex="-1">{{ title }}</h2>
                <button type="button" class="icon-button" aria-label="Cerrar formulario" :disabled="busy" @click="emit('close')">×</button>
            </header>
            <div class="dialog-content"><slot /></div>
            <footer v-if="$slots.footer" class="dialog-actions"><slot name="footer" /></footer>
        </dialog>
    </Teleport>
</template>
