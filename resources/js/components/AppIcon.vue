<script setup lang="ts">
withDefaults(defineProps<{ name: string; size?: number }>(), { size: 24 });
const paths: Record<string, string> = {
    search: 'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
    phone: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z',
    mail: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm18 2-10 7L2 6',
    pin: 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Zm-5 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
    edit: 'm16 3 5 5M4 20l4-1L21 6a2.1 2.1 0 0 0-3-3L5 16l-1 4Z',
    refresh: 'M20 7a8 8 0 0 0-13-3L3 8m0-5v5h5M4 17a8 8 0 0 0 13 3l4-4m0 5v-5h-5',
    note: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8M8 17h5',
    arrow: 'M5 12h14m-6-6 6 6-6 6',
    archive: 'M3 3h18v4H3V3Zm2 4v14h14V7M9 11h6',
    home: 'm3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9',
    cart: 'M2 3h3l3 12h11l3-9H6M9 20h.01M18 20h.01',
    document: 'M5 3h10l4 4v14H5V3Zm10 0v5h4M8 12h8M8 16h8',
    box: 'm12 3 9 5v9l-9 5-9-5V8l9-5Zm-9 5 9 5 9-5m-9 5v9M7.5 5.5l9 5',
    inventory: 'm12 3 10 5-10 5L2 8l10-5Zm-9 9 9 5 9-5M3 16l9 5 9-5',
    store: 'M4 10v11h16V10M3 3h18l1 6a3 3 0 0 1-5 2 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-5-2l1-6Zm6 18v-6h6v6',
    warehouse: 'm3 9 9-6 9 6v12H3V9Zm4 12v-9h10v9M7 16h10',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.9M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8m8 0a4 4 0 0 1 0 8',
    settings: 'm9 3-1 3-3 1-2 3 2 2v3l2 2 3-1 3 2 3-1 1-3 3-2v-3l-3-2-1-3h-4Zm3 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8',
    bag: 'M5 7h14l2 14H3L5 7Zm3 2V6a4 4 0 0 1 8 0v3',
};
</script>
<template><svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path :d="paths[name] || paths.box" /></svg></template>
