<template>
    <svg xmlns="http://www.w3.org/2000/svg" :width="size" :height="size" viewBox="0 0 64 64" fill="none">
        <defs>
            <linearGradient :id="gid" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#1a2e4a" />
                <stop offset="100%" stop-color="#00b4d8" />
            </linearGradient>
        </defs>
        <ellipse cx="32" cy="32" rx="28" ry="10" :stroke="`url(#${gid})`" stroke-width="2" fill="none" />
        <ellipse cx="32" cy="32" rx="28" ry="10" :stroke="`url(#${gid})`" stroke-width="2" fill="none" transform="rotate(60 32 32)" />
        <ellipse cx="32" cy="32" rx="28" ry="10" :stroke="`url(#${gid})`" stroke-width="2" fill="none" transform="rotate(-60 32 32)" />
        <rect x="22" y="22" width="20" height="22" rx="2" fill="#1a2e4a" stroke="#00b4d8" stroke-width="1.4" />
        <path d="M26 28h12 M26 32h12 M26 36h8" stroke="#00b4d8" stroke-width="1.4" stroke-linecap="round" />
        <circle cx="32" cy="32" r="3" fill="#00b4d8" />
    </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    size: { type: Number, default: 36 },
});
const gid = computed(() => `atlas-logo-${props.size}-${Math.random().toString(36).slice(2,6)}`);
</script>
