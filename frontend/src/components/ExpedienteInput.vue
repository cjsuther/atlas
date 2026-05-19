<template>
    <div class="expediente-input">
        <!-- EX (fijo) -->
        <span class="seg fixed">EX</span>
        <span class="sep">-</span>

        <!-- AÑO (4 dígitos) -->
        <input v-model="anio" type="text" inputmode="numeric" maxlength="4"
               class="seg input year" placeholder="AÑO" @input="onChange" @blur="onChange" />
        <span class="sep">-</span>

        <!-- NÚMERO (numérico) -->
        <input v-model="numero" type="text" inputmode="numeric"
               class="seg input num" placeholder="NÚMERO" @input="onChange" @blur="onChange" />
        <span class="sep">--</span>

        <!-- APN (fijo) -->
        <span class="seg fixed">APN</span>
        <span class="sep">-</span>

        <!-- REPARTICIÓN (texto libre con letras/números/#) -->
        <input v-model="reparticion" type="text"
               class="seg input rep" placeholder="REPARTICIÓN" @input="onChange" @blur="onChange" />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const DEFAULT_REPARTICION = 'GVTYEA#CNEA';

const anio = ref('');
const numero = ref('');
const reparticion = ref('');

/** Parse "EX-AÑO-NÚMERO--APN-REPARTICION" en sus partes. Si no matchea, deja vacío. */
function parse(value) {
    const m = (value || '').match(/^EX-(\d{4})-(\d+)--APN-(.+)$/);
    if (m) {
        anio.value = m[1];
        numero.value = m[2];
        reparticion.value = m[3];
    } else {
        anio.value = '';
        numero.value = '';
        reparticion.value = '';
    }
}

/** Aplica defaults y compone la string final, emitiendo update. */
function onChange() {
    // Sanitizar año a sólo dígitos
    anio.value = String(anio.value || '').replace(/\D/g, '').slice(0, 4);
    numero.value = String(numero.value || '').replace(/\D/g, '');

    const a = anio.value || String(new Date().getFullYear());
    const n = numero.value || '9999';
    const r = (reparticion.value || DEFAULT_REPARTICION).trim();

    const composed = `EX-${a}-${n}--APN-${r}`;
    if (composed !== props.modelValue) {
        emit('update:modelValue', composed);
    }
}

// Si el modelValue viene desde el padre (carga inicial / edición), parseamos
watch(() => props.modelValue, (v) => {
    if (!v) {
        // Aplicar defaults sin pisar lo que el usuario tipeó si ya hay datos
        if (!anio.value && !numero.value && !reparticion.value) {
            anio.value = String(new Date().getFullYear());
            numero.value = '9999';
            reparticion.value = DEFAULT_REPARTICION;
            onChange();
        }
        return;
    }
    parse(v);
}, { immediate: true });
</script>

<style scoped>
.expediente-input {
    display: flex;
    align-items: stretch;
    gap: 4px;
    flex-wrap: wrap;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 6px 10px;
    font-family: inherit;
}
.expediente-input:focus-within {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-soft);
}
.expediente-input .seg {
    display: inline-flex;
    align-items: center;
    font-size: 14px;
    color: var(--color-text);
}
.expediente-input .sep {
    display: inline-flex;
    align-items: center;
    color: var(--color-muted);
    font-weight: 600;
    user-select: none;
}
.expediente-input .fixed {
    font-weight: 700;
    color: var(--color-primary);
    letter-spacing: 0.5px;
}
.expediente-input .input {
    border: none;
    outline: none;
    background: transparent;
    padding: 2px 4px;
    font-family: inherit;
    font-size: 14px;
    color: var(--color-text);
    min-width: 0;
}
.expediente-input .input.year { width: 58px; text-align: center; }
.expediente-input .input.num  { width: 110px; }
.expediente-input .input.rep  { min-width: 140px; flex: 1; }

@media (max-width: 480px) {
    .expediente-input { gap: 2px; padding: 6px 8px; }
    .expediente-input .input.num  { width: 90px; }
    .expediente-input .input.rep  { flex: 1 1 100%; }
}
</style>
