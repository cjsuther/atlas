<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         :stroke-width="strokeWidth" v-html="paths" />
</template>

<script setup>
import { computed } from 'vue';
import { iconPaths } from './icons';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
    strokeWidth: { type: [Number, String], default: 2 },
});

const paths = computed(() => iconPaths[props.name] || '');
</script>
