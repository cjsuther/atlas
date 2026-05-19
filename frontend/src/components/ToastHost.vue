<template>
    <div class="toast-host">
        <div v-for="t in ui.toasts" :key="t.id" :class="['toast', t.type || 'info']">
            {{ t.msg }}
        </div>
    </div>
</template>

<script setup>
import { useUiStore } from '@/stores/ui';
const ui = useUiStore();
</script>
