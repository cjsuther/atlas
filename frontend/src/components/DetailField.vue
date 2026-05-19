<template>
    <div class="item">
        <div class="label">{{ label }}</div>
        <div class="value">{{ display }}</div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number, null], default: null },
});

const display = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') return '—';
    return props.value;
});
</script>
