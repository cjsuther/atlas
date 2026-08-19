<template>
    <div class="bar-chart">
        <div v-if="!rows?.length" class="empty-state" style="padding:16px;">Sin datos.</div>
        <div v-for="(r, i) in rows" :key="i" class="row">
            <div class="label" :title="r.label">{{ r.label || '—' }}</div>
            <div class="bar">
                <div class="fill" :style="{ width: pct(r.value) + '%' }" />
            </div>
            <div class="count">{{ formatValue(r.value) }}</div>
            <!-- Importe asociado a la fila: acompaña al conteo sin competir con él. -->
            <div v-if="r.extra !== undefined" class="extra" :class="{ negativo: r.extraNegativo }">
                {{ r.extra }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { fmtInt, fmtMoney } from '@/composables/useFormat';

const props = defineProps({
    rows: { type: Array, default: () => [] },   // [{label, value, extra?, extraNegativo?}]
    money: { type: Boolean, default: false },
});

const max = computed(() => Math.max(1, ...props.rows.map(r => Number(r.value) || 0)));
function pct(v) { return Math.max(2, (Number(v) || 0) / max.value * 100); }
function formatValue(v) { return props.money ? fmtMoney(v) : fmtInt(v); }
</script>

<style scoped>
.bar-chart .extra {
    min-width: 130px;
    text-align: right;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    color: var(--color-muted, #888);
    white-space: nowrap;
}
.bar-chart .extra.negativo { color: var(--color-danger, #c0392b); }
</style>
