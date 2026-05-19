<template>
    <div v-if="totalPages > 1 || total > 0" class="pager">
        <div class="info">
            {{ total }} registro{{ total === 1 ? '' : 's' }} · Pág. {{ page }} de {{ totalPages || 1 }}
        </div>
        <div class="pages">
            <button :disabled="page <= 1" @click="$emit('change', 1)">«</button>
            <button :disabled="page <= 1" @click="$emit('change', page - 1)">‹</button>
            <button v-for="n in visiblePages" :key="n" :class="{ active: n === page }" @click="$emit('change', n)">
                {{ n }}
            </button>
            <button :disabled="page >= totalPages" @click="$emit('change', page + 1)">›</button>
            <button :disabled="page >= totalPages" @click="$emit('change', totalPages)">»</button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    page: { type: Number, required: true },
    perPage: { type: Number, default: 20 },
    total: { type: Number, default: 0 },
});
defineEmits(['change']);

const totalPages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));

const visiblePages = computed(() => {
    const tp = totalPages.value;
    const p = props.page;
    const around = 2;
    const pages = new Set([1, tp]);
    for (let i = p - around; i <= p + around; i++) {
        if (i >= 1 && i <= tp) pages.add(i);
    }
    return [...pages].sort((a, b) => a - b);
});
</script>
